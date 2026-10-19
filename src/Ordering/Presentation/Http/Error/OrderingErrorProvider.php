<?php

declare(strict_types=1);

namespace App\Ordering\Presentation\Http\Error;

use App\Ordering\Domain\Exception\EmptyOrderItemsException;
use App\Ordering\Domain\Exception\IdempotencyConflictException;
use App\Ordering\Domain\Exception\InvalidOrderItemQuantityException;
use App\Ordering\Domain\Exception\OrderNotFoundException;
use App\Ordering\Domain\Exception\OrderNotModifiableException;
use App\Ordering\Domain\Exception\ProductNotAvailableException;
use App\Shared\Presentation\Http\Error\DomainErrorProviderInterface;

final class OrderingErrorProvider implements DomainErrorProviderInterface
{
    public static function getErrors(): array
    {
        return [
            OrderNotFoundException::class => [
                'error' => 'order_not_found',
                'status' => 404,
            ],
            OrderNotModifiableException::class => [
                'error' => 'order_not_in_draft_state',
                'status' => 409,
            ],
            IdempotencyConflictException::class => [
                'error' => 'idempotency_conflict',
                'status' => 409,
            ],
            InvalidOrderItemQuantityException::class => [
                'error' => 'invalid_order_item_quantity',
                'status' => 422,
            ],
            EmptyOrderItemsException::class => [
                'error' => 'empty_order_items',
                'status' => 422,
            ],
            ProductNotAvailableException::class => [
                'error' => 'product_not_available',
                'status' => 422,
            ],
        ];
    }
}
