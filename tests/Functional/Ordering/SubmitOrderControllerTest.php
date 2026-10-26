<?php

declare(strict_types=1);

namespace Tests\Functional\Ordering;

use App\Catalog\Infrastructure\Persistence\Doctrine\Fixtures\CatalogFixtures;
use App\Kernel;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\HttpKernelBrowser;

final class SubmitOrderControllerTest extends TestCase
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
        $em = $doctrine->getManager();
        $fixture = new CatalogFixtures();
        $fixture->load($em);
    }

    protected function tearDown(): void
    {
        $this->kernel->shutdown();
    }

    public function testSuccessfulOrderSubmissionWithOutboxMessage(): void
    {
        $csrfToken = $this->login('buyer1', 'password');

        // 1. Create draft order
        $this->client->request(
            'POST',
            '/api/v1/orders',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-CSRF-TOKEN' => $csrfToken,
            ],
        );
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        /** @var array{id: string} $draftData */
        $draftData = json_decode((string) $this->client->getResponse()->getContent(), true);
        $orderId = $draftData['id'];

        // 2. Put items in draft (1 keyboard = 799000, 2 mice = 2 * 349000 = 698000, total = 1497000)
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

        // 3. Submit order
        $this->client->request(
            'POST',
            "/api/v1/orders/{$orderId}/submission",
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-CSRF-TOKEN' => $csrfToken,
                'HTTP_IDEMPOTENCY-KEY' => 'submission-key-1',
            ],
        );
        $response = $this->client->getResponse();
        self::assertSame(202, $response->getStatusCode());

        /** @var array{id: string, status: string, totalAmount: int, currency: string, items: list<array{productId: string, quantity: int, name: string, price: int, currency: string}>} $data */
        $data = json_decode((string) $response->getContent(), true);
        self::assertSame($orderId, $data['id']);
        self::assertSame('pending_stock', $data['status']);
        self::assertSame(1497000, $data['totalAmount']);
        self::assertSame('RUB', $data['currency']);
        self::assertCount(2, $data['items']);

        // Verify outbox message written to database
        /** @var ManagerRegistry $doctrine */
        $doctrine = $this->kernel->getContainer()->get('doctrine');
        /** @var Connection $connection */
        $connection = $doctrine->getConnection();
        $outboxCount = (int) $connection->fetchOne('SELECT COUNT(*) FROM shared.outbox_messages');
        self::assertGreaterThanOrEqual(1, $outboxCount);
    }

    public function testPriceSnapshotImmunityToCatalogPriceChange(): void
    {
        $csrfToken = $this->login('buyer1', 'password');

        // Create and populate draft
        $this->client->request(
            'POST',
            '/api/v1/orders',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-CSRF-TOKEN' => $csrfToken,
            ],
        );
        /** @var array{id: string} $draftData */
        $draftData = json_decode((string) $this->client->getResponse()->getContent(), true);
        $orderId = $draftData['id'];

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
                ],
            ]),
        );

        // Submit order at current price 799000
        $this->client->request(
            'POST',
            "/api/v1/orders/{$orderId}/submission",
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-CSRF-TOKEN' => $csrfToken,
            ],
        );
        self::assertSame(202, $this->client->getResponse()->getStatusCode());

        // Admin changes price in catalog
        $adminCsrfToken = $this->login('admin', 'password');
        $this->client->request(
            'PATCH',
            '/api/v1/admin/products/prod-keyboard',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-CSRF-TOKEN' => $adminCsrfToken,
            ],
            content: json_encode(['price' => 999900]),
        );
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        // Buyer checks submitted order: price must remain 799000 (A-04, BR-03, BR-15)
        $buyerCsrfToken = $this->login('buyer1', 'password');
        $this->client->request('GET', "/api/v1/orders/{$orderId}");
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        /** @var array{totalAmount: int, items: list<array{productId: string, price: int}>} $submittedData */
        $submittedData = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame(799000, $submittedData['totalAmount']);
        self::assertSame(799000, $submittedData['items'][0]['price']);
    }

    public function testSubmittingOrderWithUnpublishedProductFails(): void
    {
        $csrfToken = $this->login('buyer1', 'password');

        $this->client->request(
            'POST',
            '/api/v1/orders',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-CSRF-TOKEN' => $csrfToken,
            ],
        );
        /** @var array{id: string} $draftData */
        $draftData = json_decode((string) $this->client->getResponse()->getContent(), true);
        $orderId = $draftData['id'];

        // prod-headphones is unpublished in CatalogFixtures
        $this->client->request(
            'PUT',
            "/api/v1/orders/{$orderId}/items",
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-CSRF-TOKEN' => $csrfToken,
            ],
            content: json_encode([
                'items' => [
                    ['productId' => 'prod-headphones', 'quantity' => 1],
                ],
            ]),
        );

        $this->client->request(
            'POST',
            "/api/v1/orders/{$orderId}/submission",
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-CSRF-TOKEN' => $csrfToken,
            ],
        );
        self::assertSame(422, $this->client->getResponse()->getStatusCode());

        /** @var array{error: string} $errorData */
        $errorData = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('product_not_available', $errorData['error']);

        // Order remains in draft state
        $this->client->request('GET', "/api/v1/orders/{$orderId}");
        /** @var array{status: string} $orderData */
        $orderData = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('draft', $orderData['status']);
    }

    public function testSubmittingEmptyOrderFails(): void
    {
        $csrfToken = $this->login('buyer1', 'password');

        $this->client->request(
            'POST',
            '/api/v1/orders',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-CSRF-TOKEN' => $csrfToken,
            ],
        );
        /** @var array{id: string} $draftData */
        $draftData = json_decode((string) $this->client->getResponse()->getContent(), true);
        $orderId = $draftData['id'];

        $this->client->request(
            'POST',
            "/api/v1/orders/{$orderId}/submission",
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-CSRF-TOKEN' => $csrfToken,
            ],
        );
        self::assertSame(422, $this->client->getResponse()->getStatusCode());

        /** @var array{error: string} $errorData */
        $errorData = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('empty_order_items', $errorData['error']);
    }

    public function testSubmittingAlreadySubmittedOrderReturns409(): void
    {
        $csrfToken = $this->login('buyer1', 'password');

        $this->client->request(
            'POST',
            '/api/v1/orders',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-CSRF-TOKEN' => $csrfToken,
            ],
        );
        /** @var array{id: string} $draftData */
        $draftData = json_decode((string) $this->client->getResponse()->getContent(), true);
        $orderId = $draftData['id'];

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
                ],
            ]),
        );

        $this->client->request(
            'POST',
            "/api/v1/orders/{$orderId}/submission",
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-CSRF-TOKEN' => $csrfToken,
            ],
        );
        self::assertSame(202, $this->client->getResponse()->getStatusCode());

        // Second submission attempt without idempotency key
        $this->client->request(
            'POST',
            "/api/v1/orders/{$orderId}/submission",
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-CSRF-TOKEN' => $csrfToken,
            ],
        );
        self::assertSame(409, $this->client->getResponse()->getStatusCode());

        /** @var array{error: string} $errorData */
        $errorData = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('order_not_in_draft_state', $errorData['error']);
    }

    public function testSubmitIdempotencyKeyRepeatAndConflict(): void
    {
        $csrfToken = $this->login('buyer1', 'password');

        $this->client->request(
            'POST',
            '/api/v1/orders',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-CSRF-TOKEN' => $csrfToken,
            ],
        );
        /** @var array{id: string} $draftData */
        $draftData = json_decode((string) $this->client->getResponse()->getContent(), true);
        $orderId = $draftData['id'];

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
                ],
            ]),
        );

        // First submit with idempotency key
        $this->client->request(
            'POST',
            "/api/v1/orders/{$orderId}/submission",
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-CSRF-TOKEN' => $csrfToken,
                'HTTP_IDEMPOTENCY-KEY' => 'sub-key-unique',
            ],
            content: json_encode(['comment' => 'please hurry']),
        );
        self::assertSame(202, $this->client->getResponse()->getStatusCode());

        // Repeat submit with same key and same body
        $this->client->request(
            'POST',
            "/api/v1/orders/{$orderId}/submission",
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-CSRF-TOKEN' => $csrfToken,
                'HTTP_IDEMPOTENCY-KEY' => 'sub-key-unique',
            ],
            content: json_encode(['comment' => 'please hurry']),
        );
        self::assertSame(202, $this->client->getResponse()->getStatusCode());

        // Repeat submit with same key and different body -> 409
        $this->client->request(
            'POST',
            "/api/v1/orders/{$orderId}/submission",
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-CSRF-TOKEN' => $csrfToken,
                'HTTP_IDEMPOTENCY-KEY' => 'sub-key-unique',
            ],
            content: json_encode(['comment' => 'different payload']),
        );
        self::assertSame(409, $this->client->getResponse()->getStatusCode());
    }

    public function testSubsequentDraftCreationAfterSubmissionCreatesNewDraft(): void
    {
        $csrfToken = $this->login('buyer1', 'password');

        // 1. Create draft 1
        $this->client->request(
            'POST',
            '/api/v1/orders',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-CSRF-TOKEN' => $csrfToken,
            ],
        );
        /** @var array{id: string} $firstDraftData */
        $firstDraftData = json_decode((string) $this->client->getResponse()->getContent(), true);
        $firstOrderId = $firstDraftData['id'];

        $this->client->request(
            'PUT',
            "/api/v1/orders/{$firstOrderId}/items",
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-CSRF-TOKEN' => $csrfToken,
            ],
            content: json_encode([
                'items' => [
                    ['productId' => 'prod-keyboard', 'quantity' => 1],
                ],
            ]),
        );

        // Submit draft 1 -> transitions to pending_stock
        $this->client->request(
            'POST',
            "/api/v1/orders/{$firstOrderId}/submission",
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-CSRF-TOKEN' => $csrfToken,
            ],
        );
        self::assertSame(202, $this->client->getResponse()->getStatusCode());

        // 2. Buyer creates draft again -> BR-16: should create a NEW draft!
        $this->client->request(
            'POST',
            '/api/v1/orders',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-CSRF-TOKEN' => $csrfToken,
            ],
        );
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        /** @var array{id: string, status: string} $secondDraftData */
        $secondDraftData = json_decode((string) $this->client->getResponse()->getContent(), true);

        self::assertNotSame($firstOrderId, $secondDraftData['id']);
        self::assertSame('draft', $secondDraftData['status']);
    }

    private function login(string $username, string $password): string
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
                'HTTP_X-CSRF-TOKEN' => $csrfToken,
            ],
            content: json_encode(['username' => $username, 'password' => $password]),
        );

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->client->request('GET', '/api/v1/auth/session');
        /** @var array{csrfToken: string} $authSessionData */
        $authSessionData = json_decode((string) $this->client->getResponse()->getContent(), true);

        return $authSessionData['csrfToken'];
    }
}
