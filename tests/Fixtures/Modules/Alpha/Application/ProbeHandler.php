<?php

declare(strict_types=1);

namespace Tests\Fixtures\Modules\Alpha\Application;

use App\Shared\Application\Bus\Outbox\OutboxBusInterface;
use Doctrine\DBAL\Connection;
use Tests\Fixtures\Modules\Alpha\Domain\ProbeFailure;

final class ProbeHandler
{
    public int $calls = 0;
    public int $outboxCalls = 0;
    public ?int $outboxValue = null;

    public function __construct(private readonly ProbeStoreInterface $store, private readonly OutboxBusInterface $outbox, private readonly Connection $connection)
    {
    }

    public function command(ProbeCommand $command): void
    {
        $this->store->append($command->value);
        if ($command->publish) {
            $this->outbox->publish(new ProbeOutbox($command->value));
        }
        if ($command->fail) {
            throw new ProbeFailure();
        }
        ++$this->calls;
    }

    public function query(ProbeQuery $query): int
    {
        return $this->calls;
    }

    public function inbox(ProbeInbox $message): void
    {
        $this->write($message->value, $message->fail);
    }

    public function outbox(ProbeOutbox $message): void
    {
        if (0 === $this->connection->executeStatement('INSERT INTO outbox_processed (value) VALUES (?) ON CONFLICT(value) DO NOTHING', [$message->value])) {
            return;
        }
        ++$this->outboxCalls;
        $this->outboxValue = $message->value;
    }

    private function write(int $value, bool $fail): void
    {
        $this->store->append($value);
        if ($fail) {
            throw new ProbeFailure();
        }
        ++$this->calls;
    }
}
