<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messaging;

final readonly class IntegrationEventDefinition
{
    /** @param class-string $eventClass */
    public function __construct(
        public string $eventClass,
        public string $source,
        public string $type,
        public int $schemaVersion,
        public IntegrationEventCodecInterface $codec,
    ) {
        self::validate($eventClass, $source, $type, $schemaVersion);
    }

    /** @param class-string $eventClass */
    public static function validate(string $eventClass, string $source, string $type, int $schemaVersion): void
    {
        $reflection = new \ReflectionClass($eventClass);
        if (!str_contains($eventClass, '\\Presentation\\Contracts\\Event\\') || !$reflection->isFinal() || !$reflection->isReadOnly()) {
            throw new \LogicException(sprintf('Integration event "%s" must be a final readonly public Contracts/Event DTO.', $eventClass));
        }
        if ('' === trim($source) || '' === trim($type) || $schemaVersion < 1) {
            throw new \LogicException('Integration event source/type must be nonempty and schemaVersion must be positive.');
        }
    }
}
