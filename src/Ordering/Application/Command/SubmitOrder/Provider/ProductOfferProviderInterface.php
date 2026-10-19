<?php

declare(strict_types=1);

namespace App\Ordering\Application\Command\SubmitOrder\Provider;

interface ProductOfferProviderInterface
{
    public function getOffer(string $productId): ?ProductOffer;
}
