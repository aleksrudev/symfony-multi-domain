<?php

declare(strict_types=1);

namespace Tests\Fixtures\Modules\Beta\Application\Message;

use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'inbox.bus')]
final class RecordProbeHandler
{
    public int $calls = 0;
    /** @var list<RecordProbeMessage> */
    public array $messages = [];

    public function __construct(private readonly Connection $connection)
    {
    }

    public function __invoke(RecordProbeMessage $message): void
    {
        ++$this->calls;
        $this->messages[] = $message;
        if (0 !== (int) $this->connection->fetchOne('SELECT COUNT(*) FROM probe_recorded WHERE event_id = ?', [$message->eventId])) {
            return;
        }
        $this->connection->insert('probe_recorded', ['event_id' => $message->eventId, 'value' => $message->value]);
    }
}
