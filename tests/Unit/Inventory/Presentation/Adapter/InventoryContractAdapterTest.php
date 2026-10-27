<?php

declare(strict_types=1);

namespace Tests\Unit\Inventory\Presentation\Adapter;

use App\Inventory\Application\Query\GetReservation\GetReservationQuery;
use App\Inventory\Application\Query\GetReservation\Result\GetReservationQueryResult;
use App\Inventory\Domain\Exception\ReservationNotFoundException;
use App\Inventory\Presentation\Adapter\InventoryContractAdapter;
use App\Shared\Application\Bus\Query\QueryBusInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(InventoryContractAdapter::class)]
final class InventoryContractAdapterTest extends TestCase
{
    private QueryBusInterface&MockObject $queryBus;
    private InventoryContractAdapter $adapter;

    protected function setUp(): void
    {
        $this->queryBus = $this->createMock(QueryBusInterface::class);
        $this->adapter = new InventoryContractAdapter($this->queryBus);
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
