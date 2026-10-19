<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Catalog\Infrastructure\Persistence\Doctrine\Fixtures\CatalogFixtures;
use App\Kernel;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\HttpKernelBrowser;

final class DraftOrderScenarioTest extends TestCase
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

    public function testCompleteFirstShoppingScenario(): void
    {
        // 1. Анонимный посетитель просматривает каталог опубликованных товаров
        $this->client->request('GET', '/api/v1/products?page=1&limit=10');
        $catalogResponse = $this->client->getResponse();
        self::assertSame(200, $catalogResponse->getStatusCode());

        /** @var array{items: list<array{id: string, name: string, price: int, currency: string, published: bool}>, total: int, page: int, limit: int} $catalogData */
        $catalogData = json_decode((string) $catalogResponse->getContent(), true);

        self::assertGreaterThanOrEqual(3, $catalogData['total']);
        self::assertSame(1, $catalogData['page']);
        self::assertSame(10, $catalogData['limit']);

        $publishedProductIds = array_column($catalogData['items'], 'id');
        self::assertContains('prod-keyboard', $publishedProductIds);
        self::assertContains('prod-mouse', $publishedProductIds);
        self::assertNotContains('prod-headphones', $publishedProductIds, 'Неопубликованный товар не должен отображаться на витрине.');

        // 2. Получение анонимной сессии и CSRF-токена
        $this->client->request('GET', '/api/v1/auth/session');
        $sessionResponse = $this->client->getResponse();
        self::assertSame(200, $sessionResponse->getStatusCode());

        /** @var array{authenticated: bool, user: ?string, roles: list<string>, csrfToken: string} $sessionData */
        $sessionData = json_decode((string) $sessionResponse->getContent(), true);
        self::assertFalse($sessionData['authenticated']);
        self::assertNotEmpty($sessionData['csrfToken']);
        $initialCsrfToken = $sessionData['csrfToken'];

        // 3. Вход в систему под учетной записью покупателя (buyer1)
        $this->client->request(
            'POST',
            '/api/v1/auth/login',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-CSRF-TOKEN' => $initialCsrfToken,
            ],
            content: json_encode(['username' => 'buyer1', 'password' => 'password']),
        );
        $loginResponse = $this->client->getResponse();
        self::assertSame(200, $loginResponse->getStatusCode());

        /** @var array{authenticated: bool, user: string, roles: list<string>} $loginData */
        $loginData = json_decode((string) $loginResponse->getContent(), true);
        self::assertTrue($loginData['authenticated']);
        self::assertSame('buyer1', $loginData['user']);
        self::assertContains('ROLE_BUYER', $loginData['roles']);

        // 4. Обновление сессии и получение актуального CSRF-токена для аутентифицированного покупателя
        $this->client->request('GET', '/api/v1/auth/session');
        /** @var array{authenticated: bool, user: string, roles: list<string>, csrfToken: string} $authSessionData */
        $authSessionData = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertTrue($authSessionData['authenticated']);
        self::assertSame('buyer1', $authSessionData['user']);
        $authCsrfToken = $authSessionData['csrfToken'];

        // 5. Создание черновика заказа с Idempotency-Key
        $idempotencyKey = 'scenario-create-draft-key-1';
        $this->client->request(
            'POST',
            '/api/v1/orders',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-CSRF-TOKEN' => $authCsrfToken,
                'HTTP_IDEMPOTENCY-KEY' => $idempotencyKey,
            ],
        );
        $createOrderResponse = $this->client->getResponse();
        self::assertSame(200, $createOrderResponse->getStatusCode());

        /** @var array{id: string, buyerId: string, status: string, items: list<mixed>, createdAt: string, updatedAt: string} $orderData */
        $orderData = json_decode((string) $createOrderResponse->getContent(), true);

        $orderId = $orderData['id'];
        self::assertNotEmpty($orderId);
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $orderId);
        self::assertSame('buyer1', $orderData['buyerId']);
        self::assertSame('draft', $orderData['status']);
        self::assertSame([], $orderData['items']);

        // 6. Замена позиций черновика (добавление клавиатуры и мыши)
        $this->client->request(
            'PUT',
            "/api/v1/orders/{$orderId}/items",
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-CSRF-TOKEN' => $authCsrfToken,
            ],
            content: json_encode([
                'items' => [
                    ['productId' => 'prod-keyboard', 'quantity' => 1],
                    ['productId' => 'prod-mouse', 'quantity' => 2],
                ],
            ]),
        );
        $replaceItemsResponse = $this->client->getResponse();
        self::assertSame(200, $replaceItemsResponse->getStatusCode());

        /** @var array{id: string, buyerId: string, status: string, items: list<array{productId: string, quantity: int}>} $replacedData */
        $replacedData = json_decode((string) $replaceItemsResponse->getContent(), true);
        self::assertSame($orderId, $replacedData['id']);
        self::assertSame('draft', $replacedData['status']);
        self::assertCount(2, $replacedData['items']);

        // 7. Чтение сохранённого черновика покупателем
        $this->client->request('GET', "/api/v1/orders/{$orderId}");
        $getOrderResponse = $this->client->getResponse();
        self::assertSame(200, $getOrderResponse->getStatusCode());

        /** @var array{id: string, buyerId: string, status: string, items: list<array{productId: string, quantity: int}>, createdAt: string, updatedAt: string} $readOrderData */
        $readOrderData = json_decode((string) $getOrderResponse->getContent(), true);
        self::assertSame($orderId, $readOrderData['id']);
        self::assertSame('buyer1', $readOrderData['buyerId']);
        self::assertSame('draft', $readOrderData['status']);
        self::assertEqualsCanonicalizing(
            [
                ['productId' => 'prod-keyboard', 'quantity' => 1],
                ['productId' => 'prod-mouse', 'quantity' => 2],
            ],
            $readOrderData['items'],
        );

        // 8. Список заказов покупателя содержит созданный черновик
        $this->client->request('GET', '/api/v1/orders?page=1&limit=10');
        $ordersListResponse = $this->client->getResponse();
        self::assertSame(200, $ordersListResponse->getStatusCode());

        /** @var array{items: list<array{id: string, status: string}>, total: int, page: int, limit: int} $ordersListData */
        $ordersListData = json_decode((string) $ordersListResponse->getContent(), true);
        self::assertGreaterThanOrEqual(1, $ordersListData['total']);
        $orderIds = array_column($ordersListData['items'], 'id');
        self::assertContains($orderId, $orderIds);
    }

    public function testDataIsolationBetweenBuyers(): void
    {
        // 1. Покупатель 1 создает и наполняет свой черновик
        $csrfToken1 = $this->login('buyer1', 'password');

        $this->client->request(
            'POST',
            '/api/v1/orders',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-CSRF-TOKEN' => $csrfToken1,
            ],
        );
        /** @var array{id: string} $order1Data */
        $order1Data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $order1Id = $order1Data['id'];

        $this->client->request(
            'PUT',
            "/api/v1/orders/{$order1Id}/items",
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
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        // 2. Создаем независимого клиента (второй браузер / отдельный cookie jar) для покупателя 2
        $client2 = new HttpKernelBrowser($this->kernel);
        $csrfToken2 = $this->loginWithClient($client2, 'buyer2', 'password');

        // 3. Покупатель 2 пытается прочитать заказ покупателя 1 -> HTTP 404 (BR-09, A-11)
        $client2->request('GET', "/api/v1/orders/{$order1Id}");
        self::assertSame(404, $client2->getResponse()->getStatusCode());

        /** @var array{text: string, error: string} $notFoundData */
        $notFoundData = json_decode((string) $client2->getResponse()->getContent(), true);
        self::assertSame('order_not_found', $notFoundData['error']);

        // 4. Покупатель 2 пытается модифицировать позиции заказа покупателя 1 -> HTTP 404
        $client2->request(
            'PUT',
            "/api/v1/orders/{$order1Id}/items",
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-CSRF-TOKEN' => $csrfToken2,
            ],
            content: json_encode([
                'items' => [
                    ['productId' => 'prod-monitor', 'quantity' => 1],
                ],
            ]),
        );
        self::assertSame(404, $client2->getResponse()->getStatusCode());

        // 5. Покупатель 2 запрашивает список своих заказов -> заказ покупателя 1 там отсутствует
        $client2->request('GET', '/api/v1/orders');
        self::assertSame(200, $client2->getResponse()->getStatusCode());
        /** @var array{items: list<array{id: string}>, total: int} $buyer2Orders */
        $buyer2Orders = json_decode((string) $client2->getResponse()->getContent(), true);
        $buyer2OrderIds = array_column($buyer2Orders['items'], 'id');
        self::assertNotContains($order1Id, $buyer2OrderIds);
    }

    public function testDraftCreationIdempotencyAndSingleDraftInvariant(): void
    {
        $csrfToken = $this->login('buyer1', 'password');

        // Первое создание с ключом идемпотентности
        $this->client->request(
            'POST',
            '/api/v1/orders',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-CSRF-TOKEN' => $csrfToken,
                'HTTP_IDEMPOTENCY-KEY' => 'key-idem-unique-1',
            ],
            content: json_encode(['note' => 'payload-a']),
        );
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        /** @var array{id: string} $firstCreateData */
        $firstCreateData = json_decode((string) $this->client->getResponse()->getContent(), true);
        $orderId = $firstCreateData['id'];

        // Повторный запрос с тем же Idempotency-Key и тем же телом возвращает тот же результат
        $this->client->request(
            'POST',
            '/api/v1/orders',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-CSRF-TOKEN' => $csrfToken,
                'HTTP_IDEMPOTENCY-KEY' => 'key-idem-unique-1',
            ],
            content: json_encode(['note' => 'payload-a']),
        );
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        /** @var array{id: string} $repeatSameData */
        $repeatSameData = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame($orderId, $repeatSameData['id']);

        // Повторный запрос с тем же Idempotency-Key, но другим телом возвращает HTTP 409
        $this->client->request(
            'POST',
            '/api/v1/orders',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-CSRF-TOKEN' => $csrfToken,
                'HTTP_IDEMPOTENCY-KEY' => 'key-idem-unique-1',
            ],
            content: json_encode(['note' => 'payload-different']),
        );
        self::assertSame(409, $this->client->getResponse()->getStatusCode());
        /** @var array{error: string} $conflictData */
        $conflictData = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('idempotency_conflict', $conflictData['error']);

        // Запрос без Idempotency-Key возвращает существующий активный черновик покупателя (BR-16)
        $this->client->request(
            'POST',
            '/api/v1/orders',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-CSRF-TOKEN' => $csrfToken,
            ],
        );
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        /** @var array{id: string} $existingDraftData */
        $existingDraftData = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame($orderId, $existingDraftData['id']);
    }

    public function testValidationAndSecurityFailures(): void
    {
        // 1. Анонимный доступ к закрытым эндпоинтам заказа
        $this->client->request('GET', '/api/v1/orders');
        self::assertSame(401, $this->client->getResponse()->getStatusCode());

        $this->client->request('POST', '/api/v1/orders');
        self::assertSame(403, $this->client->getResponse()->getStatusCode());

        // 2. Доступ покупателя без CSRF-токена
        $csrfToken = $this->login('buyer1', 'password');

        $this->client->request(
            'POST',
            '/api/v1/orders',
            server: ['CONTENT_TYPE' => 'application/json'],
        );
        self::assertSame(403, $this->client->getResponse()->getStatusCode());

        // Создаем черновик для проверки валидации позиций
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

        // 3. Замена позиций с пустым массивом items -> HTTP 422
        $this->client->request(
            'PUT',
            "/api/v1/orders/{$orderId}/items",
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-CSRF-TOKEN' => $csrfToken,
            ],
            content: json_encode(['items' => []]),
        );
        self::assertSame(422, $this->client->getResponse()->getStatusCode());

        // 4. Замена позиций с отрицательным количеством -> HTTP 422
        $this->client->request(
            'PUT',
            "/api/v1/orders/{$orderId}/items",
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-CSRF-TOKEN' => $csrfToken,
            ],
            content: json_encode([
                'items' => [
                    ['productId' => 'prod-keyboard', 'quantity' => -1],
                ],
            ]),
        );
        self::assertSame(422, $this->client->getResponse()->getStatusCode());

        // 5. Некорректный синтаксис JSON -> HTTP 400
        $this->client->request(
            'PUT',
            "/api/v1/orders/{$orderId}/items",
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-CSRF-TOKEN' => $csrfToken,
            ],
            content: '{invalid-json',
        );
        self::assertSame(400, $this->client->getResponse()->getStatusCode());
    }

    private function login(string $username, string $password): string
    {
        return $this->loginWithClient($this->client, $username, $password);
    }

    private function loginWithClient(HttpKernelBrowser $client, string $username, string $password): string
    {
        $client->request('GET', '/api/v1/auth/session');
        /** @var array{csrfToken: string} $sessionData */
        $sessionData = json_decode((string) $client->getResponse()->getContent(), true);
        $csrfToken = $sessionData['csrfToken'];

        $client->request(
            'POST',
            '/api/v1/auth/login',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-CSRF-TOKEN' => $csrfToken,
            ],
            content: json_encode(['username' => $username, 'password' => $password]),
        );

        self::assertSame(200, $client->getResponse()->getStatusCode());

        $client->request('GET', '/api/v1/auth/session');
        /** @var array{csrfToken: string} $authSessionData */
        $authSessionData = json_decode((string) $client->getResponse()->getContent(), true);

        return $authSessionData['csrfToken'];
    }
}

