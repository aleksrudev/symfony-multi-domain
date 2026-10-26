<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Shared\Application\Bus\Command\CommandBusInterface;
use Tests\Fixtures\Messaging\Integration\MessagingTestCase;
use Tests\Fixtures\Modules\Alpha\Application\PublishProbeEventCommand;

final class PostgresIntegrationEventTest extends MessagingTestCase
{
    public function testSourceAndRelayChangesBecomeVisibleOnlyAfterTheirCommits(): void
    {
        $observer = $this->observer();
        try {
            $this->db->beginTransaction();
            $this->container->get(CommandBusInterface::class)->dispatch(new PublishProbeEventCommand(7));
            self::assertSame(0, $observer->fetchOne('SELECT COUNT(*) FROM alpha.txn_probe'));
            self::assertSame(0, $observer->fetchOne('SELECT COUNT(*) FROM shared.outbox_messages'));
            $this->db->commit();
            self::assertSame(1, $observer->fetchOne('SELECT COUNT(*) FROM alpha.txn_probe'));
            self::assertSame(1, $observer->fetchOne('SELECT COUNT(*) FROM shared.outbox_messages'));
            $this->relay();
            self::assertSame(0, $observer->fetchOne('SELECT COUNT(*) FROM shared.outbox_messages'));
            self::assertSame(1, $observer->fetchOne("SELECT COUNT(*) FROM public.messenger_messages WHERE queue_name = 'async'"));
        } finally {
            $observer->close();
        }
    }

    public function testFailureAfterAsyncInsertBeforeDeleteRollsBackBoth(): void
    {
        $event = $this->source();
        $this->db->executeStatement("CREATE OR REPLACE FUNCTION shared.reject_outbox_delete() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN RAISE EXCEPTION ''fixture delete failure''; END'");
        $this->db->executeStatement('CREATE TRIGGER reject_outbox_delete BEFORE DELETE ON shared.outbox_messages FOR EACH ROW EXECUTE FUNCTION shared.reject_outbox_delete()');
        $this->relay();
        self::assertSame(0, $this->countQueue('async'));
        $row = $this->db->fetchAssociative('SELECT * FROM shared.outbox_messages');
        self::assertSame(1, $row['attempts']);
        self::assertEquals($event, unserialize(base64_decode($row['body'], true)));
        self::assertNull($row['failed_at']);
        $this->db->executeStatement('DROP TRIGGER reject_outbox_delete ON shared.outbox_messages');
        $this->db->executeStatement('UPDATE shared.outbox_messages SET available_at = CURRENT_TIMESTAMP');
        $this->relay();
        self::assertSame(1, $this->countQueue('async'));
        self::assertSame(0, $this->db->fetchOne('SELECT COUNT(*) FROM shared.outbox_messages'));
    }
}
