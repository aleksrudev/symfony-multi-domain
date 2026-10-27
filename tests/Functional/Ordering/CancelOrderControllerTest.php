<?php

declare(strict_types=1);

namespace Tests\Functional\Ordering;

use App\Catalog\Infrastructure\Persistence\Doctrine\Fixtures\CatalogFixtures;
use App\Kernel;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\HttpKernelBrowser;

final class CancelOrderControllerTest extends TestCase
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

    public function testSuccessfulOrderCancellationWithIdempotency(): void
    {
        $csrfToken = $this->login('buyer1', 'password');

        // 1. Create draft
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

        // 2. Put item
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
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        // 3. Submit
        $this->client->request(
            'POST',
            "/api/v1/orders/{$orderId}/submission",
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-CSRF-TOKEN' => $csrfToken,
            ],
        );
        self::assertSame(202, $this->client->getResponse()->getStatusCode());

        // 4. Cancel
        $this->client->request(
            'POST',
            "/api/v1/orders/{$orderId}/cancellation",
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-CSRF-TOKEN' => $csrfToken,
                'HTTP_IDEMPOTENCY-KEY' => 'cancel-key-1',
            ],
            content: json_encode(['reason' => 'user_request']),
        );
        $response = $this->client->getResponse();
        self::assertSame(202, $response->getStatusCode());

        /** @var array{id: string, status: string, items: list<mixed>} $data */
        $data = json_decode((string) $response->getContent(), true);
        self::assertSame($orderId, $data['id']);
        self::assertSame('cancelling', $data['status']);
        self::assertCount(1, $data['items']);

        // 5. Repeat with same idempotency key and body -> 202 Accepted
        $this->client->request(
            'POST',
            "/api/v1/orders/{$orderId}/cancellation",
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-CSRF-TOKEN' => $csrfToken,
                'HTTP_IDEMPOTENCY-KEY' => 'cancel-key-1',
            ],
            content: json_encode(['reason' => 'user_request']),
        );
        self::assertSame(202, $this->client->getResponse()->getStatusCode());

        // 6. Repeat with same idempotency key and different body -> 409 Conflict
        $this->client->request(
            'POST',
            "/api/v1/orders/{$orderId}/cancellation",
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-CSRF-TOKEN' => $csrfToken,
                'HTTP_IDEMPOTENCY-KEY' => 'cancel-key-1',
            ],
            content: json_encode(['reason' => 'different_body']),
        );
        self::assertSame(409, $this->client->getResponse()->getStatusCode());
    }

    public function testCancelDraftOrderReturnsConflict(): void
    {
        $csrfToken = $this->login('buyer1', 'password');

        // Create draft
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

        // Cancel draft order -> 409
        $this->client->request(
            'POST',
            "/api/v1/orders/{$orderId}/cancellation",
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-CSRF-TOKEN' => $csrfToken,
            ],
        );
        self::assertSame(409, $this->client->getResponse()->getStatusCode());
    }

    public function testCancelAnotherBuyersOrderReturnsNotFound(): void
    {
        // Buyer 1 creates & submits order
        $csrfToken1 = $this->login('buyer1', 'password');
        $this->client->request(
            'POST',
            '/api/v1/orders',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-CSRF-TOKEN' => $csrfToken1,
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
                'HTTP_X-CSRF-TOKEN' => $csrfToken1,
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
                'HTTP_X-CSRF-TOKEN' => $csrfToken1,
            ],
        );

        // Buyer 2 logs in and tries to cancel Buyer 1's order
        $csrfToken2 = $this->login('buyer2', 'password');
        $this->client->request(
            'POST',
            "/api/v1/orders/{$orderId}/cancellation",
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-CSRF-TOKEN' => $csrfToken2,
            ],
        );
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testCancelWithoutCsrfTokenReturnsForbidden(): void
    {
        $this->login('buyer1', 'password');

        $this->client->request(
            'POST',
            '/api/v1/orders/00000000-0000-4000-8000-000000000001/cancellation',
            server: [
                'CONTENT_TYPE' => 'application/json',
            ],
        );
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testCancelWithoutAuthenticationReturnsUnauthorized(): void
    {
        $this->client->request('GET', '/api/v1/auth/session');
        /** @var array{csrfToken: string} $sessionData */
        $sessionData = json_decode((string) $this->client->getResponse()->getContent(), true);
        $csrfToken = $sessionData['csrfToken'];

        $this->client->request(
            'POST',
            '/api/v1/orders/00000000-0000-4000-8000-000000000001/cancellation',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-CSRF-TOKEN' => $csrfToken,
            ],
        );
        self::assertSame(401, $this->client->getResponse()->getStatusCode());
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
