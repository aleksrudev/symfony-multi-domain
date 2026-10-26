<?php

declare(strict_types=1);

namespace Tests\Integration;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Tests\Fixtures\Messaging\OutboxTestCase;

final class TransactionalOutboxSchemaTest extends OutboxTestCase
{
    public function testOutboxHasIndependentTechnicalStorage(): void
    {
        self::assertNotNull($this->db->fetchOne("SELECT to_regclass('shared.outbox_messages')"));
        $columns = $this->db->fetchAllAssociative("SELECT column_name, data_type, is_nullable, datetime_precision, is_identity FROM information_schema.columns WHERE table_schema = 'shared' AND table_name = 'outbox_messages' ORDER BY ordinal_position");
        self::assertSame(['id', 'body', 'created_at', 'available_at', 'attempts', 'failed_at', 'last_error'], array_column($columns, 'column_name'));
        self::assertSame(['bigint', 'text', 'timestamp without time zone', 'timestamp without time zone', 'integer', 'timestamp without time zone', 'text'], array_column($columns, 'data_type'));
        self::assertSame(['NO', 'NO', 'NO', 'NO', 'NO', 'YES', 'YES'], array_column($columns, 'is_nullable'));
        self::assertSame('YES', $columns[0]['is_identity']);
        self::assertSame(6, $columns[2]['datetime_precision']);
        self::assertSame(6, $columns[3]['datetime_precision']);
        self::assertSame(6, $columns[5]['datetime_precision']);
        self::assertSame(0, $this->db->fetchOne("SELECT count(*) FROM information_schema.table_constraints WHERE table_schema = 'shared' AND table_name = 'outbox_messages' AND constraint_type = 'FOREIGN KEY'"));
        $index = $this->db->fetchOne("SELECT indexdef FROM pg_indexes WHERE schemaname = 'shared' AND indexname = 'idx_outbox_ready'");
        self::assertIsString($index);
        self::assertStringContainsString('(available_at, id)', $index);
        self::assertStringContainsString('WHERE (failed_at IS NULL)', $index);
    }

    public function testOrmDiffDoesNotDropTechnicalOutboxStorage(): void
    {
        $em = $this->kernel->getContainer()->get('doctrine')->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $sql = implode("\n", new SchemaTool($em)->getUpdateSchemaSql($em->getMetadataFactory()->getAllMetadata()));
        self::assertStringNotContainsString('DROP TABLE shared.outbox_messages', $sql);
        self::assertStringNotContainsString('DROP SEQUENCE shared.outbox_messages_id_seq', $sql);
        self::assertNotContains('shared.outbox_messages', $this->db->createSchemaManager()->listTableNames());
    }
}
