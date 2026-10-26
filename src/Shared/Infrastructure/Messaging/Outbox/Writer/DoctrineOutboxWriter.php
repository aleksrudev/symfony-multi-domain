<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messaging\Outbox\Writer;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(OutboxWriterInterface::class)]
final readonly class DoctrineOutboxWriter implements OutboxWriterInterface
{
    public function __construct(private Connection $connection, private ClockInterface $clock)
    {
    }

    public function append(object $message): void
    {
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('Outbox append requires an active transaction.');
        }
        $body = base64_encode(serialize($message));
        $now = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
        $this->connection->insert('shared.outbox_messages', ['body' => $body, 'created_at' => $now, 'available_at' => $now]);
    }
}
