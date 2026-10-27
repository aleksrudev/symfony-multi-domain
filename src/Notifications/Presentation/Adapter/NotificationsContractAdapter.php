<?php

declare(strict_types=1);

namespace App\Notifications\Presentation\Adapter;

use App\Notifications\Application\Command\EnqueueNotification\EnqueueNotificationCommand;
use App\Notifications\Application\Query\GetNotificationStatus\GetNotificationStatusQuery;
use App\Notifications\Application\Query\GetNotificationStatus\Result\NotificationStatusQueryResult;
use App\Notifications\Domain\DataProvider\NotificationDataProviderInterface;
use App\Notifications\Domain\Entity\Notification;
use App\Notifications\Presentation\Contracts\Dto\EnqueueNotificationInput;
use App\Notifications\Presentation\Contracts\Dto\NotificationStatusDto;
use App\Notifications\Presentation\Contracts\NotificationsContractInterface;
use App\Shared\Application\Bus\Command\CommandBusInterface;
use App\Shared\Application\Bus\Query\QueryBusInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(NotificationsContractInterface::class, public: true)]
final readonly class NotificationsContractAdapter implements NotificationsContractInterface
{
    public function __construct(
        private CommandBusInterface $commandBus,
        private QueryBusInterface $queryBus,
        private NotificationDataProviderInterface $dataProvider,
    ) {
    }

    public function enqueueNotification(EnqueueNotificationInput $input): void
    {
        $this->commandBus->dispatch(new EnqueueNotificationCommand(
            orderId: $input->orderId,
            type: $input->type,
            buyerId: $input->buyerId,
            payload: $input->payload,
        ));
    }

    public function getNotificationStatus(string $orderId, string $type): ?NotificationStatusDto
    {
        /** @var ?NotificationStatusQueryResult $result */
        $result = $this->queryBus->dispatch(new GetNotificationStatusQuery($orderId, $type));
        if (null === $result) {
            return null;
        }

        return new NotificationStatusDto(
            id: $result->id,
            orderId: $result->orderId,
            type: $result->type,
            buyerId: $result->buyerId,
            status: $result->status,
            attempts: $result->attempts,
            payload: $result->payload,
            errorMessage: $result->errorMessage,
            createdAt: $result->createdAt,
            updatedAt: $result->updatedAt,
            sentAt: $result->sentAt,
        );
    }

    public function getNotificationsForOrder(string $orderId): array
    {
        $notifications = $this->dataProvider->findByOrderId($orderId);

        $dtos = [];
        foreach ($notifications as $notification) {
            /* @var Notification $notification */
            $dtos[] = new NotificationStatusDto(
                id: $notification->id()->value,
                orderId: $notification->orderId(),
                type: $notification->type()->value,
                buyerId: $notification->buyerId(),
                status: $notification->status()->value,
                attempts: $notification->attempts(),
                payload: $notification->payload(),
                errorMessage: $notification->errorMessage(),
                createdAt: $notification->createdAt()->format(\DateTimeInterface::ATOM),
                updatedAt: $notification->updatedAt()->format(\DateTimeInterface::ATOM),
                sentAt: $notification->sentAt()?->format(\DateTimeInterface::ATOM),
            );
        }

        return $dtos;
    }
}
