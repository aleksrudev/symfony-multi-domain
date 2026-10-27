<?php

declare(strict_types=1);

namespace Tests\Functional\Ordering;

use App\Kernel;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\HttpKernelBrowser;

final class AdminOrderDiagnosticsControllerTest extends TestCase
{
    private Kernel $kernel;
    private HttpKernelBrowser $client;

    protected function setUp(): void
    {
        $this->kernel = new Kernel('test', true);
        $this->kernel->boot();
        $this->client = new HttpKernelBrowser($this->kernel);

        /** @var ManagerRegistry $doctrine */
        $doctrine = $this->kernel->getContainer()->get('doctrine');
        /** @var \Doctrine\DBAL\Connection $conn */
        $conn = $doctrine->getConnection();
        $conn->executeStatement("DELETE FROM ordering.order_items WHERE order_id LIKE '00000000-0000-4000-8900-%'");
        $conn->executeStatement("DELETE FROM ordering.orders WHERE id LIKE '00000000-0000-4000-8900-%'");
        $conn->executeStatement("DELETE FROM notifications.notifications WHERE order_id LIKE '00000000-0000-4000-8900-%'");
    }

    protected function tearDown(): void
    {
        $this->kernel->shutdown();
    }

    public function testDiagnosticsRequiresAdminRole(): void
    {
        $orderId = '00000000-0000-4000-8900-000000000001';

        // 1. Anonymous -> 401
        $this->client->request('GET', '/api/v1/admin/diagnostics/orders/'.$orderId);
        self::assertSame(401, $this->client->getResponse()->getStatusCode());

        // 2. Buyer -> 403
        $this->login('buyer1', 'password');
        $this->client->request('GET', '/api/v1/admin/diagnostics/orders/'.$orderId);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testDiagnosticsReturns404WhenOrderNotFound(): void
    {
        $orderId = '00000000-0000-4000-8900-000000000999';

        $this->login('admin', 'password');
        $this->client->request('GET', '/api/v1/admin/diagnostics/orders/'.$orderId);
        self::assertSame(404, $this->client->getResponse()->getStatusCode());

        /** @var array{error: string} $data */
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('order_not_found', $data['error']);
    }

    public function testDiagnosticsReturnsAggregatedOrderInventoryAndNotifications(): void
    {
        $orderId = '00000000-0000-4000-8900-000000000001';
        $buyerId = 'buyer1';

        /** @var ManagerRegistry $doctrine */
        $doctrine = $this->kernel->getContainer()->get('doctrine');
        /** @var \Doctrine\DBAL\Connection $conn */
        $conn = $doctrine->getConnection();

        // 1. Insert order into ordering.orders and ordering.order_items
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u+00');
        $conn->executeStatement(
            "INSERT INTO ordering.orders (id, buyer_id, status, total_amount, currency, created_at, updated_at) 
             VALUES (:id, :buyer_id, 'confirmed', 15000, 'RUB', :created_at, :updated_at)",
            [
                'id' => $orderId,
                'buyer_id' => $buyerId,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );
        $conn->executeStatement(
            "INSERT INTO ordering.order_items (order_id, product_id, quantity, product_name, price_amount, currency)
             VALUES (:order_id, '00000000-0000-4000-8000-000000000001', 1, 'Keyboard', 15000, 'RUB')",
            [
                'order_id' => $orderId,
            ],
        );

        // 2. Insert notification into notifications.notifications
        $conn->executeStatement(
            "INSERT INTO notifications.notifications (id, order_id, type, buyer_id, status, attempts, payload, created_at, updated_at, sent_at)
             VALUES ('00000000-0000-4000-8900-000000000002', :order_id, 'order_confirmed', :buyer_id, 'sent', 1, '{}', :created_at, :updated_at, :sent_at)",
            [
                'order_id' => $orderId,
                'buyer_id' => $buyerId,
                'created_at' => $now,
                'updated_at' => $now,
                'sent_at' => $now,
            ],
        );

        // 3. Request diagnostics as admin
        $this->login('admin', 'password');
        $this->client->request('GET', '/api/v1/admin/diagnostics/orders/'.$orderId);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        /** @var array{
         *     order: array{id: string, buyerId: string, status: string, totalAmount: int, currency: string, items: list<array{productId: string, quantity: int, productName: string, priceAmount: int}>},
         *     inventory: array{status: string, rejectionReason: ?string},
         *     notifications: list<array{id: string, type: string, status: string, attempts: int}>
         * } $data */
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);

        // Verify order
        self::assertSame($orderId, $data['order']['id']);
        self::assertSame($buyerId, $data['order']['buyerId']);
        self::assertSame('confirmed', $data['order']['status']);
        self::assertSame(15000, $data['order']['totalAmount']);
        self::assertSame('RUB', $data['order']['currency']);
        self::assertCount(1, $data['order']['items']);
        self::assertSame('Keyboard', $data['order']['items'][0]['productName']);
        self::assertSame(15000, $data['order']['items'][0]['priceAmount']);

        // Verify inventory (since no reservation exists for this test order ID, inventory contract returns not_found)
        self::assertSame('not_found', $data['inventory']['status']);
        self::assertNull($data['inventory']['rejectionReason']);

        // Verify notifications
        self::assertCount(1, $data['notifications']);
        self::assertSame('order_confirmed', $data['notifications'][0]['type']);
        self::assertSame('sent', $data['notifications'][0]['status']);
        self::assertSame(1, $data['notifications'][0]['attempts']);
    }

    private function login(string $username, string $password): void
    {
        $this->client->request('GET', '/api/v1/auth/session');
        /** @var array{csrfToken: string} $sessionData */
        $sessionData = json_decode((string) $this->client->getResponse()->getContent(), true);
        $csrfToken = $sessionData['csrfToken'];

        $this->client->request(
            'POST',
            '/api/v1/auth/login',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_CSRF_TOKEN' => $csrfToken,
            ],
            content: (string) json_encode([
                'username' => $username,
                'password' => $password,
            ]),
        );
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }
}
