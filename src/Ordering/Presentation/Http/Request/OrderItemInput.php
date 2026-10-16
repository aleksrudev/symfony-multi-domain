<?php

declare(strict_types=1);

namespace App\Ordering\Presentation\Http\Request;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class OrderItemInput
{
    public function __construct(
        #[Assert\NotBlank]
        public string $productId,
        #[Assert\Positive]
        public int $quantity,
    ) {
    }
}
