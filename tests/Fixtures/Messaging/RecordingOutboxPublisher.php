<?php

declare(strict_types=1);

namespace Tests\Fixtures\Messaging;

use App\Shared\Infrastructure\Messaging\Outbox\Publisher\OutboxPublisherInterface;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(OutboxPublisherInterface::class)]
final readonly class RecordingOutboxPublisher implements OutboxPublisherInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function publish(object $message): void
    {
        $this->connection->insert('public.outbox_test_deliveries', ['body' => base64_encode(serialize($message))]);
    }
}
