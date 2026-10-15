<?php

declare(strict_types=1);

namespace App\Catalog\Presentation\Contracts;

use App\Catalog\Presentation\Contracts\Dto\Offer;

interface CatalogContractInterface
{
    public function getOffer(string $productId): ?Offer;
}

