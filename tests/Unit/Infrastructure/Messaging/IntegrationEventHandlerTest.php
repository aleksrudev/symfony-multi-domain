<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Messaging;

use App\Shared\Infrastructure\Messaging\IntegrationEventDefinition;
use App\Shared\Infrastructure\Messaging\IntegrationEventEnvelope;
use App\Shared\Infrastructure\Messaging\IntegrationEventHandler;
use App\Shared\Infrastructure\Messaging\IntegrationEventMetadata;
use App\Shared\Infrastructure\Messaging\IntegrationEventRegistry;
use App\Shared\Infrastructure\Messaging\IntegrationEventSubscription;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Tests\Fixtures\Modules\Alpha\Infrastructure\Messaging\ProbeEventCodec;
use Tests\Fixtures\Modules\Alpha\Presentation\Contracts\Event\ProbePublishedEvent;
use Tests\Fixtures\Modules\Beta\Infrastructure\Integration\Alpha\RecordingSubscriber;

final class IntegrationEventHandlerTest extends TestCase
{
    public function testRestoresNestedPublicDtosAndPreservesMetadataOnRedelivery(): void
    {
        $subscriber = new RecordingSubscriber();
        $handler = $this->handler($subscriber);
        $message = new IntegrationEventEnvelope($this->metadata(), '{"value":7,"items":[{"code":"item-1","quantity":3}]}', 'beta.record');
        $handler($message);
        $handler($message);

        self::assertCount(2, $subscriber->events);
        self::assertSame(7, $subscriber->events[0]->value);
        self::assertSame('item-1', $subscriber->events[0]->items[0]->code);
        self::assertSame(3, $subscriber->events[0]->items[0]->quantity);
        self::assertEquals([$message->metadata, $message->metadata], $subscriber->metadata);
    }

    #[DataProvider('invalidDeliveries')]
    public function testInvalidDeliveryDoesNotRetryOrInvokeSubscriber(string $subscriberId, int $version, string $payload): void
    {
        $subscriber = new RecordingSubscriber();
        $handler = $this->handler($subscriber);
        try {
            $handler(new IntegrationEventEnvelope($this->metadata($version), $payload, $subscriberId));
            self::fail('Expected unrecoverable delivery failure.');
        } catch (UnrecoverableMessageHandlingException) {
            self::assertSame([], $subscriber->events);
        }
    }

    /** @return iterable<string, array{string, int, string}> */
    public static function invalidDeliveries(): iterable
    {
        yield 'removed subscriber' => ['beta.removed', 1, '{"value":7,"items":[]}'];
        yield 'unknown schema' => ['beta.record', 2, '{"value":7,"items":[]}'];
        yield 'broken JSON' => ['beta.record', 1, '{'];
        yield 'wrong field type' => ['beta.record', 1, '{"value":"wrong","items":[]}'];
    }

    public function testSubscriberExceptionKeepsItsRetrySemantics(): void
    {
        $failure = new \RuntimeException('Temporary subscriber failure.');
        $handler = $this->handler(static function () use ($failure): void { throw $failure; });

        $this->expectExceptionObject($failure);
        $handler(new IntegrationEventEnvelope($this->metadata(), '{"value":7,"items":[]}', 'beta.record'));
    }

    private function handler(callable $subscriber): IntegrationEventHandler
    {
        return new IntegrationEventHandler(new IntegrationEventRegistry([
            new IntegrationEventDefinition(ProbePublishedEvent::class, 'Alpha', 'alpha.published', 1, new ProbeEventCodec()),
        ], [new IntegrationEventSubscription('beta.record', ProbePublishedEvent::class, $subscriber)]));
    }

    private function metadata(int $version = 1): IntegrationEventMetadata
    {
        return new IntegrationEventMetadata('event-1', '2026-10-22T09:30:00.123456Z', 'Alpha', 'alpha.published', $version);
    }
}
