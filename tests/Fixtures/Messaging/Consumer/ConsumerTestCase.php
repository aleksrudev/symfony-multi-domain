<?php

declare(strict_types=1);

namespace Tests\Fixtures\Messaging\Consumer;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\IdempotentConsumerKernel;

abstract class ConsumerTestCase extends TestCase
{
    protected Connection $db;
    protected EntityManagerInterface $em;
    protected ManagerRegistry $registry;
    protected IdempotentConsumerKernel $kernel;

    protected function setUp(): void
    {
        if (!getenv('CONSUMER_TEST_DATABASE_URL')) {
            self::markTestSkipped('Dedicated Consumer PostgreSQL test database is not configured.');
        }
        $this->kernel = new IdempotentConsumerKernel('test', true);
        $this->kernel->boot();
        $this->registry = $this->kernel->getContainer()->get('doctrine');
        $manager = $this->registry->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $this->em = $manager;
        $this->db = $manager->getConnection();
        $guard = require dirname(__DIR__, 4).'/tools/test-database-guard.php';
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
