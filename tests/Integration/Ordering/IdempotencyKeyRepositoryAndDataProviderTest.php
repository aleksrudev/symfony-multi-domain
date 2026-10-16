<?php

declare(strict_types=1);

namespace Tests\Integration\Ordering;

use App\Ordering\Domain\DataProvider\IdempotencyKeyDataProviderInterface;
use App\Ordering\Domain\Entity\IdempotencyKey;
use App\Ordering\Domain\Exception\IdempotencyKeyNotFoundException;
use App\Ordering\Domain\Repository\IdempotencyKeyRepositoryInterface;
use App\Ordering\Domain\ValueObject\BuyerId;
use Tests\Integration\IntegrationTestCase;

final class IdempotencyKeyRepositoryAndDataProviderTest extends IntegrationTestCase
{
    private IdempotencyKeyRepositoryInterface $repository;
    private IdempotencyKeyDataProviderInterface $dataProvider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = self::getContainer()->get(IdempotencyKeyRepositoryInterface::class);
        $this->dataProvider = self::getContainer()->get(IdempotencyKeyDataProviderInterface::class);
    }

    public function testSaveAndFindIdempotencyKey(): void
    {
        $buyerId = new BuyerId('buyer-int-1');
        $key = new IdempotencyKey('key-int-1', $buyerId, 'create_draft', 'hash-int-1');

        $this->repository->save($key);
        $this->em->flush();
        $this->em->clear();

        $loaded = $this->dataProvider->findByKeyAndBuyerId('key-int-1', $buyerId);

        self::assertSame('key-int-1', $loaded->key());
        self::assertTrue($loaded->buyerId()->equals($buyerId));
        self::assertSame('create_draft', $loaded->operation());
        self::assertSame('hash-int-1', $loaded->requestHash());
    }

    public function testFindThrowsExceptionWhenNotFound(): void
    {
        $this->expectException(IdempotencyKeyNotFoundException::class);
        $this->dataProvider->findByKeyAndBuyerId('non-existent', new BuyerId('buyer-int-1'));
    }

    public function testRemoveIdempotencyKey(): void
    {
        $buyerId = new BuyerId('buyer-int-2');
        $key = new IdempotencyKey('key-int-2', $buyerId, 'create_draft', 'hash-int-2');

        $this->repository->save($key);
        $this->em->flush();

        $this->repository->remove($key);
        $this->em->flush();
        $this->em->clear();

        $this->expectException(IdempotencyKeyNotFoundException::class);
        $this->dataProvider->findByKeyAndBuyerId('key-int-2', $buyerId);
    }
}
