<?php

declare(strict_types=1);

namespace App\Catalog\Presentation\Http\Request;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class GetProductsRequest
{
    public function __construct(
        #[Assert\Positive(message: 'Page must be a positive integer.')]
        public int $page = 1,
        #[Assert\Range(min: 1, max: 50, notInRangeMessage: 'Limit must be between {{ min }} and {{ max }}.')]
        public int $limit = 10,
    ) {
    }
}
