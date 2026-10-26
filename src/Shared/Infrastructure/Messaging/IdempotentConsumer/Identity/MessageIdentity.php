<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messaging\IdempotentConsumer\Identity;

final readonly class MessageIdentity
{
    public function __construct(public string $namespace, public string $id)
    {
        foreach (['namespace' => $namespace, 'id' => $id] as $field => $value) {
            if (1 !== preg_match('/\A[^\x00]{1,190}\z/u', $value)) {
                throw new \InvalidArgumentException($field.' must contain 1–190 UTF-8 characters without NUL.');
            }
        }
    }
}
