<?php

declare(strict_types=1);

namespace App\Catalog\Presentation\Http\Request;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class UpdateProductRequest
{
    public function __construct(
        #[Assert\Positive(message: 'Price must be a positive integer.')]
        public ?int $price = null,
        public ?bool $published = null,
    ) {
    }
}
