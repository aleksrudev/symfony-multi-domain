<?php

declare(strict_types=1);

namespace App\Ordering\Presentation\Http\Request;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class ReplaceOrderItemsRequest
{
    /**
     * @param list<OrderItemInput> $items
     */
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Count(min: 1)]
        #[Assert\Valid]
        public array $items = [],
    ) {
    }
}
