<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Bus\Symfony;

use App\Shared\Application\Bus\Outbox\OutboxBusInterface;
use App\Shared\Application\Bus\Outbox\OutboxMessageInterface;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsAlias(OutboxBusInterface::class)]
final readonly class OutboxMessageBus implements OutboxBusInterface
{
    public function __construct(
        private MessageBusInterface $outboxBus,
        private Connection $connection,
    ) {
    }

    public function publish(OutboxMessageInterface $message): void
    {
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('Outbox messages must be published inside the default Doctrine transaction.');
        }

        $this->outboxBus->dispatch($message);
    }
}
