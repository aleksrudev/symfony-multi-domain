<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Shared\Application\Bus\Command\CommandBusInterface;
use App\Shared\Infrastructure\Messaging\IdempotentConsumer\Consumer\IdempotentConsumerInterface;
use App\Shared\Infrastructure\Messaging\IdempotentConsumer\Identity\MessageIdentity;
use Tests\Fixtures\Messaging\Integration\MessagingTestCase;
use Tests\Fixtures\Modules\Beta\Application\Command\RecordProbe\RecordProbeCommand;
use Tests\Fixtures\Modules\Beta\Presentation\Contracts\Event\ProbeRecordedEvent;

final class IdempotentConsumerOutboxTest extends MessagingTestCase
{
    public function testReceiptBusinessChangeAndNewOutboxEventCommitTogether(): void
    {
        $this->react(false);
        self::assertSame(1, $this->db->fetchOne('SELECT COUNT(*) FROM shared.processed_messages'));
        self::assertSame(1, $this->db->fetchOne('SELECT COUNT(*) FROM beta.probe_recorded'));
        $event = unserialize(base64_decode($this->db->fetchOne('SELECT body FROM shared.outbox_messages'), true));
        self::assertInstanceOf(ProbeRecordedEvent::class, $event);
        self::assertSame('Alpha-event-1', $event->operationId);
        self::assertSame(7, $event->value);
        self::assertSame('2026-10-26T09:00:00.654321Z', $event->eventAt);
        $this->react(false);
        self::assertSame(1, $this->db->fetchOne('SELECT COUNT(*) FROM shared.outbox_messages'));
        $this->relay();
        self::assertNull($this->runOne()['failure'], 'Follow-up fact needs no subscribers.');
    }

    public function testFailureAfterNextPublicationRollsBackReceiptBusinessAndOutbox(): void
    {
        try {
            $this->react(true);
            self::fail('Expected reaction failure after publication.');
        } catch (\RuntimeException) {
            self::assertSame(0, $this->db->fetchOne('SELECT COUNT(*) FROM shared.processed_messages'));
            self::assertSame(0, $this->db->fetchOne('SELECT COUNT(*) FROM beta.probe_recorded'));
            self::assertSame(0, $this->db->fetchOne('SELECT COUNT(*) FROM shared.outbox_messages'));
        }
        $this->react(false);
        self::assertSame(1, $this->db->fetchOne('SELECT COUNT(*) FROM shared.processed_messages'));
    }

    private function react(bool $fail): void
    {
        $observer = $this->observer();
        try {
            $this->container->get(IdempotentConsumerInterface::class)->consume('beta.record-probe', new MessageIdentity('Alpha', 'event-1'), function () use ($fail, $observer): void {
                $this->container->get(CommandBusInterface::class)->dispatch(new RecordProbeCommand('Alpha-event-1', '2026-10-26T09:00:00.123456Z', 7, [], $fail));
                self::assertSame(0, $observer->fetchOne('SELECT COUNT(*) FROM shared.processed_messages'));
                self::assertSame(0, $observer->fetchOne('SELECT COUNT(*) FROM beta.probe_recorded'));
                self::assertSame(0, $observer->fetchOne('SELECT COUNT(*) FROM shared.outbox_messages'));
            });
        } finally {
            $observer->close();
        }
    }
}
