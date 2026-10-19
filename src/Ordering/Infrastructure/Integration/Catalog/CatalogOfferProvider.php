<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Integration\Catalog;

use App\Catalog\Presentation\Contracts\CatalogContractInterface;
use App\Ordering\Application\Command\SubmitOrder\OfferProviderInterface;
use App\Ordering\Application\Command\SubmitOrder\OfferSnapshot;

final readonly class CatalogOfferProvider implements OfferProviderInterface
{
    public function __construct(
        private CatalogContractInterface $catalogContract,
    ) {
    }

    public function getOffer(string $productId): ?OfferSnapshot
    {
        $offer = $this->catalogContract->getOffer($productId);
        if (null === $offer) {
            return null;
        }

        return new OfferSnapshot(
            productId: $offer->productId,
            name: $offer->name,
            priceAmount: $offer->priceAmount,
            currency: $offer->currency,
        );
    }
}

