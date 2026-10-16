<?php

declare(strict_types=1);

namespace Tests\Integration\Ordering;

use App\Ordering\Domain\Collection\OrderItemCollection;
use App\Ordering\Domain\DataProvider\OrderDataProviderInterface;
use App\Ordering\Domain\Entity\Order;
use App\Ordering\Domain\Entity\OrderItem;
use App\Ordering\Domain\Enum\OrderStatus;
use App\Ordering\Domain\Exception\OrderNotFoundException;
use App\Ordering\Domain\Repository\OrderRepositoryInterface;
use App\Ordering\Domain\ValueObject\BuyerId;
use App\Ordering\Domain\ValueObject\OrderId;
use App\Ordering\Domain\ValueObject\ProductId;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Tests\Integration\IntegrationTestCase;

final class OrderRepositoryAndDataProviderTest extends IntegrationTestCase
{
    private OrderRepositoryInterface $repository;
    private OrderDataProviderInterface $dataProvider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = self::getContainer()->get(OrderRepositoryInterface::class);
        $this->dataProvider = self::getContainer()->get(OrderDataProviderInterface::class);
    }

    public function testSaveAndFindOrderWithItems(): void
    {
        $orderId = new OrderId('00000000-0000-4000-8000-000000000001');
        $buyerId = new BuyerId('test-buyer-1');

        $order = Order::createDraft($orderId, $buyerId);
        $order->replaceItems(new OrderItemCollection([
            new OrderItem(new ProductId('prod-1'), 2),
            new OrderItem(new ProductId('prod-2'), 1),
        ]));

        $this->repository->save($order);
        $this->em->flush();
        $this->em->clear();

        $loadedOrder = $this->dataProvider->findById($orderId);

        self::assertTrue($loadedOrder->id()->equals($orderId));
        self::assertTrue($loadedOrder->buyerId()->equals($buyerId));
        self::assertSame(OrderStatus::DRAFT, $loadedOrder->status());
        self::assertCount(2, $loadedOrder->items());

        $itemIds = array_map(fn (OrderItem $i): string => $i->productId()->value, $loadedOrder->items()->all());
        self::assertContains('prod-1', $itemIds);
        self::assertContains('prod-2', $itemIds);
    }

    public function testFindDraftByBuyerId(): void
    {
        $buyerId = new BuyerId('test-buyer-draft');
        $orderId = new OrderId('00000000-0000-4000-8000-000000000002');
        $order = Order::createDraft($orderId, $buyerId);

        $this->repository->save($order);
        $this->em->flush();
        $this->em->clear();

        $loadedDraft = $this->dataProvider->findDraftByBuyerId($buyerId);
        self::assertTrue($loadedDraft->id()->equals($orderId));
    }

    public function testFindDraftThrowsWhenNoDraftExists(): void
    {
        $this->expectException(OrderNotFoundException::class);
        $this->dataProvider->findDraftByBuyerId(new BuyerId('non-existent-buyer'));
    }

    public function testFindByIdAndBuyerIdHidesForeignOrder(): void
    {
        $orderId = new OrderId('00000000-0000-4000-8000-000000000003');
        $order = Order::createDraft($orderId, new BuyerId('owner-buyer'));
        $this->repository->save($order);
        $this->em->flush();
        $this->em->clear();

        // Own order found
        $loaded = $this->dataProvider->findByIdAndBuyerId($orderId, new BuyerId('owner-buyer'));
        self::assertTrue($loaded->id()->equals($orderId));

        // Foreign order throws OrderNotFoundException
        $this->expectException(OrderNotFoundException::class);
        $this->dataProvider->findByIdAndBuyerId($orderId, new BuyerId('other-buyer'));
    }

    public function testReplaceItemsUpdatesOrphanRemoval(): void
    {
        $orderId = new OrderId('00000000-0000-4000-8000-000000000004');
        $buyerId = new BuyerId('test-buyer-orphan');

        $order = Order::createDraft($orderId, $buyerId);
        $order->replaceItems(new OrderItemCollection([
            new OrderItem(new ProductId('initial-prod'), 1),
        ]));

        $this->repository->save($order);
        $this->em->flush();
        $this->em->clear();

        $loaded = $this->dataProvider->findById($orderId);
        $loaded->replaceItems(new OrderItemCollection([
            new OrderItem(new ProductId('replaced-prod-1'), 3),
            new OrderItem(new ProductId('replaced-prod-2'), 4),
        ]));

        $this->repository->save($loaded);
        $this->em->flush();
        $this->em->clear();

        $reloaded = $this->dataProvider->findById($orderId);
        self::assertCount(2, $reloaded->items());
        $reloadedIds = array_map(fn (OrderItem $i): string => $i->productId()->value, $reloaded->items()->all());
        self::assertNotContains('initial-prod', $reloadedIds);
        self::assertContains('replaced-prod-1', $reloadedIds);
        self::assertContains('replaced-prod-2', $reloadedIds);
    }

    public function testUniquePartialDraftIndexRejectsSecondDraftForSameBuyer(): void
    {
        $buyerId = new BuyerId('test-buyer-unique-draft');

        $order1 = Order::createDraft(new OrderId('00000000-0000-4000-8000-000000000005'), $buyerId);
        $order2 = Order::createDraft(new OrderId('00000000-0000-4000-8000-000000000006'), $buyerId);

        $this->repository->save($order1);
        $this->em->flush();

        $this->expectException(UniqueConstraintViolationException::class);
        $this->repository->save($order2);
        $this->em->flush();
    }

    public function testBuyerCanHaveNewDraftAfterFirstOrderTransitions(): void
    {
        $buyerId = new BuyerId('test-buyer-transition');

        $order1 = new Order(new OrderId('00000000-0000-4000-8000-000000000007'), $buyerId, OrderStatus::PENDING_STOCK);
        $this->repository->save($order1);
        $this->em->flush();

        $order2 = Order::createDraft(new OrderId('00000000-0000-4000-8000-000000000008'), $buyerId);
        $this->repository->save($order2);
        $this->em->flush();
        $this->em->clear();

        $loadedDraft = $this->dataProvider->findDraftByBuyerId($buyerId);
        self::assertTrue($loadedDraft->id()->equals(new OrderId('00000000-0000-4000-8000-000000000008')));
    }
}
