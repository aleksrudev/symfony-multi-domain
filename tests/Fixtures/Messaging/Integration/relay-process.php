<?php

declare(strict_types=1);

use App\Shared\Infrastructure\Messaging\Outbox\Publisher\OutboxPublisherInterface;
use App\Shared\Infrastructure\Messaging\Outbox\Relay\OutboxRelay;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Tests\Fixtures\Messaging\Integration\ProcessBarrier;
use Tests\Fixtures\TransactionalMessagingKernel;

require dirname(__DIR__, 3).'/bootstrap.php';

$mode = $_SERVER['argv'][1] ?? null;
$path = $_SERVER['argv'][2] ?? null;
if (!in_array($mode, ['normal', 'after-send'], true) || ('after-send' === $mode && (!is_string($path) || '' === $path))) {
    throw new InvalidArgumentException('Relay mode and barrier required.');
}
$kernel = new TransactionalMessagingKernel('test', true);
try {
    $kernel->boot();
    $container = $kernel->getContainer()->get('test.service_container');
    $db = $container->get('doctrine')->getConnection();
    if (!$db instanceof Connection) {
        throw new LogicException('Default connection required.');
    }
    $guard = require dirname(__DIR__, 4).'/tools/test-database-guard.php';
    $guard('test', true, $db->getParams());
    $relay = $container->get(OutboxRelay::class);
    if ('after-send' === $mode) {
        $publisher = new class($container->get(OutboxPublisherInterface::class), new ProcessBarrier($path), (int) $db->fetchOne('SELECT pg_backend_pid()')) implements OutboxPublisherInterface {
            public function __construct(private OutboxPublisherInterface $publisher, private ProcessBarrier $barrier, private int $backendPid)
            {
            }

            public function publish(object $message): void
            {
                $this->publisher->publish($message);
                $this->barrier->signal('sent', (string) $this->backendPid);
                $this->barrier->wait('release');
            }
        };
        $relay = new OutboxRelay($db, $container->get(ClockInterface::class), $publisher);
    }
    echo 'relayed=', $relay->relayOne() ? '1' : '0', "\n";
} finally {
    $kernel->shutdown();
}
