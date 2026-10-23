<?php

declare(strict_types=1);

namespace App\Inventory\Presentation\Http\Request;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class UpdateStockOnHandRequest
{
    public function __construct(
        #[Assert\NotNull(message: 'onHand is required.')]
        #[Assert\PositiveOrZero(message: 'onHand must be greater than or equal to 0.')]
        public ?int $onHand = null,
    ) {
    }
}
