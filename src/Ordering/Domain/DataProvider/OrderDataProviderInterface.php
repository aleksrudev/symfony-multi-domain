<?php

declare(strict_types=1);

namespace App\Ordering\Domain\DataProvider;

use App\Ordering\Domain\Collection\OrderCollection;
use App\Ordering\Domain\Collection\OrderPaginatedCollection;
use App\Ordering\Domain\Entity\Order;
use App\Ordering\Domain\Exception\OrderNotFoundException;
use App\Ordering\Domain\ValueObject\BuyerId;
use App\Ordering\Domain\ValueObject\OrderId;

interface OrderDataProviderInterface
{
    /**
     * @throws OrderNotFoundException
     */
    public function findById(OrderId $id, bool $lock = false): Order;

    /**
     * @throws OrderNotFoundException
     */
    public function findByIdAndBuyerId(OrderId $id, BuyerId $buyerId): Order;

    /**
     * @throws OrderNotFoundException
     */
    public function findDraftByBuyerId(BuyerId $buyerId): Order;

    public function findByBuyerIdPaginated(BuyerId $buyerId, int $offset, int $limit): OrderPaginatedCollection;

    public function findPendingStockForReconciliation(int $limit, ?\DateTimeImmutable $now = null): OrderCollection;
}
