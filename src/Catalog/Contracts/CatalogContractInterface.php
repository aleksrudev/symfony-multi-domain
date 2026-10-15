<?php

declare(strict_types=1);

namespace App\Catalog\Contracts;

interface CatalogContractInterface
{
    public function getOffer(string $productId): ?Offer;
}

