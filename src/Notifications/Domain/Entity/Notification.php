<?php

declare(strict_types=1);

namespace App\Notifications\Domain\Entity;

use App\Notifications\Domain\Enum\NotificationStatus;
use App\Notifications\Domain\Enum\NotificationType;
use App\Notifications\Domain\ValueObject\NotificationId;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Table;
use Doctrine\ORM\Mapping\UniqueConstraint;

#[Entity]
#[Table(name: 'notifications', schema: 'notifications')]
#[UniqueConstraint(name: 'uniq_notifications_order_type', columns: ['order_id', 'type'])]
class Notification
{
    #[Id]
    #[Column(type: 'notification_id')]
    private NotificationId $id;

    #[Column(name: 'order_id', type: 'string', length: 36)]
    private string $orderId;

    #[Column(name: 'type', type: 'string', length: 50, enumType: NotificationType::class)]
    private NotificationType $type;

    #[Column(name: 'buyer_id', type: 'string', length: 100)]
    private string $buyerId;

    #[Column(name: 'status', type: 'string', length: 30, enumType: NotificationStatus::class)]
    private NotificationStatus $status;

    #[Column(name: 'attempts', type: 'integer')]
    private int $attempts;

    /**
     * @var array<string, mixed>
     */
    #[Column(name: 'payload', type: 'json')]
    private array $payload;

    #[Column(name: 'error_message', type: 'text', nullable: true)]
    private ?string $errorMessage = null;

    #[Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[Column(name: 'updated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    #[Column(name: 'sent_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $sentAt = null;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        NotificationId $id,
        string $orderId,
        NotificationType $type,
        string $buyerId,
        array $payload,
        NotificationStatus $status = NotificationStatus::PENDING,
        int $attempts = 0,
        ?string $errorMessage = null,
        ?\DateTimeImmutable $createdAt = null,
        ?\DateTimeImmutable $updatedAt = null,
        ?\DateTimeImmutable $sentAt = null,
    ) {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->id = $id;
        $this->orderId = $orderId;
        $this->type = $type;
        $this->buyerId = $buyerId;
        $this->payload = $payload;
        $this->status = $status;
        $this->attempts = $attempts;
        $this->errorMessage = $errorMessage;
        $this->createdAt = $createdAt ?? $now;
        $this->updatedAt = $updatedAt ?? $now;
        $this->sentAt = $sentAt;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function createPending(
        NotificationId $id,
        string $orderId,
        NotificationType $type,
        string $buyerId,
        array $payload,
    ): self {
        return new self(
            id: $id,
            orderId: $orderId,
            type: $type,
            buyerId: $buyerId,
            payload: $payload,
            status: NotificationStatus::PENDING,
            attempts: 0,
        );
    }

    public function markSent(\DateTimeImmutable $sentAt): void
    {
        ++$this->attempts;
        $this->status = NotificationStatus::SENT;
        $this->sentAt = $sentAt;
        $this->errorMessage = null;
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function recordFailure(string $errorMessage, int $maxAttempts = 3): void
    {
        ++$this->attempts;
        if ($this->attempts >= $maxAttempts) {
            $this->status = NotificationStatus::FAILED;
        }
        $this->errorMessage = $errorMessage;
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function id(): NotificationId
    {
        return $this->id;
    }

    public function orderId(): string
    {
        return $this->orderId;
    }

    public function type(): NotificationType
    {
        return $this->type;
    }

    public function buyerId(): string
    {
        return $this->buyerId;
    }

    public function status(): NotificationStatus
    {
        return $this->status;
    }

    public function attempts(): int
    {
        return $this->attempts;
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->payload;
    }

    public function errorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function sentAt(): ?\DateTimeImmutable
    {
        return $this->sentAt;
    }
}
