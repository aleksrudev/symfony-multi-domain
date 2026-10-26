<?php

declare(strict_types=1);

use App\Shared\Infrastructure\Messaging\IdempotentConsumer\Consumer\IdempotentConsumerInterface;
use App\Shared\Infrastructure\Messaging\IdempotentConsumer\Identity\MessageIdentity;
use Doctrine\DBAL\Connection;
use Tests\Fixtures\IdempotentConsumerKernel;

require dirname(__DIR__, 3).'/bootstrap.php';

$barrier = $_SERVER['argv'][1] ?? null;
$mode = $_SERVER['argv'][2] ?? null;
if (!is_string($barrier) || !in_array($mode, ['commit', 'rollback', 'second'], true)) {
    throw new InvalidArgumentException('Barrier and transaction mode required.');
}
$kernel = new IdempotentConsumerKernel('test', true);
try {
    $kernel->boot();
    $container = $kernel->getContainer();
    $db = $container->get('doctrine')->getConnection();
    if (!$db instanceof Connection) {
        throw new LogicException('Default connection required.');
    }
    $guard = require dirname(__DIR__, 4).'/tools/test-database-guard.php';
    $guard('test', true, $db->getParams());
    $consumer = $container->get('test.idempotent_consumer');
    if (!$consumer instanceof IdempotentConsumerInterface) {
        throw new LogicException('Consumer unavailable.');
    }
    if ('second' === $mode) {
        file_put_contents($barrier.'.waiting', (string) $db->fetchOne('SELECT pg_backend_pid()'));
    }
    try {
        $consumer->consume('record-delivery', new MessageIdentity('provider.webhook', 'delivery-42'), static function () use ($db, $mode, $barrier): void {
            $db->executeStatement("UPDATE public.consumer_test_probe SET value = value + 1 WHERE id = 'effect'");
            $db->insert('public.consumer_test_probe', ['id' => 'second' === $mode ? 'second' : 'first', 'value' => 1]);
            if ('second' !== $mode) {
                touch($barrier.'.locked');
                $deadline = microtime(true) + 5;
                while (!is_file($barrier.'.release')) {
                    if (microtime(true) > $deadline) {
                        throw new RuntimeException('Consumer barrier timed out.');
                    }
                    usleep(10000);
                }
                if ('rollback' === $mode) {
                    throw new RuntimeException('Expected callback rollback.');
                }
            }
        });
    } catch (RuntimeException $error) {
        if ('rollback' !== $mode || 'Expected callback rollback.' !== $error->getMessage()) {
            throw $error;
        }
    }
} finally {
    $kernel->shutdown();
}
