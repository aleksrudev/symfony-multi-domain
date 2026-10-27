<?php

declare(strict_types=1);

namespace App\Ordering\Domain\Entity;

use App\Ordering\Domain\Enum\IdempotencyOperation;
use App\Ordering\Domain\Exception\IdempotencyConflictException;
use App\Ordering\Domain\ValueObject\BuyerId;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Table;

#[Entity]
#[Table(name: 'idempotency_keys', schema: 'ordering')]
class IdempotencyKey
{
    #[Id]
    #[Column(name: '`key`', type: 'string', length: 128)]
    private string $key;

    #[Id]
    #[Column(name: 'buyer_id', type: 'string', length: 64)]
    private string $buyerId;

    #[Column(name: 'operation', type: 'string', length: 64, enumType: IdempotencyOperation::class)]
    private IdempotencyOperation $operation;

    #[Column(name: 'request_hash', type: 'string', length: 64)]
    private string $requestHash;

    #[Column(name: 'response_payload', type: 'text')]
    private string $responsePayload;

    #[Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        string $key,
        BuyerId $buyerId,
        IdempotencyOperation $operation,
        string $requestHash,
        string $responsePayload = '',
        ?\DateTimeImmutable $createdAt = null,
    ) {
        $this->key = $key;
        $this->buyerId = (string) $buyerId;
        $this->operation = $operation;
        $this->requestHash = $requestHash;
        $this->responsePayload = $responsePayload;
        $this->createdAt = $createdAt ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function key(): string
    {
        return $this->key;
    }

    public function buyerId(): BuyerId
    {
        return new BuyerId($this->buyerId);
    }

    public function operation(): IdempotencyOperation
    {
        return $this->operation;
    }

    public function requestHash(): string
    {
        return $this->requestHash;
    }

    public function responsePayload(): string
    {
        return $this->responsePayload;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * @throws IdempotencyConflictException
     */
    public function assertMatches(string $requestHash): void
    {
        if ($this->requestHash !== $requestHash) {
            throw new IdempotencyConflictException($this->key);
        }
    }
}
