<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Persistence\Doctrine\Repository;

use App\Ordering\Domain\Entity\Order;
use App\Ordering\Domain\Repository\OrderRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class OrderRepository implements OrderRepositoryInterface
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {
    }

    public function save(Order $order): void
    {
        $this->em->persist($order);
    }

    public function remove(Order $order): void
    {
        $this->em->remove($order);
    }
}
