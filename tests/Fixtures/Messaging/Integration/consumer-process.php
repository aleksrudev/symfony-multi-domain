<?php

declare(strict_types=1);

use App\Shared\Presentation\Contracts\Event\IntegrationEventInterface;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Tests\Fixtures\Messaging\Integration\ProcessBarrier;
use Tests\Fixtures\TransactionalMessagingKernel;

require dirname(__DIR__, 3).'/bootstrap.php';

$mode = $_SERVER['argv'][1] ?? null;
$path = $_SERVER['argv'][2] ?? null;
if (!in_array($mode, ['normal', 'before-ack'], true) || !is_string($path) || '' === $path) {
    throw new InvalidArgumentException('Worker mode and barrier path required.');
}
$barrier = new ProcessBarrier($path);
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
    $events = $container->get('event_dispatcher');
    $events->addListener(WorkerMessageReceivedEvent::class, static function () use ($db, $barrier): void {
        $barrier->signal('received', (string) $db->fetchOne('SELECT pg_backend_pid()'));
    });
    if ('before-ack' === $mode) {
        $events->addListener(WorkerMessageHandledEvent::class, static function (WorkerMessageHandledEvent $event) use ($barrier): void {
            $message = $event->getEnvelope()->getMessage();
            if (!$message instanceof IntegrationEventInterface) {
                throw new LogicException('Expected an integration event before ACK.');
            }
            $barrier->signal('committed', $message->eventId);
            $barrier->wait('release');
        }, 1000);
    }
    $app = new Application($kernel);
    $app->setAutoExit(false);
    $status = $app->run(new ArrayInput(['command' => 'messenger:consume', 'receivers' => ['async'], '--limit' => 1, '--time-limit' => 10, '--sleep' => 0.01, '--no-interaction' => true]));
} finally {
    $kernel->shutdown();
}
exit($status);
