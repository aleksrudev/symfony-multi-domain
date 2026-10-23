<?php

declare(strict_types=1);

namespace Tests\Fixtures\Modules\Beta\Application\Message;

use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'inbox.bus')]
final class FailProbeHandler
{
    public int $calls = 0;
    public bool $fail = true;
    /** @var list<FailProbeMessage> */
    public array $messages = [];

    public function __construct(private readonly Connection $connection)
    {
    }

    public function __invoke(FailProbeMessage $message): void
    {
        ++$this->calls;
        $this->messages[] = $message;
        if (0 !== (int) $this->connection->fetchOne('SELECT COUNT(*) FROM probe_failed_effects WHERE event_id = ?', [$message->eventId])) {
            return;
        }
        $this->connection->insert('probe_failed_effects', ['event_id' => $message->eventId, 'value' => $message->value]);
        if ($this->fail) {
            throw new \RuntimeException('Fixture inbox handler is unavailable.');
        }
    }
}
