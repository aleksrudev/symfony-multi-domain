<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Integration\Catalog;

use App\Catalog\Presentation\Contracts\CatalogContractInterface;
use App\Ordering\Application\Command\SubmitOrder\Provider\ProductOffer;
use App\Ordering\Application\Command\SubmitOrder\Provider\ProductOfferProviderInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(ProductOfferProviderInterface::class)]
final readonly class CatalogOfferProvider implements ProductOfferProviderInterface
{
    public function __construct(
        private CatalogContractInterface $catalogContract,
    ) {
    }

    public function getOffer(string $productId): ?ProductOffer
    {
        $offer = $this->catalogContract->getOffer($productId);
        if (null === $offer) {
            return null;
        }

        return new ProductOffer(
            productId: $offer->productId,
            name: $offer->name,
            priceAmount: $offer->priceAmount,
            currency: $offer->currency,
        );
    }
}
