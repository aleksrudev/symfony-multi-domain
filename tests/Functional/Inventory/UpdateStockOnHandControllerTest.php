<?php

declare(strict_types=1);

namespace Tests\Functional\Inventory;

use App\Inventory\Domain\Entity\StockPosition;
use App\Inventory\Domain\ValueObject\ProductId;
use App\Inventory\Infrastructure\Persistence\Doctrine\Fixtures\InventoryFixtures;
use App\Kernel;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\HttpKernelBrowser;

final class UpdateStockOnHandControllerTest extends TestCase
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
        $fixture = new InventoryFixtures();
        $fixture->load($em);
    }

    protected function tearDown(): void
    {
        $this->kernel->shutdown();
    }

    public function testAdminPatchRequiresAuthentication(): void
    {
        $this->client->request('GET', '/api/v1/auth/session');
        /** @var array{csrfToken: string} $sessionData */
        $sessionData = json_decode((string) $this->client->getResponse()->getContent(), true);
        $csrfToken = $sessionData['csrfToken'];

        $this->client->request(
            'PATCH',
            '/api/v1/admin/stock/prod-keyboard',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-CSRF-TOKEN' => $csrfToken,
            ],
            content: json_encode(['onHand' => 50]),
        );

        self::assertSame(401, $this->client->getResponse()->getStatusCode());
    }

    public function testAdminPatchForbiddenForBuyerRole(): void
    {
        $csrfToken = $this->login('buyer1', 'password');

        $this->client->request(
            'PATCH',
            '/api/v1/admin/stock/prod-keyboard',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-CSRF-TOKEN' => $csrfToken,
            ],
            content: json_encode(['onHand' => 50]),
        );

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testAdminPatchRequiresCsrfToken(): void
    {
        $this->login('admin', 'password');

        $this->client->request(
            'PATCH',
            '/api/v1/admin/stock/prod-keyboard',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['onHand' => 50]),
        );

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testAdminCanUpdateStockOnHand(): void
    {
        $csrfToken = $this->login('admin', 'password');

        $this->client->request(
            'PATCH',
            '/api/v1/admin/stock/prod-keyboard',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-CSRF-TOKEN' => $csrfToken,
            ],
            content: json_encode(['onHand' => 50]),
        );

        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());

        /** @var array{productId: string, onHand: int, reserved: int, available: int, updatedAt: string} $data */
        $data = json_decode((string) $response->getContent(), true);

        self::assertSame('prod-keyboard', $data['productId']);
        self::assertSame(50, $data['onHand']);
        self::assertSame(0, $data['reserved']);
        self::assertSame(50, $data['available']);
        self::assertNotEmpty($data['updatedAt']);
    }

    public function testAdminPatchWithNegativeOnHandReturns422(): void
    {
        $csrfToken = $this->login('admin', 'password');

        $this->client->request(
            'PATCH',
            '/api/v1/admin/stock/prod-keyboard',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-CSRF-TOKEN' => $csrfToken,
            ],
            content: json_encode(['onHand' => -5]),
        );

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
    }

    public function testAdminPatchWithMissingOnHandReturns422(): void
    {
        $csrfToken = $this->login('admin', 'password');

        $this->client->request(
            'PATCH',
            '/api/v1/admin/stock/prod-keyboard',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-CSRF-TOKEN' => $csrfToken,
            ],
            content: json_encode([]),
        );

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
    }

    public function testAdminPatchWithOnHandBelowReservedReturns422(): void
    {
        /** @var ManagerRegistry $doctrine */
        $doctrine = $this->kernel->getContainer()->get('doctrine');
        $em = $doctrine->getManager();

        $position = new StockPosition(new ProductId('prod-test-reserved'), onHand: 10, reserved: 8);
        $em->persist($position);
        $em->flush();

        $csrfToken = $this->login('admin', 'password');

        $this->client->request(
            'PATCH',
            '/api/v1/admin/stock/prod-test-reserved',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-CSRF-TOKEN' => $csrfToken,
            ],
            content: json_encode(['onHand' => 5]),
        );

        $response = $this->client->getResponse();
        self::assertSame(422, $response->getStatusCode());

        /** @var array{error: string, text: string} $errorData */
        $errorData = json_decode((string) $response->getContent(), true);
        self::assertSame('stock_on_hand_below_reserved', $errorData['error']);
    }

    public function testAdminPatchWithNonExistentProductReturns404(): void
    {
        $csrfToken = $this->login('admin', 'password');

        $this->client->request(
            'PATCH',
            '/api/v1/admin/stock/non-existent-product-id',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-CSRF-TOKEN' => $csrfToken,
            ],
            content: json_encode(['onHand' => 10]),
        );

        $response = $this->client->getResponse();
        self::assertSame(404, $response->getStatusCode());

        /** @var array{error: string, text: string} $errorData */
        $errorData = json_decode((string) $response->getContent(), true);
        self::assertSame('stock_position_not_found', $errorData['error']);
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
