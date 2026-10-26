<?php

declare(strict_types=1);

namespace Tests\Fixtures\Messaging\Consumer;

use App\Shared\Infrastructure\Messaging\IdempotentConsumer\Consumer\IdempotentConsumerInterface;

abstract class ConsumerStorageTestCase extends ConsumerTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->db->executeStatement('CREATE TABLE IF NOT EXISTS public.consumer_test_probe (id TEXT PRIMARY KEY, value INTEGER NOT NULL CHECK (value >= 0))');
        $this->db->executeStatement('DELETE FROM shared.processed_messages');
        $this->db->executeStatement('DELETE FROM public.consumer_test_probe');
        $this->db->insert('public.consumer_test_probe', ['id' => 'effect', 'value' => 0]);
    }

    protected function consumer(): IdempotentConsumerInterface
    {
        $consumer = $this->kernel->getContainer()->get('test.idempotent_consumer');
        self::assertInstanceOf(IdempotentConsumerInterface::class, $consumer);

        return $consumer;
    }
}
