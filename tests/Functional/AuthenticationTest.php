<?php

declare(strict_types=1);

namespace Tests\Functional;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\HttpKernelBrowser;
use Tests\Fixtures\ModuleKernel;

final class AuthenticationTest extends TestCase
{
    private ModuleKernel $kernel;
    private HttpKernelBrowser $client;

    protected function setUp(): void
    {
        $this->kernel = new ModuleKernel('test', true);
        $this->kernel->boot();
        $this->client = new HttpKernelBrowser($this->kernel);
    }

    protected function tearDown(): void
    {
        $this->kernel->shutdown();
    }

    public function testAnonymousShowcaseIsAccessible(): void
    {
        $this->client->request('GET', '/api/v1/products');
        $response = $this->client->getResponse();

        self::assertSame(200, $response->getStatusCode());
        self::assertJsonStringEqualsJsonString(
            json_encode(['items' => []]),
            (string) $response->getContent(),
        );
    }

    public function testProtectedEndpointRequiresAuthentication(): void
    {
        $this->client->request('GET', '/api/v1/probe');
        $response = $this->client->getResponse();

        self::assertSame(401, $response->getStatusCode());
    }

    public function testSessionEndpointReturnsCsrfAndAnonymousState(): void
    {
        $this->client->request('GET', '/api/v1/auth/session');
        $response = $this->client->getResponse();

        self::assertSame(200, $response->getStatusCode());
        /** @var array{authenticated: bool, user: ?string, roles: list<string>, csrfToken: string} $data */
        $data = json_decode((string) $response->getContent(), true);

        self::assertFalse($data['authenticated']);
        self::assertNull($data['user']);
        self::assertSame([], $data['roles']);
        self::assertNotEmpty($data['csrfToken']);
    }

    public function testLoginWithoutCsrfIsRejected(): void
    {
        $this->client->request(
            'POST',
            '/api/v1/auth/login',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['username' => 'buyer1', 'password' => 'password']),
        );
        $response = $this->client->getResponse();

        self::assertSame(403, $response->getStatusCode());
        /** @var array{text: string, error: string} $data */
        $data = json_decode((string) $response->getContent(), true);
        self::assertSame('invalid_csrf_token', $data['error']);
    }

    public function testLoginWithInvalidCsrfIsRejected(): void
    {
        $this->client->request('GET', '/api/v1/auth/session');

        $this->client->request(
            'POST',
            '/api/v1/auth/login',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-CSRF-TOKEN' => 'invalid-token',
            ],
            content: json_encode(['username' => 'buyer1', 'password' => 'password']),
        );
        $response = $this->client->getResponse();

        self::assertSame(403, $response->getStatusCode());
    }

    public function testLoginWithWrongCredentialsReturns401(): void
    {
        $this->client->request('GET', '/api/v1/auth/session');
        /** @var array{csrfToken: string} $sessionData */
        $sessionData = json_decode((string) $this->client->getResponse()->getContent(), true);

        $this->client->request(
            'POST',
            '/api/v1/auth/login',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-CSRF-TOKEN' => $sessionData['csrfToken'],
            ],
            content: json_encode(['username' => 'buyer1', 'password' => 'wrong-password']),
        );
        $response = $this->client->getResponse();

        self::assertSame(401, $response->getStatusCode());
    }

    public function testBuyerCanLoginAndAccessProtectedResource(): void
    {
        $this->client->request('GET', '/api/v1/auth/session');
        /** @var array{csrfToken: string} $sessionData */
        $sessionData = json_decode((string) $this->client->getResponse()->getContent(), true);

        $this->client->request(
            'POST',
            '/api/v1/auth/login',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-CSRF-TOKEN' => $sessionData['csrfToken'],
            ],
            content: json_encode(['username' => 'buyer1', 'password' => 'password']),
        );
        $loginResponse = $this->client->getResponse();

        self::assertSame(200, $loginResponse->getStatusCode());
        /** @var array{ok: bool, user: string, roles: list<string>} $loginData */
        $loginData = json_decode((string) $loginResponse->getContent(), true);
        self::assertTrue($loginData['ok']);
        self::assertSame('buyer1', $loginData['user']);
        self::assertContains('ROLE_BUYER', $loginData['roles']);

        // Check session state after login
        $this->client->request('GET', '/api/v1/auth/session');
        /** @var array{authenticated: bool, user: string, roles: list<string>, csrfToken: string} $stateData */
        $stateData = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertTrue($stateData['authenticated']);
        self::assertSame('buyer1', $stateData['user']);
        self::assertContains('ROLE_BUYER', $stateData['roles']);

        // Access protected resource
        $this->client->request('GET', '/api/v1/probe');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    public function testBuyerCannotAccessAdminEndpoint(): void
    {
        $this->client->request('GET', '/api/v1/auth/session');
        /** @var array{csrfToken: string} $sessionData */
        $sessionData = json_decode((string) $this->client->getResponse()->getContent(), true);

        $this->client->request(
            'POST',
            '/api/v1/auth/login',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-CSRF-TOKEN' => $sessionData['csrfToken'],
            ],
            content: json_encode(['username' => 'buyer1', 'password' => 'password']),
        );
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->client->request('GET', '/api/v1/admin/probe');
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testAdminCanAccessAdminEndpoint(): void
    {
        $this->client->request('GET', '/api/v1/auth/session');
        /** @var array{csrfToken: string} $sessionData */
        $sessionData = json_decode((string) $this->client->getResponse()->getContent(), true);

        $this->client->request(
            'POST',
            '/api/v1/auth/login',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-CSRF-TOKEN' => $sessionData['csrfToken'],
            ],
            content: json_encode(['username' => 'admin', 'password' => 'password']),
        );
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->client->request('GET', '/api/v1/admin/probe');
        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        self::assertJsonStringEqualsJsonString(
            json_encode(['admin' => true]),
            (string) $response->getContent(),
        );
    }

    public function testLogoutRequiresCsrfAndInvalidatesSession(): void
    {
        // 1. Login
        $this->client->request('GET', '/api/v1/auth/session');
        /** @var array{csrfToken: string} $sessionData */
        $sessionData = json_decode((string) $this->client->getResponse()->getContent(), true);

        $this->client->request(
            'POST',
            '/api/v1/auth/login',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-CSRF-TOKEN' => $sessionData['csrfToken'],
            ],
            content: json_encode(['username' => 'buyer1', 'password' => 'password']),
        );
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        // 2. Logout without CSRF -> 403
        $this->client->request('POST', '/api/v1/auth/logout');
        self::assertSame(403, $this->client->getResponse()->getStatusCode());

        // Still authenticated
        $this->client->request('GET', '/api/v1/probe');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        // Refresh CSRF token for the authenticated session
        $this->client->request('GET', '/api/v1/auth/session');
        /** @var array{csrfToken: string} $authSessionData */
        $authSessionData = json_decode((string) $this->client->getResponse()->getContent(), true);

        // 3. Logout with CSRF -> 200
        $this->client->request(
            'POST',
            '/api/v1/auth/logout',
            server: ['HTTP_X-CSRF-TOKEN' => $authSessionData['csrfToken']],
        );
        $logoutResponse = $this->client->getResponse();
        self::assertSame(200, $logoutResponse->getStatusCode());
        self::assertJsonStringEqualsJsonString(
            json_encode(['ok' => true]),
            (string) $logoutResponse->getContent(),
        );

        // 4. Access protected resource -> 401
        $this->client->request('GET', '/api/v1/probe');
        self::assertSame(401, $this->client->getResponse()->getStatusCode());

        // 5. Check session state -> unauthenticated
        $this->client->request('GET', '/api/v1/auth/session');
        /** @var array{authenticated: bool, user: ?string} $finalData */
        $finalData = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertFalse($finalData['authenticated']);
        self::assertNull($finalData['user']);
    }

    public function testLoginThrottlingBlocksExcessiveFailedAttempts(): void
    {
        $this->client->request('GET', '/api/v1/auth/session');
        /** @var array{csrfToken: string} $sessionData */
        $sessionData = json_decode((string) $this->client->getResponse()->getContent(), true);
        $csrfToken = $sessionData['csrfToken'];

        $lastStatus = 200;
        // Attempt login up to 7 times with wrong password (max_attempts is 5)
        for ($i = 0; $i < 7; ++$i) {
            $this->client->request(
                'POST',
                '/api/v1/auth/login',
                server: [
                    'CONTENT_TYPE' => 'application/json',
                    'HTTP_X-CSRF-TOKEN' => $csrfToken,
                ],
                content: json_encode(['username' => 'buyer2', 'password' => 'wrong']),
            );
            $lastStatus = $this->client->getResponse()->getStatusCode();
            if (429 === $lastStatus) {
                break;
            }
        }

        self::assertSame(429, $lastStatus);
    }
}

