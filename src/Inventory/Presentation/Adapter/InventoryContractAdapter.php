<?php

declare(strict_types=1);

namespace App\Inventory\Presentation\Adapter;

use App\Inventory\Application\Command\RequestRelease\RequestReleaseCommand;
use App\Inventory\Application\Command\RequestReservation\RequestReservationCommand;
use App\Inventory\Application\Command\RequestReservation\RequestReservationItemInput;
use App\Inventory\Application\Query\GetReservation\GetReservationQuery;
use App\Inventory\Application\Query\GetReservation\GetReservationQueryResult;
use App\Inventory\Domain\Exception\ReservationNotFoundException;
use App\Inventory\Presentation\Contracts\Dto\ReleaseRequest;
use App\Inventory\Presentation\Contracts\Dto\ReservationRequest;
use App\Inventory\Presentation\Contracts\Dto\ReservationResult;
use App\Inventory\Presentation\Contracts\Dto\ReservationResultItem;
use App\Inventory\Presentation\Contracts\InventoryContractInterface;
use App\Shared\Application\Bus\Command\CommandBusInterface;
use App\Shared\Application\Bus\Query\QueryBusInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(InventoryContractInterface::class)]
final readonly class InventoryContractAdapter implements InventoryContractInterface
{
    public function __construct(
        private CommandBusInterface $commandBus,
        private QueryBusInterface $queryBus,
    ) {
    }

    public function requestReservation(ReservationRequest $request): void
    {
        $items = [];
        foreach ($request->items as $item) {
            $items[] = new RequestReservationItemInput(
                productId: $item->productId,
                quantity: $item->quantity,
            );
        }

        $this->commandBus->dispatch(new RequestReservationCommand(
            orderId: $request->orderId,
            items: $items,
        ));
    }

    public function requestRelease(ReleaseRequest $request): void
    {
        $this->commandBus->dispatch(new RequestReleaseCommand(
            orderId: $request->orderId,
        ));
    }

    public function getReservationResult(string $orderId): ReservationResult
    {
        try {
            /** @var GetReservationQueryResult $result */
            $result = $this->queryBus->dispatch(new GetReservationQuery($orderId));

            $items = [];
            foreach ($result->items as $item) {
                $items[] = new ReservationResultItem(
                    productId: $item['productId'],
                    quantity: $item['quantity'],
                );
            }

            return new ReservationResult(
                orderId: $result->orderId,
                status: $result->status,
                rejectionReason: $result->rejectionReason,
                items: $items,
            );
        } catch (ReservationNotFoundException) {
            return new ReservationResult(
                orderId: $orderId,
                status: 'not_found',
                rejectionReason: null,
                items: [],
            );
        }
    }
}
