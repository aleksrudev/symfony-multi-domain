<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Persistence\Doctrine\DataProvider;

use App\Ordering\Domain\Collection\OrderCollection;
use App\Ordering\Domain\Collection\OrderPaginatedCollection;
use App\Ordering\Domain\DataProvider\OrderDataProviderInterface;
use App\Ordering\Domain\Entity\Order;
use App\Ordering\Domain\Enum\OrderStatus;
use App\Ordering\Domain\Exception\OrderNotFoundException;
use App\Ordering\Domain\ValueObject\BuyerId;
use App\Ordering\Domain\ValueObject\OrderId;
use Doctrine\ORM\EntityManagerInterface;

final readonly class OrderDataProvider implements OrderDataProviderInterface
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {
    }

    public function findById(OrderId $id): Order
    {
        $order = $this->em->find(Order::class, $id);
        if (!$order instanceof Order) {
            throw new OrderNotFoundException($id);
        }

        return $order;
    }

    public function findByIdAndBuyerId(OrderId $id, BuyerId $buyerId): Order
    {
        /** @var Order|null $order */
        $order = $this->em->createQueryBuilder()
            ->select('o')
            ->from(Order::class, 'o')
            ->where('o.id = :id AND o.buyerId = :buyerId')
            ->setParameter('id', $id)
            ->setParameter('buyerId', $buyerId->value)
            ->getQuery()
            ->getOneOrNullResult();

        if (null === $order) {
            throw new OrderNotFoundException($id);
        }

        return $order;
    }

    public function findDraftByBuyerId(BuyerId $buyerId): Order
    {
        /** @var Order|null $order */
        $order = $this->em->createQueryBuilder()
            ->select('o')
            ->from(Order::class, 'o')
            ->where('o.buyerId = :buyerId AND o.status = :status')
            ->setParameter('buyerId', $buyerId->value)
            ->setParameter('status', OrderStatus::DRAFT)
            ->getQuery()
            ->getOneOrNullResult();

        if (null === $order) {
            throw new OrderNotFoundException(sprintf('draft for buyer "%s"', $buyerId->value));
        }

        return $order;
    }

    public function findByBuyerIdPaginated(BuyerId $buyerId, int $offset, int $limit): OrderPaginatedCollection
    {
        $count = (int) $this->em->createQueryBuilder()
            ->select('COUNT(o.id)')
            ->from(Order::class, 'o')
            ->where('o.buyerId = :buyerId')
            ->setParameter('buyerId', $buyerId->value)
            ->getQuery()
            ->getSingleScalarResult();

        /** @var list<Order> $items */
        $items = $this->em->createQueryBuilder()
            ->select('o')
            ->from(Order::class, 'o')
            ->where('o.buyerId = :buyerId')
            ->setParameter('buyerId', $buyerId->value)
            ->orderBy('o.createdAt', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return new OrderPaginatedCollection(new OrderCollection($items), $count);
    }
}
