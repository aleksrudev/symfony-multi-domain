<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messaging\IdempotentConsumer\Consumer;

use App\Shared\Infrastructure\Messaging\IdempotentConsumer\Identity\MessageIdentity;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(IdempotentConsumerInterface::class)]
final readonly class DoctrineIdempotentConsumer implements IdempotentConsumerInterface
{
    public function __construct(private Connection $connection, private ManagerRegistry $registry, private ClockInterface $clock)
    {
    }

    public function consume(string $consumerId, MessageIdentity $identity, callable $reaction): void
    {
        if (1 !== preg_match('/\A[^\x00]{1,190}\z/u', $consumerId)) {
            throw new \InvalidArgumentException('consumerId must contain 1–190 UTF-8 characters without NUL.');
        }
        if ($this->connection->isTransactionActive()) {
            throw new \LogicException('Idempotent Consumer requires its own transaction.');
        }
        $em = $this->registry->getManager();
        if (!$em instanceof EntityManagerInterface) {
            throw new \LogicException('Default ORM EntityManager is required.');
        }
        if (!$em->isOpen()) {
            throw new \LogicException('Idempotent Consumer requires an open EntityManager.');
        }
        if ($em->getConnection() !== $this->connection) {
            throw new \LogicException('Receipt and ORM effects must use the same connection.');
        }
        try {
            $this->connection->transactional(function () use ($consumerId, $identity, $reaction, $em): void {
                $inserted = $this->connection->executeStatement('INSERT INTO shared.processed_messages (consumer_id, namespace, message_id, processed_at) VALUES (?, ?, ?, ?) ON CONFLICT (consumer_id, namespace, message_id) DO NOTHING', [$consumerId, $identity->namespace, $identity->id, $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u')]);
                if (0 === $inserted) {
                    return;
                }
                $reaction();
                $em->flush();
            });
        } finally {
            if ($em->isOpen()) {
                $em->clear();
            }
        }
    }
}
