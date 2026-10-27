<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Catalog\Infrastructure\Persistence\Doctrine\Fixtures\CatalogFixtures;
use App\Inventory\Infrastructure\Integration\Ordering\OrderCancelledReleaseConsumer;
use App\Inventory\Infrastructure\Integration\Ordering\OrderSubmittedReservationConsumer;
use App\Inventory\Infrastructure\Persistence\Doctrine\Fixtures\InventoryFixtures;
use App\Kernel;
use App\Notifications\Infrastructure\Integration\Ordering\OrderCancellationFinalizedNotificationConsumer;
use App\Notifications\Infrastructure\Integration\Ordering\OrderConfirmedNotificationConsumer;
use App\Notifications\Infrastructure\Integration\Ordering\OrderRejectedNotificationConsumer;
use App\Ordering\Application\Command\ReconcileStock\ReconcileStockCommand;
use App\Ordering\Presentation\Contracts\Event\OrderCancellationFinalizedEvent;
use App\Ordering\Presentation\Contracts\Event\OrderCancelledEvent;
use App\Ordering\Presentation\Contracts\Event\OrderConfirmedEvent;
use App\Ordering\Presentation\Contracts\Event\OrderRejectedEvent;
use App\Ordering\Presentation\Contracts\Event\OrderSubmittedEvent;
use App\Shared\Application\Bus\Command\CommandBusInterface;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\HttpKernelBrowser;

/**
 * End-to-end functional test covering all buyer and administrative API scenarios (T14).
 * Verifies purchase, insufficient stock rejection, order cancellation, admin price/stock changes,
 * role permissions, CSRF protection, and idempotency replays and conflicts.
 */
final class CompleteScenariosTest extends TestCase
{
    private Kernel $kernel;
    private HttpKernelBrowser $client;
    private Connection $connection;
    private CommandBusInterface $commandBus;
    private OrderSubmittedReservationConsumer $reservationConsumer;
    private OrderCancelledReleaseConsumer $releaseConsumer;
    private OrderConfirmedNotificationConsumer $confirmedNotificationConsumer;
    private OrderRejectedNotificationConsumer $rejectedNotificationConsumer;
    private OrderCancellationFinalizedNotificationConsumer $cancelledNotificationConsumer;

    protected function setUp(): void
    {
        $this->kernel = new Kernel('test', true);
        $this->kernel->boot();
        $this->client = new HttpKernelBrowser($this->kernel);

        $container = $this->kernel->getContainer()->get('test.service_container');
        /** @var ManagerRegistry $doctrine */
        $doctrine = $container->get('doctrine');
        $em = $doctrine->getManager();
        $this->connection = $doctrine->getConnection();

        $this->commandBus = $container->get(CommandBusInterface::class);
        $this->reservationConsumer = $container->get(OrderSubmittedReservationConsumer::class);
        $this->releaseConsumer = $container->get(OrderCancelledReleaseConsumer::class);
        $this->confirmedNotificationConsumer = $container->get(OrderConfirmedNotificationConsumer::class);
        $this->rejectedNotificationConsumer = $container->get(OrderRejectedNotificationConsumer::class);
        $this->cancelledNotificationConsumer = $container->get(OrderCancellationFinalizedNotificationConsumer::class);

        // Load catalog and inventory fixtures
        $catFixtures = new CatalogFixtures();
        $catFixtures->load($em);

        $invFixtures = new InventoryFixtures();
        $invFixtures->load($em);

        // Clean tables for repeatable tests
        $this->cleanDatabase();
    }

    protected function tearDown(): void
    {
        $this->cleanDatabase();
        $this->kernel->shutdown();
    }

    private function cleanDatabase(): void
    {
        $this->connection->executeStatement("DELETE FROM ordering.order_items WHERE order_id IN (SELECT id FROM ordering.orders WHERE buyer_id IN ('buyer1', 'buyer2'))");
        $this->connection->executeStatement("DELETE FROM ordering.orders WHERE buyer_id IN ('buyer1', 'buyer2')");
        $this->connection->executeStatement('DELETE FROM inventory.reservation_items');
        $this->connection->executeStatement('DELETE FROM inventory.reservations');
        $this->connection->executeStatement("DELETE FROM notifications.notifications WHERE buyer_id IN ('buyer1', 'buyer2')");
        $this->connection->executeStatement("DELETE FROM ordering.idempotency_keys WHERE buyer_id IN ('buyer1', 'buyer2')");
        $this->connection->executeStatement('DELETE FROM shared.outbox_messages');
        $this->connection->executeStatement('DELETE FROM shared.processed_messages');
    }

    /**
     * Managed step-by-step queue and reconciliation processor.
     * Consumes outbox events, runs stock reconciliation, and routes notification events without timers.
     */
    private function processQueuesAndReconcile(): void
    {
        // 1. Process pending outbox messages (OrderSubmittedEvent, OrderCancelledEvent)
        $rows = $this->connection->fetchAllAssociative('SELECT id, body FROM shared.outbox_messages ORDER BY id ASC');
        foreach ($rows as $row) {
            $event = unserialize(base64_decode((string) $row['body'], true));
            if ($event instanceof OrderSubmittedEvent) {
                ($this->reservationConsumer)($event);
                $this->connection->delete('shared.outbox_messages', ['id' => $row['id']]);
            } elseif ($event instanceof OrderCancelledEvent) {
                ($this->releaseConsumer)($event);
                $this->connection->delete('shared.outbox_messages', ['id' => $row['id']]);
            }
        }

        // 2. Run stock reconciliation batch
        $this->commandBus->dispatch(new ReconcileStockCommand(50));

        // 3. Process resulting outbox messages (OrderConfirmedEvent, OrderRejectedEvent, OrderCancellationFinalizedEvent)
        $rowsAfterReconcile = $this->connection->fetchAllAssociative('SELECT id, body FROM shared.outbox_messages ORDER BY id ASC');
        foreach ($rowsAfterReconcile as $row) {
            $event = unserialize(base64_decode((string) $row['body'], true));
            if ($event instanceof OrderConfirmedEvent) {
                ($this->confirmedNotificationConsumer)($event);
                $this->connection->delete('shared.outbox_messages', ['id' => $row['id']]);
            } elseif ($event instanceof OrderRejectedEvent) {
                ($this->rejectedNotificationConsumer)($event);
                $this->connection->delete('shared.outbox_messages', ['id' => $row['id']]);
            } elseif ($event instanceof OrderCancellationFinalizedEvent) {
                ($this->cancelledNotificationConsumer)($event);
                $this->connection->delete('shared.outbox_messages', ['id' => $row['id']]);
            }
        }
    }

    private function getCsrfToken(): string
    {
        $this->client->request('GET', '/api/v1/auth/session');
        /** @var array{csrfToken: string} $data */
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);

        return $data['csrfToken'];
    }

    private function login(string $username, string $password): string
    {
        $initialCsrf = $this->getCsrfToken();
        $this->client->request(
            'POST',
            '/api/v1/auth/login',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-CSRF-TOKEN' => $initialCsrf,
            ],
            content: json_encode(['username' => $username, 'password' => $password]),
        );
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        return $this->getCsrfToken();
    }

    public function testScenario1SuccessfulPurchase(): void
    {
        // 1. Anonymous visitor checks catalog
        $this->client->request('GET', '/api/v1/products?page=1&limit=10');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        /** @var array{items: list<array{id: string, name: string, price: int, published: bool}>} $catalogData */
        $catalogData = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertNotEmpty($catalogData['items']);

        // 2. Buyer1 logs in
        $csrfToken = $this->login('buyer1', 'password');

        // 3. Create draft order
        $this->client->request(
            'POST',
            '/api/v1/orders',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-CSRF-TOKEN' => $csrfToken,
            ],
        );
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        /** @var array{id: string, status: string, items: list<mixed>} $draft */
        $draft = json_decode((string) $this->client->getResponse()->getContent(), true);
        $orderId = $draft['id'];
        self::assertSame('draft', $draft['status']);
        self::assertEmpty($draft['items']);

        // 4. Fill cart with 1 keyboard (799000) and 2 mice (2 * 349000 = 698000), total = 1497000
        $this->client->request(
            'PUT',
            "/api/v1/orders/{$orderId}/items",
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-CSRF-TOKEN' => $csrfToken,
            ],
            content: json_encode([
                'items' => [
                    ['productId' => 'prod-keyboard', 'quantity' => 1],
                    ['productId' => 'prod-mouse', 'quantity' => 2],
                ],
            ]),
        );
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        /** @var array{items: list<array{productId: string, quantity: int}>} $cart */
        $cart = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertCount(2, $cart['items']);

        // 5. Submit order -> 202 Accepted, pending_stock
        $this->client->request(
            'POST',
            "/api/v1/orders/{$orderId}/submission",
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-CSRF-TOKEN' => $csrfToken,
                'HTTP_IDEMPOTENCY-KEY' => 'purchase-sub-key-1',
            ],
        );
        $submitResponse = $this->client->getResponse();
        self::assertSame(202, $submitResponse->getStatusCode());
        /** @var array{id: string, status: string, totalAmount: int, currency: string, items: list<array{productId: string, priceAmount: int, productName: string}>} $submitted */
        $submitted = json_decode((string) $submitResponse->getContent(), true);
        self::assertSame($orderId, $submitted['id']);
        self::assertSame('pending_stock', $submitted['status']);
        self::assertSame(1497000, $submitted['totalAmount']);
        self::assertSame('RUB', $submitted['currency']);
        self::assertCount(2, $submitted['items']);

        // 6. Polling before queue processing still reports intermediate pending_stock
        $this->client->request('GET', "/api/v1/orders/{$orderId}");
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        /** @var array{status: string} $pollingIntermediate */
        $pollingIntermediate = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('pending_stock', $pollingIntermediate['status']);

        // 7. Execute controlled queue & reconciliation
        $this->processQueuesAndReconcile();

        // 8. Polling after processing -> order is CONFIRMED
        $this->client->request('GET', "/api/v1/orders/{$orderId}");
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        /** @var array{id: string, status: string, totalAmount: int, currency: string, rejectionReason: ?string} $confirmedOrder */
        $confirmedOrder = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('confirmed', $confirmedOrder['status']);
        self::assertSame(1497000, $confirmedOrder['totalAmount']);
        self::assertSame('RUB', $confirmedOrder['currency']);
        self::assertNull($confirmedOrder['rejectionReason']);

        // 9. Admin checks order diagnostics
        $adminCsrf = $this->login('admin', 'password');
        $this->client->request('GET', "/api/v1/admin/diagnostics/orders/{$orderId}");
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        /** @var array{order: array{status: string, totalAmount: int}, inventory: array{status: string, rejectionReason: ?string}, notifications: list<array{type: string, status: string}>} $diag */
        $diag = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('confirmed', $diag['order']['status']);
        self::assertSame(1497000, $diag['order']['totalAmount']);
        self::assertSame('reserved', $diag['inventory']['status']);
        self::assertNull($diag['inventory']['rejectionReason']);

        $types = array_column($diag['notifications'], 'type');
        self::assertContains('order_confirmed', $types);
        $confirmedNotif = $diag['notifications'][array_search('order_confirmed', $types, true)];
        self::assertSame('sent', $confirmedNotif['status']);

        // 10. Admin checks notifications mailbox
        $this->client->request('GET', '/api/v1/admin/notifications?page=1&limit=10');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        /** @var array{items: list<array{orderId: string, type: string, status: string}>, total: int} $mailbox */
        $mailbox = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertGreaterThanOrEqual(1, $mailbox['total']);
        $mailboxOrderIds = array_column($mailbox['items'], 'orderId');
        self::assertContains($orderId, $mailboxOrderIds);
    }

    public function testScenario2InsufficientStockRejection(): void
    {
        // 1. Admin sets stock of prod-monitor to 1
        $adminCsrf = $this->login('admin', 'password');
        $this->client->request(
            'PATCH',
            '/api/v1/admin/stock/prod-monitor',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-CSRF-TOKEN' => $adminCsrf,
            ],
            content: json_encode(['onHand' => 1]),
        );
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        // 2. Buyer1 creates draft requesting 5 monitors (exceeding stock of 1)
        $buyerCsrf = $this->login('buyer1', 'password');
        $this->client->request(
            'POST',
            '/api/v1/orders',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-CSRF-TOKEN' => $buyerCsrf,
            ],
        );
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        /** @var array{id: string} $draft */
        $draft = json_decode((string) $this->client->getResponse()->getContent(), true);
        $orderId = $draft['id'];

        $this->client->request(
            'PUT',
            "/api/v1/orders/{$orderId}/items",
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-CSRF-TOKEN' => $buyerCsrf,
            ],
            content: json_encode([
                'items' => [
                    ['productId' => 'prod-monitor', 'quantity' => 5],
                ],
            ]),
        );
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        // 3. Submit -> 202 Accepted (pending_stock)
        $this->client->request(
            'POST',
            "/api/v1/orders/{$orderId}/submission",
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-CSRF-TOKEN' => $buyerCsrf,
            ],
        );
        self::assertSame(202, $this->client->getResponse()->getStatusCode());

        // 4. Run queue processing and reconciliation
        $this->processQueuesAndReconcile();

        // 5. Buyer polls order -> status is REJECTED with reason insufficient_stock
        $this->client->request('GET', "/api/v1/orders/{$orderId}");
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        /** @var array{status: string, rejectionReason: string} $rejectedOrder */
        $rejectedOrder = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('rejected', $rejectedOrder['status']);
        self::assertSame('insufficient_stock', $rejectedOrder['rejectionReason']);

        // 6. Admin checks diagnostics -> order rejected, inventory rejected, notification sent
        $this->login('admin', 'password');
        $this->client->request('GET', "/api/v1/admin/diagnostics/orders/{$orderId}");
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        /** @var array{order: array{status: string, rejectionReason: string}, inventory: array{status: string, rejectionReason: string}, notifications: list<array{type: string, status: string}>} $diag */
        $diag = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('rejected', $diag['order']['status']);
        self::assertSame('insufficient_stock', $diag['order']['rejectionReason']);
        self::assertSame('rejected', $diag['inventory']['status']);
        self::assertSame('insufficient_stock', $diag['inventory']['rejectionReason']);

        $types = array_column($diag['notifications'], 'type');
        self::assertContains('order_rejected', $types);
        $rejectedNotif = $diag['notifications'][array_search('order_rejected', $types, true)];
        self::assertSame('sent', $rejectedNotif['status']);
    }

    public function testScenario3OrderCancellationAndStockRelease(): void
    {
        // 1. Buyer1 creates and submits order for 2 keyboards
        $buyerCsrf = $this->login('buyer1', 'password');
        $this->client->request(
            'POST',
            '/api/v1/orders',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-CSRF-TOKEN' => $buyerCsrf,
            ],
        );
        /** @var array{id: string} $draft */
        $draft = json_decode((string) $this->client->getResponse()->getContent(), true);
        $orderId = $draft['id'];

        $this->client->request(
            'PUT',
            "/api/v1/orders/{$orderId}/items",
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-CSRF-TOKEN' => $buyerCsrf,
            ],
            content: json_encode([
                'items' => [
                    ['productId' => 'prod-keyboard', 'quantity' => 2],
                ],
            ]),
        );
        $this->client->request(
            'POST',
            "/api/v1/orders/{$orderId}/submission",
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-CSRF-TOKEN' => $buyerCsrf,
            ],
        );
        self::assertSame(202, $this->client->getResponse()->getStatusCode());

        // Process reservation -> order confirmed
        $this->processQueuesAndReconcile();

        $this->client->request('GET', "/api/v1/orders/{$orderId}");
        /** @var array{status: string} $confirmedData */
        $confirmedData = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('confirmed', $confirmedData['status']);

        // 2. Buyer cancels confirmed order -> 202 Accepted, status cancelling
        $this->client->request(
            'POST',
            "/api/v1/orders/{$orderId}/cancellation",
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-CSRF-TOKEN' => $buyerCsrf,
                'HTTP_IDEMPOTENCY-KEY' => 'cancel-key-test-1',
            ],
            content: json_encode(['reason' => 'buyer_changed_mind']),
        );
        self::assertSame(202, $this->client->getResponse()->getStatusCode());
        /** @var array{status: string} $cancellingData */
        $cancellingData = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('cancelling', $cancellingData['status']);

        // 3. Process release consumer and final cancellation
        $this->processQueuesAndReconcile();

        // 4. Buyer polls order -> status is CANCELLED
        $this->client->request('GET', "/api/v1/orders/{$orderId}");
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        /** @var array{status: string} $cancelledData */
        $cancelledData = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('cancelled', $cancelledData['status']);

        // 5. Admin diagnostics confirms release and cancellation
        $this->login('admin', 'password');
        $this->client->request('GET', "/api/v1/admin/diagnostics/orders/{$orderId}");
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        /** @var array{order: array{status: string}, inventory: array{status: string}, notifications: list<array{type: string, status: string}>} $diag */
        $diag = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('cancelled', $diag['order']['status']);
        self::assertSame('released', $diag['inventory']['status']);

        $types = array_column($diag['notifications'], 'type');
        self::assertContains('order_cancelled', $types);
    }

    public function testScenario4AdminModificationsAndFrozenSnapshots(): void
    {
        // 1. Admin modifies product price in catalog
        $adminCsrf = $this->login('admin', 'password');
        $this->client->request(
            'PATCH',
            '/api/v1/admin/products/prod-keyboard',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-CSRF-TOKEN' => $adminCsrf,
            ],
            content: json_encode(['price' => 899000, 'published' => true]),
        );
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        // 2. Admin sets stock on hand
        $this->client->request(
            'PATCH',
            '/api/v1/admin/stock/prod-keyboard',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-CSRF-TOKEN' => $adminCsrf,
            ],
            content: json_encode(['onHand' => 25]),
        );
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        /** @var array{onHand: int, available: int} $stockData */
        $stockData = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame(25, $stockData['onHand']);
        self::assertSame(25, $stockData['available']);

        // 3. Buyer1 submits order with new price 899000
        $buyerCsrf = $this->login('buyer1', 'password');
        $this->client->request(
            'POST',
            '/api/v1/orders',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-CSRF-TOKEN' => $buyerCsrf,
            ],
        );
        /** @var array{id: string} $draft */
        $draft = json_decode((string) $this->client->getResponse()->getContent(), true);
        $orderId = $draft['id'];

        $this->client->request(
            'PUT',
            "/api/v1/orders/{$orderId}/items",
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-CSRF-TOKEN' => $buyerCsrf,
            ],
            content: json_encode([
                'items' => [
                    ['productId' => 'prod-keyboard', 'quantity' => 1],
                ],
            ]),
        );
        $this->client->request(
            'POST',
            "/api/v1/orders/{$orderId}/submission",
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-CSRF-TOKEN' => $buyerCsrf,
            ],
        );
        self::assertSame(202, $this->client->getResponse()->getStatusCode());

        // 4. Admin changes catalog price again to 1200000
        $adminCsrf = $this->login('admin', 'password');
        $this->client->request(
            'PATCH',
            '/api/v1/admin/products/prod-keyboard',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-CSRF-TOKEN' => $adminCsrf,
            ],
            content: json_encode(['price' => 1200000]),
        );
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        // 5. Buyer1 checks order: captured price snapshot remains 899000 (A-04 snapshot immunity)
        $this->login('buyer1', 'password');
        $this->client->request('GET', "/api/v1/orders/{$orderId}");
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        /** @var array{totalAmount: int, items: list<array{priceAmount: int}>} $orderData */
        $orderData = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame(899000, $orderData['totalAmount']);
        self::assertSame(899000, $orderData['items'][0]['priceAmount']);
    }

    public function testSecurityPermissionsAndIdempotency(): void
    {
        // 1. 401 Unauthorized for unauthenticated requests
        $this->client->request('GET', '/api/v1/orders');
        self::assertSame(401, $this->client->getResponse()->getStatusCode());

        $this->client->request('GET', '/api/v1/admin/notifications');
        self::assertSame(401, $this->client->getResponse()->getStatusCode());

        $this->client->request('GET', '/api/v1/admin/diagnostics/orders/00000000-0000-4000-8400-000000000001');
        self::assertSame(401, $this->client->getResponse()->getStatusCode());

        // 2. 403 Forbidden for Buyer accessing Admin endpoints
        $buyerCsrf = $this->login('buyer1', 'password');
        $this->client->request('GET', '/api/v1/admin/notifications');
        self::assertSame(403, $this->client->getResponse()->getStatusCode());

        $this->client->request('GET', '/api/v1/admin/diagnostics/orders/00000000-0000-4000-8400-000000000001');
        self::assertSame(403, $this->client->getResponse()->getStatusCode());

        $this->client->request(
            'PATCH',
            '/api/v1/admin/products/prod-keyboard',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-CSRF-TOKEN' => $buyerCsrf,
            ],
            content: json_encode(['price' => 500]),
        );
        self::assertSame(403, $this->client->getResponse()->getStatusCode());

        // 3. 403 Forbidden for missing / invalid CSRF token on mutating requests
        $this->client->request(
            'POST',
            '/api/v1/orders',
            server: ['CONTENT_TYPE' => 'application/json'],
        );
        self::assertSame(403, $this->client->getResponse()->getStatusCode());

        $this->client->request(
            'POST',
            '/api/v1/orders',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-CSRF-TOKEN' => 'invalid-token',
            ],
        );
        self::assertSame(403, $this->client->getResponse()->getStatusCode());

        // 4. 404 Not Found for accessing another buyer's order
        $this->client->request(
            'POST',
            '/api/v1/orders',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-CSRF-TOKEN' => $buyerCsrf,
            ],
        );
        /** @var array{id: string} $buyer1Draft */
        $buyer1Draft = json_decode((string) $this->client->getResponse()->getContent(), true);
        $buyer1OrderId = $buyer1Draft['id'];

        // Buyer2 logs in
        $buyer2Csrf = $this->login('buyer2', 'password');

        // Buyer2 tries to view, modify, or submit Buyer1's order -> 404 Not Found
        $this->client->request('GET', "/api/v1/orders/{$buyer1OrderId}");
        self::assertSame(404, $this->client->getResponse()->getStatusCode());

        $this->client->request(
            'PUT',
            "/api/v1/orders/{$buyer1OrderId}/items",
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-CSRF-TOKEN' => $buyer2Csrf,
            ],
            content: json_encode(['items' => [['productId' => 'prod-keyboard', 'quantity' => 1]]]),
        );
        self::assertSame(404, $this->client->getResponse()->getStatusCode());

        $this->client->request(
            'POST',
            "/api/v1/orders/{$buyer1OrderId}/submission",
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-CSRF-TOKEN' => $buyer2Csrf,
            ],
        );
        self::assertSame(404, $this->client->getResponse()->getStatusCode());

        $this->client->request(
            'POST',
            "/api/v1/orders/{$buyer1OrderId}/cancellation",
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-CSRF-TOKEN' => $buyer2Csrf,
            ],
        );
        self::assertSame(404, $this->client->getResponse()->getStatusCode());

        // 5. Idempotency replay with same payload vs conflicting payload
        $buyer1Csrf = $this->login('buyer1', 'password');
        $this->client->request(
            'PUT',
            "/api/v1/orders/{$buyer1OrderId}/items",
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-CSRF-TOKEN' => $buyer1Csrf,
            ],
            content: json_encode(['items' => [['productId' => 'prod-keyboard', 'quantity' => 1]]]),
        );
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        // First submit with key
        $this->client->request(
            'POST',
            "/api/v1/orders/{$buyer1OrderId}/submission",
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-CSRF-TOKEN' => $buyer1Csrf,
                'HTTP_IDEMPOTENCY-KEY' => 'idemp-test-key-42',
            ],
            content: json_encode(['comment' => 'original']),
        );
        self::assertSame(202, $this->client->getResponse()->getStatusCode());

        // Replay with identical body and same key -> 202 Accepted
        $this->client->request(
            'POST',
            "/api/v1/orders/{$buyer1OrderId}/submission",
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-CSRF-TOKEN' => $buyer1Csrf,
                'HTTP_IDEMPOTENCY-KEY' => 'idemp-test-key-42',
            ],
            content: json_encode(['comment' => 'original']),
        );
        self::assertSame(202, $this->client->getResponse()->getStatusCode());

        // Replay with conflicting body and same key -> 409 Conflict
        $this->client->request(
            'POST',
            "/api/v1/orders/{$buyer1OrderId}/submission",
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-CSRF-TOKEN' => $buyer1Csrf,
                'HTTP_IDEMPOTENCY-KEY' => 'idemp-test-key-42',
            ],
            content: json_encode(['comment' => 'conflict']),
        );
        self::assertSame(409, $this->client->getResponse()->getStatusCode());
        /** @var array{error: string} $conflictData */
        $conflictData = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('idempotency_conflict', $conflictData['error']);
    }
}
