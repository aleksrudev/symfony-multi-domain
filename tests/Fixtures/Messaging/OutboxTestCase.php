<?php

declare(strict_types=1);

namespace Tests\Fixtures\Messaging;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\OutboxKernel;

abstract class OutboxTestCase extends TestCase
{
    protected Connection $db;
    protected OutboxKernel $kernel;

    protected function setUp(): void
    {
        if (!getenv('OUTBOX_TEST_DATABASE_URL')) {
            self::markTestSkipped('Dedicated PostgreSQL test database is not configured.');
        }
        $this->kernel = new OutboxKernel('test', true);
        $this->kernel->boot();
        $this->db = $this->kernel->getContainer()->get('doctrine')->getConnection();
        $guard = require dirname(__DIR__, 3).'/tools/test-database-guard.php';
        $guard('test', true, $this->db->getParams());
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            while ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            $this->db->close();
        }
        if (isset($this->kernel)) {
            $this->kernel->shutdown();
        }
    }

    protected function observer(): Connection
    {
        return DriverManager::getConnection($this->db->getParams());
    }
}
