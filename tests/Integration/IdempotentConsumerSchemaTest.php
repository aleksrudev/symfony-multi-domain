<?php

declare(strict_types=1);

namespace Tests\Integration;

use Doctrine\ORM\Tools\SchemaTool;
use Tests\Fixtures\Messaging\Consumer\ConsumerTestCase;

final class IdempotentConsumerSchemaTest extends ConsumerTestCase
{
    public function testReceiptStorageIsIndependentAndHasNoPayloadOrForeignKeys(): void
    {
        self::assertNotNull($this->db->fetchOne("SELECT to_regclass('shared.processed_messages')"));
        self::assertNull($this->db->fetchOne("SELECT to_regclass('shared.outbox_messages')"));
        self::assertNull($this->db->fetchOne("SELECT to_regclass('public.messenger_messages')"));
        $columns = $this->db->fetchAllAssociative("SELECT column_name, is_nullable, character_maximum_length, datetime_precision FROM information_schema.columns WHERE table_schema = 'shared' AND table_name = 'processed_messages' ORDER BY ordinal_position");
        self::assertSame(['consumer_id', 'namespace', 'message_id', 'processed_at'], array_column($columns, 'column_name'));
        self::assertSame(['NO', 'NO', 'NO', 'NO'], array_column($columns, 'is_nullable'));
        self::assertSame([190, 190, 190, null], array_column($columns, 'character_maximum_length'));
        self::assertSame(6, $columns[3]['datetime_precision']);
        self::assertSame(['consumer_id', 'namespace', 'message_id'], $this->db->fetchFirstColumn("SELECT column_name FROM information_schema.key_column_usage WHERE table_schema = 'shared' AND table_name = 'processed_messages' AND constraint_name = 'processed_messages_pkey' ORDER BY ordinal_position"));
        self::assertSame(0, $this->db->fetchOne("SELECT count(*) FROM information_schema.table_constraints WHERE table_schema = 'shared' AND table_name = 'processed_messages' AND constraint_type = 'FOREIGN KEY'"));
    }

    public function testOrmDiffDoesNotDropReceipts(): void
    {
        $sql = implode("\n", new SchemaTool($this->em)->getUpdateSchemaSql($this->em->getMetadataFactory()->getAllMetadata()));
        self::assertStringNotContainsString('DROP TABLE shared.processed_messages', $sql);
        self::assertNotContains('shared.processed_messages', $this->db->createSchemaManager()->listTableNames());
    }
}
