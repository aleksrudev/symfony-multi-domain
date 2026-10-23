<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Persistence\Doctrine\DataProvider;

use App\Catalog\Domain\Collection\ProductCollection;
use App\Catalog\Domain\Collection\ProductPaginatedCollection;
use App\Catalog\Domain\DataProvider\ProductDataProviderInterface;
use App\Catalog\Domain\Entity\Product;
use App\Catalog\Domain\Exception\ProductNotFoundException;
use App\Catalog\Domain\ValueObject\ProductId;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(ProductDataProviderInterface::class)]
final readonly class ProductDataProvider implements ProductDataProviderInterface
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {
    }

    public function findById(ProductId $id): Product
    {
        $product = $this->em->find(Product::class, $id);
        if (!$product instanceof Product) {
            throw new ProductNotFoundException($id);
        }

        return $product;
    }

    public function findPublishedById(ProductId $id): Product
    {
        /** @var Product|null $product */
        $product = $this->em->createQueryBuilder()
            ->select('p')
            ->from(Product::class, 'p')
            ->where('p.id = :id AND p.published = true')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();

        if (null === $product) {
            throw new ProductNotFoundException($id);
        }

        return $product;
    }

    public function findPublishedPaginated(int $offset, int $limit): ProductPaginatedCollection
    {
        $count = (int) $this->em->createQueryBuilder()
            ->select('COUNT(p.id)')
            ->from(Product::class, 'p')
            ->where('p.published = true')
            ->getQuery()
            ->getSingleScalarResult();

        /** @var list<Product> $items */
        $items = $this->em->createQueryBuilder()
            ->select('p')
            ->from(Product::class, 'p')
            ->where('p.published = true')
            ->orderBy('p.createdAt', 'DESC')
            ->addOrderBy('p.id', 'ASC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return new ProductPaginatedCollection(
            items: new ProductCollection($items),
            count: $count,
        );
    }
}
