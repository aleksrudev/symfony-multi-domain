<?php

declare(strict_types=1);

namespace Tests\Functional\Catalog;

use App\Catalog\Infrastructure\Persistence\Doctrine\Fixtures\CatalogFixtures;
use App\Kernel;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\HttpKernelBrowser;

final class ProductControllerTest extends TestCase
{
    private Kernel $kernel;
    private HttpKernelBrowser $client;

    protected function setUp(): void
    {
        $this->kernel = new Kernel('test', true);
        $this->kernel->boot();
        $this->client = new HttpKernelBrowser($this->kernel);

        // Seed demo products via fixtures
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

    public function testAnonymousShowcaseWithoutQueryParamsUsesDefaults(): void
    {
        $this->client->request('GET', '/api/v1/products');
        $response = $this->client->getResponse();

        self::assertSame(200, $response->getStatusCode());
        /** @var array{items: list<array{id: string, name: string, price: int, published: bool}>, total: int, page: int, limit: int} $data */
        $data = json_decode((string) $response->getContent(), true);

        self::assertSame(1, $data['page']);
        self::assertSame(10, $data['limit']);
        self::assertGreaterThanOrEqual(3, $data['total']);
    }

    public function testAnonymousShowcaseReturnsPublishedProducts(): void
    {
        $this->client->request('GET', '/api/v1/products?page=1&limit=10');
        $response = $this->client->getResponse();

        self::assertSame(200, $response->getStatusCode());
        /** @var array{items: list<array{id: string, name: string, price: int, published: bool}>, total: int, page: int, limit: int} $data */
        $data = json_decode((string) $response->getContent(), true);

        self::assertSame(1, $data['page']);
        self::assertSame(10, $data['limit']);
        self::assertGreaterThanOrEqual(3, $data['total']);

        $productIds = array_column($data['items'], 'id');
        self::assertContains('prod-keyboard', $productIds);
        self::assertContains('prod-mouse', $productIds);
        self::assertContains('prod-monitor', $productIds);
        self::assertNotContains('prod-headphones', $productIds);
    }

    public function testAnonymousShowcaseBoundaryPagination(): void
    {
        $this->client->request('GET', '/api/v1/products?page=1&limit=1');
        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        /** @var array{items: list<mixed>, limit: int} $data1 */
        $data1 = json_decode((string) $response->getContent(), true);
        self::assertSame(1, $data1['limit']);
        self::assertCount(1, $data1['items']);

        $this->client->request('GET', '/api/v1/products?page=1&limit=50');
        $response50 = $this->client->getResponse();
        self::assertSame(200, $response50->getStatusCode());
        /** @var array{items: list<mixed>, limit: int} $data50 */
        $data50 = json_decode((string) $response50->getContent(), true);
        self::assertSame(50, $data50['limit']);
    }

    #[DataProvider('invalidPaginationProvider')]
    public function testAnonymousShowcaseInvalidPaginationReturns422(string $queryString): void
    {
        $this->client->request('GET', '/api/v1/products?'.$queryString);
        $response = $this->client->getResponse();

        self::assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        self::assertIsArray($data);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function invalidPaginationProvider(): iterable
    {
        yield 'page is zero' => ['page=0'];
        yield 'page is negative' => ['page=-1'];
        yield 'page is not integer' => ['page=abc'];
        yield 'limit is zero' => ['limit=0'];
        yield 'limit exceeds maximum' => ['limit=51'];
        yield 'limit is not integer' => ['limit=xyz'];
    }

    public function testCanGetPublishedProductDetail(): void
    {
        $this->client->request('GET', '/api/v1/products/prod-keyboard');
        $response = $this->client->getResponse();

        self::assertSame(200, $response->getStatusCode());
        /** @var array{id: string, name: string, price: int, currency: string, published: bool} $data */
        $data = json_decode((string) $response->getContent(), true);

        self::assertSame('prod-keyboard', $data['id']);
        self::assertSame('Механическая клавиатура', $data['name']);
        self::assertSame('RUB', $data['currency']);
        self::assertTrue($data['published']);
    }

    public function testUnpublishedProductReturns404(): void
    {
        $this->client->request('GET', '/api/v1/products/prod-headphones');
        $response = $this->client->getResponse();

        self::assertSame(404, $response->getStatusCode());
        /** @var array{text: string, error: string} $data */
        $data = json_decode((string) $response->getContent(), true);
        self::assertSame('product_not_found', $data['error']);
    }

    public function testNonExistentProductReturns404(): void
    {
        $this->client->request('GET', '/api/v1/products/non-existent-id');
        $response = $this->client->getResponse();

        self::assertSame(404, $response->getStatusCode());
        /** @var array{text: string, error: string} $data */
        $data = json_decode((string) $response->getContent(), true);
        self::assertSame('product_not_found', $data['error']);
    }

    public function testAdminPatchRequiresAuthentication(): void
    {
        $this->client->request('GET', '/api/v1/auth/session');
        /** @var array{csrfToken: string} $sessionData */
        $sessionData = json_decode((string) $this->client->getResponse()->getContent(), true);
        $csrfToken = $sessionData['csrfToken'];

        $this->client->request(
            'PATCH',
            '/api/v1/admin/products/prod-keyboard',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-CSRF-TOKEN' => $csrfToken,
            ],
            content: json_encode(['price' => 999000]),
        );

        self::assertSame(401, $this->client->getResponse()->getStatusCode());
    }

    public function testAdminPatchForbiddenForBuyerRole(): void
    {
        $this->login('buyer1', 'password');

        $this->client->request(
            'PATCH',
            '/api/v1/admin/products/prod-keyboard',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['price' => 999000]),
        );

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testAdminPatchRequiresCsrfToken(): void
    {
        $this->login('admin', 'password');

        $this->client->request(
            'PATCH',
            '/api/v1/admin/products/prod-keyboard',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['price' => 999000]),
        );

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testAdminCanUpdatePriceAndPublication(): void
    {
        $csrfToken = $this->login('admin', 'password');

        $this->client->request(
            'PATCH',
            '/api/v1/admin/products/prod-keyboard',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-CSRF-TOKEN' => $csrfToken,
            ],
            content: json_encode(['price' => 999000, 'published' => false]),
        );
        $patchResponse = $this->client->getResponse();

        self::assertSame(200, $patchResponse->getStatusCode());
        /** @var array{id: string, price: int, published: bool} $patchData */
        $patchData = json_decode((string) $patchResponse->getContent(), true);
        self::assertSame(999000, $patchData['price']);
        self::assertFalse($patchData['published']);

        // Since it is now unpublished, public item endpoint returns 404
        $this->client->request('GET', '/api/v1/products/prod-keyboard');
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testAdminPatchWithOnlyPricePreservesPublication(): void
    {
        $csrfToken = $this->login('admin', 'password');

        $this->client->request(
            'PATCH',
            '/api/v1/admin/products/prod-keyboard',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-CSRF-TOKEN' => $csrfToken,
            ],
            content: json_encode(['price' => 777000]),
        );
        $patchResponse = $this->client->getResponse();

        self::assertSame(200, $patchResponse->getStatusCode());
        /** @var array{id: string, price: int, published: bool} $patchData */
        $patchData = json_decode((string) $patchResponse->getContent(), true);
        self::assertSame(777000, $patchData['price']);
        self::assertTrue($patchData['published']);
    }

    public function testAdminPatchWithOnlyPublishedFalsePreservesPrice(): void
    {
        $csrfToken = $this->login('admin', 'password');

        // Check original price first
        $this->client->request('GET', '/api/v1/products/prod-keyboard');
        /** @var array{price: int} $initialData */
        $initialData = json_decode((string) $this->client->getResponse()->getContent(), true);
        $originalPrice = $initialData['price'];

        $this->client->request(
            'PATCH',
            '/api/v1/admin/products/prod-keyboard',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-CSRF-TOKEN' => $csrfToken,
            ],
            content: json_encode(['published' => false]),
        );
        $patchResponse = $this->client->getResponse();

        self::assertSame(200, $patchResponse->getStatusCode());
        /** @var array{id: string, price: int, published: bool} $patchData */
        $patchData = json_decode((string) $patchResponse->getContent(), true);
        self::assertSame($originalPrice, $patchData['price']);
        self::assertFalse($patchData['published']);
    }

    public function testAdminPatchWithInvalidPriceReturns422AndDoesNotModifyState(): void
    {
        $csrfToken = $this->login('admin', 'password');

        // Get initial price of prod-mouse
        $this->client->request('GET', '/api/v1/products/prod-mouse');
        /** @var array{price: int} $initialData */
        $initialData = json_decode((string) $this->client->getResponse()->getContent(), true);
        $originalPrice = $initialData['price'];

        $this->client->request(
            'PATCH',
            '/api/v1/admin/products/prod-mouse',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-CSRF-TOKEN' => $csrfToken,
            ],
            content: json_encode(['price' => -50]),
        );
        $response = $this->client->getResponse();

        self::assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        self::assertIsArray($data);

        // Verify state is unchanged
        $this->client->request('GET', '/api/v1/products/prod-mouse');
        /** @var array{price: int} $afterData */
        $afterData = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame($originalPrice, $afterData['price']);
    }

    public function testAdminPatchWithMalformedJsonReturns400(): void
    {
        $csrfToken = $this->login('admin', 'password');

        $this->client->request(
            'PATCH',
            '/api/v1/admin/products/prod-mouse',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-CSRF-TOKEN' => $csrfToken,
            ],
            content: '{"price": ',
        );
        $response = $this->client->getResponse();

        self::assertSame(400, $response->getStatusCode());
    }

    public function testAdminPatchWithUnsupportedContentTypeReturns415(): void
    {
        $csrfToken = $this->login('admin', 'password');

        $this->client->request(
            'PATCH',
            '/api/v1/admin/products/prod-mouse',
            server: [
                'CONTENT_TYPE' => 'text/plain',
                'HTTP_X-CSRF-TOKEN' => $csrfToken,
            ],
            content: 'price=1000',
        );
        $response = $this->client->getResponse();

        self::assertSame(415, $response->getStatusCode());
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
