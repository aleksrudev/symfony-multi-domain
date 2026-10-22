<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Messaging;

use App\Shared\Application\Bus\Outbox\OutboxBusInterface;
use App\Shared\Application\Bus\Outbox\OutboxMessageInterface;
use App\Shared\Infrastructure\Messaging\IntegrationEventCodecInterface;
use App\Shared\Infrastructure\Messaging\IntegrationEventDefinition;
use App\Shared\Infrastructure\Messaging\IntegrationEventEnvelope;
use App\Shared\Infrastructure\Messaging\IntegrationEventMetadata;
use App\Shared\Infrastructure\Messaging\IntegrationEventPublisher;
use App\Shared\Infrastructure\Messaging\IntegrationEventRegistry;
use App\Shared\Infrastructure\Messaging\IntegrationEventSubscription;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\Modules\Alpha\Infrastructure\Messaging\ProbeEventCodec;
use Tests\Fixtures\Modules\Alpha\Presentation\Contracts\Dto\ProbeItem;
use Tests\Fixtures\Modules\Alpha\Presentation\Contracts\Event\ProbePublishedEvent;

final class IntegrationEventPublisherTest extends TestCase
{
    public function testPublishingOutsideTransactionIsRejectedEvenWithoutSubscribers(): void
    {
        $db = $this->connection();
        $bus = $this->createStub(OutboxBusInterface::class);
        $publisher = new IntegrationEventPublisher($this->registry([]), $bus, $db);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('transaction');
        $publisher->publish(new ProbePublishedEvent(7, []), new \DateTimeImmutable());
    }

    public function testEachSubscriberGetsAnIndependentSnapshotWithTheSameIdentity(): void
    {
        $db = $this->connection();
        $messages = [];
        $bus = $this->createMock(OutboxBusInterface::class);
        $bus->expects(self::exactly(2))->method('publish')->willReturnCallback(static function (OutboxMessageInterface $message) use (&$messages): void {
            $messages[] = $message;
        });
        $publisher = new IntegrationEventPublisher($this->registry(['beta.first', 'beta.second']), $bus, $db);
        $db->beginTransaction();
        $metadata = $publisher->publish(
            new ProbePublishedEvent(7, [new ProbeItem('item-1', 3)]),
            new \DateTimeImmutable('2026-10-22T12:30:00.123456+03:00'),
        );
        $db->rollBack();

        self::assertSame('2026-10-22T09:30:00.123456Z', $metadata->eventAt);
        self::assertNotSame('', $metadata->eventId);
        self::assertSame('Alpha', $metadata->source);
        self::assertSame('alpha.published', $metadata->type);
        self::assertSame(1, $metadata->schemaVersion);
        foreach ($messages as $message) {
            self::assertInstanceOf(IntegrationEventEnvelope::class, $message);
            self::assertEquals($metadata, $message->metadata);
            self::assertSame(['value' => 7, 'items' => [['code' => 'item-1', 'quantity' => 3]]], json_decode($message->payload, true, flags: JSON_THROW_ON_ERROR));
        }
        self::assertSame(['beta.first', 'beta.second'], array_column($messages, 'subscriberId'));
        self::assertNotSame($messages[0], $messages[1]);
    }

    public function testUnknownPublicEventCannotBePublished(): void
    {
        $db = $this->connection();
        $publisher = new IntegrationEventPublisher(new IntegrationEventRegistry([], []), $this->createStub(OutboxBusInterface::class), $db);
        $db->beginTransaction();

        $this->expectException(\LogicException::class);
        $publisher->publish(new ProbePublishedEvent(7, []), new \DateTimeImmutable());
    }

    public function testCodecMustProduceAJsonObjectBeforeAnyMessageIsQueued(): void
    {
        $codec = new class implements IntegrationEventCodecInterface {
            public function encode(object $event): string
            {
                return '[1,2,3]';
            }

            public function decode(string $payload): object
            {
                return new ProbePublishedEvent(7, []);
            }
        };
        $db = $this->connection();
        $bus = $this->createMock(OutboxBusInterface::class);
        $bus->expects(self::never())->method('publish');
        $registry = new IntegrationEventRegistry([
            new IntegrationEventDefinition(ProbePublishedEvent::class, 'Alpha', 'alpha.published', 1, $codec),
        ], []);
        $publisher = new IntegrationEventPublisher($registry, $bus, $db);
        $db->beginTransaction();

        $this->expectException(\UnexpectedValueException::class);
        $publisher->publish(new ProbePublishedEvent(7, []), new \DateTimeImmutable());
    }

    /** @param list<string> $subscribers */
    private function registry(array $subscribers): IntegrationEventRegistry
    {
        return new IntegrationEventRegistry([
            new IntegrationEventDefinition(ProbePublishedEvent::class, 'Alpha', 'alpha.published', 1, new ProbeEventCodec()),
        ], array_map(static fn (string $id): IntegrationEventSubscription => new IntegrationEventSubscription(
            $id,
            ProbePublishedEvent::class,
            static function (ProbePublishedEvent $event, IntegrationEventMetadata $metadata): void {},
        ), $subscribers));
    }

    private function connection(): Connection
    {
        return DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    }
}
