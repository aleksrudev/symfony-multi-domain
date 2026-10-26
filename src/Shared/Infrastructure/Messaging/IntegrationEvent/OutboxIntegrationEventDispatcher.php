<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messaging\IntegrationEvent;

use App\Shared\Infrastructure\Messaging\Outbox\Writer\OutboxWriterInterface;
use App\Shared\Presentation\Contracts\Event\IntegrationEventInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(IntegrationEventDispatcherInterface::class)]
final readonly class OutboxIntegrationEventDispatcher implements IntegrationEventDispatcherInterface
{
    public function __construct(private OutboxWriterInterface $writer)
    {
    }

    public function dispatch(IntegrationEventInterface $event): void
    {
        foreach (['eventId' => $event->eventId, 'source' => $event->source, 'type' => $event->type] as $field => $value) {
            if (1 !== preg_match('/\A[^\x00]{1,190}\z/u', $value)) {
                throw new \InvalidArgumentException($field.' must contain 1–190 UTF-8 characters without NUL.');
            }
        }
        if ($event->schemaVersion < 1) {
            throw new \InvalidArgumentException('schemaVersion must be positive.');
        }
        if (1 !== preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}\.[0-9]{6}Z\z/', $event->eventAt)) {
            throw new \InvalidArgumentException('eventAt must be a UTC timestamp with six fractional digits and Z.');
        }
        $format = 'Y-m-d\TH:i:s.u\Z';
        $date = \DateTimeImmutable::createFromFormat('!'.$format, $event->eventAt, new \DateTimeZone('UTC'));
        if (false === $date || $date->format($format) !== $event->eventAt) {
            throw new \InvalidArgumentException('eventAt must be a UTC timestamp with six fractional digits and Z.');
        }
        $this->writer->append($event);
    }
}
