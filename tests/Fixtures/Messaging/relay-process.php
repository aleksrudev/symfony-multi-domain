<?php

declare(strict_types=1);

use App\Shared\Infrastructure\Messaging\Outbox\Console\RelayOutboxCommand;
use App\Shared\Infrastructure\Messaging\Outbox\Publisher\OutboxPublisherInterface;
use App\Shared\Infrastructure\Messaging\Outbox\Relay\OutboxRelay;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Tests\Fixtures\Messaging\RecordingOutboxPublisher;

require dirname(__DIR__, 2).'/bootstrap.php';

$params = new DsnParser(['postgresql' => 'pdo_pgsql'])->parse(getenv('OUTBOX_TEST_DATABASE_URL'));
$guard = require dirname(__DIR__, 3).'/tools/test-database-guard.php';
$guard('test', true, $params);
$db = DriverManager::getConnection($params);
$barrier = $_SERVER['argv'][1] ?? null;
if (!is_string($barrier)) {
    throw new InvalidArgumentException('Barrier path is required.');
}
$publisher = new class(new RecordingOutboxPublisher($db), $barrier) implements OutboxPublisherInterface {
    public function __construct(private RecordingOutboxPublisher $publisher, private string $barrier)
    {
    }

    public function publish(object $message): void
    {
        $this->publisher->publish($message);
        touch($this->barrier);
        $deadline = microtime(true) + 5;
        while (!is_file($this->barrier.'.release')) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Relay barrier timed out.');
            }
            usleep(10000);
        }
    }
};
try {
    if ('command' === ($_SERVER['argv'][2] ?? '')) {
        $app = new Application();
        $app->setAutoExit(false);
        $app->addCommand(new RelayOutboxCommand(new OutboxRelay($db, new NativeClock(), $publisher), new NativeClock()));
        exit($app->run(new ArrayInput(['command' => 'app:outbox:relay', '--limit' => '2', '--time-limit' => '5'])));
    }
    exit(new OutboxRelay($db, new NativeClock(), $publisher)->relayOne() ? 0 : 1);
} finally {
    $db->close();
}
