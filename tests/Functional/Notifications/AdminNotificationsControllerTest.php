<?php

declare(strict_types=1);

namespace Tests\Functional\Notifications;

use App\Kernel;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\HttpKernelBrowser;

final class AdminNotificationsControllerTest extends TestCase
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
        $conn->executeStatement("DELETE FROM notifications.notifications WHERE order_id LIKE '00000000-0000-4000-7700-%'");
    }

    protected function tearDown(): void
    {
        $this->kernel->shutdown();
    }

    public function testGetNotificationsRequiresAdminRole(): void
    {
        // 1. Anonymous -> 401
        $this->client->request('GET', '/api/v1/admin/notifications');
        self::assertSame(401, $this->client->getResponse()->getStatusCode());

        // 2. Buyer -> 403
        $this->login('buyer1', 'password');
        $this->client->request('GET', '/api/v1/admin/notifications');
        self::assertSame(403, $this->client->getResponse()->getStatusCode());

        // 3. Admin -> 200
        $this->login('admin', 'password');
        $this->client->request('GET', '/api/v1/admin/notifications?page=1&limit=5');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        /** @var array{items: list<mixed>, page: int, limit: int, total: int} $data */
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame(1, $data['page']);
        self::assertSame(5, $data['limit']);
        self::assertSame(0, $data['total']);
        self::assertEmpty($data['items']);
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
                'HTTP_X-CSRF-TOKEN' => $csrfToken,
            ],
            content: json_encode(['username' => $username, 'password' => $password]),
        );

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }
}
