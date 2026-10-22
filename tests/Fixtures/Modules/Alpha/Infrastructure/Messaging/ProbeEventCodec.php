<?php

declare(strict_types=1);

namespace Tests\Fixtures\Modules\Alpha\Infrastructure\Messaging;

use App\Shared\Infrastructure\Messaging\Attribute\AsIntegrationEvent;
use App\Shared\Infrastructure\Messaging\IntegrationEventCodecInterface;
use Tests\Fixtures\Modules\Alpha\Presentation\Contracts\Dto\ProbeItem;
use Tests\Fixtures\Modules\Alpha\Presentation\Contracts\Event\ProbePublishedEvent;

#[AsIntegrationEvent(event: ProbePublishedEvent::class, source: 'Alpha', type: 'alpha.published', schemaVersion: 1)]
final readonly class ProbeEventCodec implements IntegrationEventCodecInterface
{
    public function encode(object $event): string
    {
        if (!$event instanceof ProbePublishedEvent) {
            throw new \InvalidArgumentException('Expected ProbePublishedEvent.');
        }

        return json_encode([
            'value' => $event->value,
            'items' => array_map(static fn (ProbeItem $item): array => ['code' => $item->code, 'quantity' => $item->quantity], $event->items),
        ], JSON_THROW_ON_ERROR);
    }

    public function decode(string $payload): object
    {
        $data = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($data) || !isset($data['value'], $data['items']) || !is_int($data['value']) || !is_array($data['items'])) {
            throw new \UnexpectedValueException('Invalid probe event payload.');
        }
        $items = [];
        foreach ($data['items'] as $item) {
            if (!is_array($item) || !isset($item['code'], $item['quantity']) || !is_string($item['code']) || !is_int($item['quantity'])) {
                throw new \UnexpectedValueException('Invalid probe item payload.');
            }
            $items[] = new ProbeItem($item['code'], $item['quantity']);
        }

        return new ProbePublishedEvent($data['value'], $items);
    }
}
