<?php

declare(strict_types=1);

namespace App\Ordering\Domain\Repository;

use App\Ordering\Domain\Entity\Order;

interface OrderRepositoryInterface
{
    public function save(Order $order): void;

    public function remove(Order $order): void;
}
