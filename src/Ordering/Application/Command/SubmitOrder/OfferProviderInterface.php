<?php

declare(strict_types=1);

namespace App\Ordering\Application\Command\SubmitOrder;

interface OfferProviderInterface
{
    public function getOffer(string $productId): ?OfferSnapshot;
}

