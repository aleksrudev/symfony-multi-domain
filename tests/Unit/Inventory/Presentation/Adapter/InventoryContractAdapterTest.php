<?php

declare(strict_types=1);

namespace Tests\Unit\Inventory\Presentation\Adapter;

use App\Inventory\Application\Command\ReleaseStock\ReleaseStockCommand;
use App\Inventory\Application\Command\ReserveStock\ReserveStockCommand;
use App\Inventory\Application\Query\GetReservation\GetReservationQuery;
use App\Inventory\Application\Query\GetReservation\GetReservationQueryResult;
use App\Inventory\Domain\Exception\ReservationNotFoundException;
use App\Inventory\Presentation\Adapter\InventoryContractAdapter;
use App\Inventory\Presentation\Contracts\Dto\ReleaseRequest;
use App\Inventory\Presentation\Contracts\Dto\ReservationItemRequest;
use App\Inventory\Presentation\Contracts\Dto\ReservationRequest;
use App\Shared\Application\Bus\Command\CommandBusInterface;
use App\Shared\Application\Bus\Query\QueryBusInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(InventoryContractAdapter::class)]
final class InventoryContractAdapterTest extends TestCase
{
    private CommandBusInterface $commandBus;
    private QueryBusInterface $queryBus;
    private InventoryContractAdapter $adapter;

    protected function setUp(): void
    {
        $this->commandBus = $this->createMock(CommandBusInterface::class);
        $this->queryBus = $this->createMock(QueryBusInterface::class);
        $this->adapter = new InventoryContractAdapter($this->commandBus, $this->queryBus);
    }

    public function testRequestReservationDispatchesCommand(): void
    {
        $orderId = '00000000-0000-4000-8000-000000000001';
        $request = new ReservationRequest(
            orderId: $orderId,
            items: [
                new ReservationItemRequest('prod-1', 2),
                new ReservationItemRequest('prod-2', 4),
            ],
        );

        $this->commandBus
            ->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(static function (ReserveStockCommand $cmd) use ($orderId): bool {
                return $cmd->orderId === $orderId
                    && 2 === count($cmd->items)
                    && 'prod-1' === $cmd->items[0]->productId
                    && 2 === $cmd->items[0]->quantity;
            }));

        $this->adapter->requestReservation($request);
    }

    public function testRequestReleaseDispatchesCommand(): void
    {
        $orderId = '00000000-0000-4000-8000-000000000001';
        $request = new ReleaseRequest(orderId: $orderId);

        $this->commandBus
            ->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(static function (ReleaseStockCommand $cmd) use ($orderId): bool {
                return $cmd->orderId === $orderId;
            }));

        $this->adapter->requestRelease($request);
    }

    public function testGetReservationResultSuccess(): void
    {
        $orderId = '00000000-0000-4000-8000-000000000001';
        $queryResult = new GetReservationQueryResult(
            orderId: $orderId,
            status: 'reserved',
            rejectionReason: null,
            items: [
                ['productId' => 'prod-1', 'quantity' => 2],
            ],
            updatedAt: '2026-10-23T10:00:00+00:00',
        );

        $this->queryBus
            ->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(static fn (GetReservationQuery $q): bool => $q->orderId === $orderId))
            ->willReturn($queryResult);

        $result = $this->adapter->getReservationResult($orderId);

        self::assertSame($orderId, $result->orderId);
        self::assertSame('reserved', $result->status);
        self::assertNull($result->rejectionReason);
        self::assertCount(1, $result->items);
        self::assertSame('prod-1', $result->items[0]->productId);
        self::assertSame(2, $result->items[0]->quantity);
    }

    public function testGetReservationResultReturnsNotFoundWhenExceptionThrown(): void
    {
        $orderId = '00000000-0000-4000-8000-000000000001';

        $this->queryBus
            ->expects(self::once())
            ->method('dispatch')
            ->willThrowException(new ReservationNotFoundException($orderId));

        $result = $this->adapter->getReservationResult($orderId);

        self::assertSame($orderId, $result->orderId);
        self::assertSame('not_found', $result->status);
        self::assertNull($result->rejectionReason);
        self::assertCount(0, $result->items);
    }
}
