<?php

declare(strict_types=1);

namespace Tests\Functional\Ordering;

use App\Kernel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\HttpKernelBrowser;

final class OrderControllerTest extends TestCase
{
    private Kernel $kernel;
    private HttpKernelBrowser $client;

    protected function setUp(): void
    {
        $this->kernel = new Kernel('test', true);
        $this->kernel->boot();
        $this->client = new HttpKernelBrowser($this->kernel);
    }

    protected function tearDown(): void
    {
        $this->kernel->shutdown();
    }

    public function testAnonymousCannotAccessOrdersEndpoints(): void
    {
        $this->client->request('POST', '/api/v1/orders');
        self::assertSame(403, $this->client->getResponse()->getStatusCode());

        $this->client->request('GET', '/api/v1/orders');
        self::assertSame(401, $this->client->getResponse()->getStatusCode());

        $this->client->request('GET', '/api/v1/orders/non-existent-order');
        self::assertSame(401, $this->client->getResponse()->getStatusCode());

        $this->client->request('PUT', '/api/v1/orders/non-existent-order/items');
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testBuyerRequiresCsrfTokenForMutatingRequests(): void
    {
        $this->login('buyer1', 'password');

        // Request without X-CSRF-Token header
        $this->client->request(
            'POST',
            '/api/v1/orders',
            server: ['CONTENT_TYPE' => 'application/json'],
        );
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testBuyerCanCreateDraftOrder(): void
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

        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());

        /** @var array{id: string, buyerId: string, status: string, items: list<mixed>, createdAt: string} $data */
        $data = json_decode((string) $response->getContent(), true);

        self::assertNotEmpty($data['id']);
        self::assertSame('buyer1', $data['buyerId']);
        self::assertSame('draft', $data['status']);
        self::assertSame([], $data['items']);
    }

    public function testBuyerRepeatedCreateReturnsSameDraft(): void
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
        /** @var array{id: string} $firstData */
        $firstData = json_decode((string) $this->client->getResponse()->getContent(), true);
        $firstId = $firstData['id'];

        // Second create call by the same buyer
        $this->client->request(
            'POST',
            '/api/v1/orders',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-CSRF-TOKEN' => $csrfToken,
            ],
        );
        /** @var array{id: string} $secondData */
        $secondData = json_decode((string) $this->client->getResponse()->getContent(), true);

        self::assertSame($firstId, $secondData['id']);
    }

    public function testIdempotencyKeyWithSamePayloadReturnsSameDraft(): void
    {
        $csrfToken = $this->login('buyer1', 'password');

        $this->client->request(
            'POST',
            '/api/v1/orders',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-CSRF-TOKEN' => $csrfToken,
                'HTTP_IDEMPOTENCY-KEY' => 'idem-create-key-1',
            ],
            content: json_encode(['note' => 'same']),
        );
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        /** @var array{id: string} $data1 */
        $data1 = json_decode((string) $this->client->getResponse()->getContent(), true);

        // Repeat with identical key and payload
        $this->client->request(
            'POST',
            '/api/v1/orders',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-CSRF-TOKEN' => $csrfToken,
                'HTTP_IDEMPOTENCY-KEY' => 'idem-create-key-1',
            ],
            content: json_encode(['note' => 'same']),
        );
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        /** @var array{id: string} $data2 */
        $data2 = json_decode((string) $this->client->getResponse()->getContent(), true);

        self::assertSame($data1['id'], $data2['id']);
    }

    public function testIdempotencyKeyWithDifferentPayloadReturns409(): void
    {
        $csrfToken = $this->login('buyer1', 'password');

        $this->client->request(
            'POST',
            '/api/v1/orders',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-CSRF-TOKEN' => $csrfToken,
                'HTTP_IDEMPOTENCY-KEY' => 'idem-conflict-key-2',
            ],
            content: json_encode(['version' => 1]),
        );
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        // Repeat with same key but different body
        $this->client->request(
            'POST',
            '/api/v1/orders',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-CSRF-TOKEN' => $csrfToken,
                'HTTP_IDEMPOTENCY-KEY' => 'idem-conflict-key-2',
            ],
            content: json_encode(['version' => 2]),
        );

        self::assertSame(409, $this->client->getResponse()->getStatusCode());
        /** @var array{error: string} $conflictData */
        $conflictData = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('idempotency_conflict', $conflictData['error']);
    }

    public function testBuyerCanReplaceItemsInDraft(): void
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
        /** @var array{id: string} $createData */
        $createData = json_decode((string) $this->client->getResponse()->getContent(), true);
        $orderId = $createData['id'];

        $this->client->request(
            'PUT',
            '/api/v1/orders/'.$orderId.'/items',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-CSRF-TOKEN' => $csrfToken,
            ],
            content: json_encode([
                'items' => [
                    ['productId' => 'prod-keyboard', 'quantity' => 2],
                    ['productId' => 'prod-mouse', 'quantity' => 1],
                ],
            ]),
        );

        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());

        /** @var array{id: string, items: list<array{productId: string, quantity: int}>} $updateData */
        $updateData = json_decode((string) $response->getContent(), true);

        self::assertSame($orderId, $updateData['id']);
        self::assertCount(2, $updateData['items']);
        $productIds = array_column($updateData['items'], 'productId');
        self::assertContains('prod-keyboard', $productIds);
        self::assertContains('prod-mouse', $productIds);
    }

    public function testReplaceItemsWithInvalidQuantityReturns422(): void
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
        /** @var array{id: string} $createData */
        $createData = json_decode((string) $this->client->getResponse()->getContent(), true);
        $orderId = $createData['id'];

        $this->client->request(
            'PUT',
            '/api/v1/orders/'.$orderId.'/items',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-CSRF-TOKEN' => $csrfToken,
            ],
            content: json_encode([
                'items' => [
                    ['productId' => 'prod-keyboard', 'quantity' => 0],
                ],
            ]),
        );

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
    }

    public function testReplaceItemsWithEmptyItemsReturns422(): void
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
        /** @var array{id: string} $createData */
        $createData = json_decode((string) $this->client->getResponse()->getContent(), true);
        $orderId = $createData['id'];

        $this->client->request(
            'PUT',
            '/api/v1/orders/'.$orderId.'/items',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-CSRF-TOKEN' => $csrfToken,
            ],
            content: json_encode([
                'items' => [],
            ]),
        );

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
    }

    public function testBuyerCanGetOwnOrder(): void
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
        /** @var array{id: string} $createData */
        $createData = json_decode((string) $this->client->getResponse()->getContent(), true);
        $orderId = $createData['id'];

        $this->client->request('GET', '/api/v1/orders/'.$orderId);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        /** @var array{id: string, buyerId: string, status: string} $getData */
        $getData = json_decode((string) $this->client->getResponse()->getContent(), true);

        self::assertSame($orderId, $getData['id']);
        self::assertSame('buyer1', $getData['buyerId']);
        self::assertSame('draft', $getData['status']);
    }

    public function testBuyerCannotGetAnotherBuyersOrderReturns404(): void
    {
        // Buyer 1 creates draft
        $csrf1 = $this->login('buyer1', 'password');
        $this->client->request(
            'POST',
            '/api/v1/orders',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-CSRF-TOKEN' => $csrf1,
            ],
        );
        /** @var array{id: string} $data1 */
        $data1 = json_decode((string) $this->client->getResponse()->getContent(), true);
        $buyer1OrderId = $data1['id'];

        // Buyer 2 logs in with separate session
        $buyer2Client = new HttpKernelBrowser($this->kernel);
        $buyer2Client->request('GET', '/api/v1/auth/session');
        /** @var array{csrfToken: string} $sess2 */
        $sess2 = json_decode((string) $buyer2Client->getResponse()->getContent(), true);
        $buyer2Client->request(
            'POST',
            '/api/v1/auth/login',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-CSRF-TOKEN' => $sess2['csrfToken'],
            ],
            content: json_encode(['username' => 'buyer2', 'password' => 'password']),
        );
        self::assertSame(200, $buyer2Client->getResponse()->getStatusCode());

        // Buyer 2 attempts to get Buyer 1's order -> must return 404
        $buyer2Client->request('GET', '/api/v1/orders/'.$buyer1OrderId);
        $response = $buyer2Client->getResponse();

        self::assertSame(404, $response->getStatusCode());
        /** @var array{error: string} $errorData */
        $errorData = json_decode((string) $response->getContent(), true);
        self::assertSame('order_not_found', $errorData['error']);
    }

    public function testBuyerCanListOrdersWithPagination(): void
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

        $this->client->request('GET', '/api/v1/orders?page=1&limit=10');
        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());

        /** @var array{items: list<mixed>, total: int, page: int, limit: int} $data */
        $data = json_decode((string) $response->getContent(), true);

        self::assertSame(1, $data['page']);
        self::assertSame(10, $data['limit']);
        self::assertGreaterThanOrEqual(1, $data['total']);
        self::assertNotEmpty($data['items']);
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

        // Refresh CSRF token for the authenticated session
        $this->client->request('GET', '/api/v1/auth/session');
        /** @var array{csrfToken: string} $authSessionData */
        $authSessionData = json_decode((string) $this->client->getResponse()->getContent(), true);

        return $authSessionData['csrfToken'];
    }
}
