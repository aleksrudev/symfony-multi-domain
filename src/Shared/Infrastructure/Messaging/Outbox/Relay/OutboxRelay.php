<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messaging\Outbox\Relay;

use App\Shared\Infrastructure\Messaging\Outbox\Publisher\OutboxPublisherInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ConnectionException;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final readonly class OutboxRelay
{
    public function __construct(
        private Connection $connection,
        private ClockInterface $clock,
        private OutboxPublisherInterface $publisher,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function relayOne(): bool
    {
        if ($this->connection->isTransactionActive()) {
            throw new \LogicException('Outbox relay requires its own transaction.');
        }

        return $this->connection->transactional(function (): bool {
            $row = $this->connection->fetchAssociative('SELECT id, body, attempts FROM shared.outbox_messages WHERE failed_at IS NULL AND available_at <= ? ORDER BY available_at, id LIMIT 1 FOR UPDATE SKIP LOCKED', [$this->timestamp()]);
            if (false === $row) {
                return false;
            }
            try {
                $this->connection->transactional(function () use ($row): void {
                    $this->publisher->publish($this->restore($row['body']));
                    $this->connection->delete('shared.outbox_messages', ['id' => $row['id']]);
                });
            } catch (ConnectionException $error) {
                throw $error;
            } catch (\Throwable $error) {
                $attempt = (int) $row['attempts'] + 1;
                $now = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
                $delay = [1 => 1, 2 => 3, 3 => 9][$attempt] ?? 0;
                $this->connection->update('shared.outbox_messages', [
                    'attempts' => $attempt,
                    'available_at' => $now->modify('+'.$delay.' seconds')->format('Y-m-d H:i:s.u'),
                    'failed_at' => $attempt >= 4 ? $now->format('Y-m-d H:i:s.u') : null,
                    'last_error' => $error::class,
                ], ['id' => $row['id']]);
                $this->logger->warning('Outbox delivery failed.', ['outbox_id' => $row['id'], 'attempt' => $attempt, 'error_class' => $error::class]);
            }

            return true;
        });
    }

    private function timestamp(): string
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private function restore(string $body): object
    {
        $serialized = base64_decode($body, true);
        if (false === $serialized || base64_encode($serialized) !== $body) {
            throw new \UnexpectedValueException('Invalid Outbox snapshot encoding.');
        }
        set_error_handler(static function (): never {
            throw new \UnexpectedValueException('Invalid Outbox snapshot.');
        });
        try {
            $message = unserialize($serialized);
        } finally {
            restore_error_handler();
        }
        if (!is_object($message)) {
            throw new \UnexpectedValueException('Outbox snapshot must contain an object.');
        }
        $objects = new \SplObjectStorage();
        $references = [];
        $this->assertComplete($message, $objects, $references);

        return $message;
    }

    /**
     * @param \SplObjectStorage<object, null> $objects
     * @param array<string, true>             $references
     */
    private function assertComplete(mixed &$value, \SplObjectStorage $objects, array &$references): void
    {
        if ($value instanceof \__PHP_Incomplete_Class) {
            throw new \UnexpectedValueException('Outbox snapshot requires an unavailable class.');
        }
        if (is_object($value)) {
            if ($objects->offsetExists($value)) {
                return;
            }
            $objects[$value] = null;
            $properties = get_mangled_object_vars($value);
            if (method_exists($value, '__serialize') && new \ReflectionMethod($value, '__serialize')->isInternal()) {
                // Native containers keep serialized values outside their PHP properties.
                $properties[] = $value->__serialize();
            }
            $this->assertComplete($properties, $objects, $references);
        } elseif (is_array($value)) {
            foreach (array_keys($value) as $key) {
                $reference = \ReflectionReference::fromArrayElement($value, $key);
                if (null !== $reference) {
                    $id = $reference->getId();
                    if (isset($references[$id])) {
                        continue;
                    }
                    $references[$id] = true;
                }
                $this->assertComplete($value[$key], $objects, $references);
            }
        }
    }
}
