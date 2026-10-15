<?php

declare(strict_types=1);

namespace Tests\Unit\Presentation;

use App\Shared\Presentation\Http\CsrfProtectionListener;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class CsrfProtectionListenerTest extends TestCase
{
    public function testIgnoresSubRequests(): void
    {
        $tokenManager = $this->createMock(CsrfTokenManagerInterface::class);
        $tokenManager->expects(self::never())->method('isTokenValid');

        $listener = new CsrfProtectionListener($tokenManager);
        $kernel = $this->createStub(HttpKernelInterface::class);
        $request = Request::create('/api/v1/orders', 'POST');

        $event = new RequestEvent($kernel, $request, HttpKernelInterface::SUB_REQUEST);
        $listener($event);

        self::assertNull($event->getResponse());
    }

    public function testIgnoresNonApiRoutes(): void
    {
        $tokenManager = $this->createMock(CsrfTokenManagerInterface::class);
        $tokenManager->expects(self::never())->method('isTokenValid');

        $listener = new CsrfProtectionListener($tokenManager);
        $kernel = $this->createStub(HttpKernelInterface::class);
        $request = Request::create('/other/route', 'POST');

        $event = new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);
        $listener($event);

        self::assertNull($event->getResponse());
    }

    public function testAllowsSafeMethodsWithoutCsrf(): void
    {
        $tokenManager = $this->createMock(CsrfTokenManagerInterface::class);
        $tokenManager->expects(self::never())->method('isTokenValid');

        $listener = new CsrfProtectionListener($tokenManager);
        $kernel = $this->createStub(HttpKernelInterface::class);

        foreach (['GET', 'HEAD', 'OPTIONS'] as $method) {
            $request = Request::create('/api/v1/products', $method);
            $event = new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);
            $listener($event);

            self::assertNull($event->getResponse(), sprintf('Method %s should be allowed without CSRF', $method));
        }
    }

    public function testBlocksMutatingMethodWithoutSession(): void
    {
        $tokenManager = $this->createStub(CsrfTokenManagerInterface::class);
        $listener = new CsrfProtectionListener($tokenManager);
        $kernel = $this->createStub(HttpKernelInterface::class);
        $request = Request::create('/api/v1/orders', 'POST');

        $event = new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);
        $listener($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        self::assertJsonStringEqualsJsonString(
            json_encode(['text' => 'Invalid or missing CSRF token.', 'error' => 'invalid_csrf_token']),
            (string) $response->getContent(),
        );
    }

    public function testBlocksMutatingMethodWithoutCsrfHeader(): void
    {
        $tokenManager = $this->createStub(CsrfTokenManagerInterface::class);
        $listener = new CsrfProtectionListener($tokenManager);
        $kernel = $this->createStub(HttpKernelInterface::class);
        $request = Request::create('/api/v1/orders', 'POST');
        $request->setSession(new Session(new MockArraySessionStorage()));

        $event = new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);
        $listener($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    public function testBlocksMutatingMethodWithInvalidCsrfToken(): void
    {
        $tokenManager = $this->createMock(CsrfTokenManagerInterface::class);
        $tokenManager->expects(self::once())
            ->method('isTokenValid')
            ->with(new CsrfToken('api_csrf', 'invalid-token'))
            ->willReturn(false);

        $listener = new CsrfProtectionListener($tokenManager);
        $kernel = $this->createStub(HttpKernelInterface::class);
        $request = Request::create('/api/v1/orders', 'POST');
        $request->setSession(new Session(new MockArraySessionStorage()));
        $request->headers->set('X-CSRF-Token', 'invalid-token');

        $event = new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);
        $listener($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    public function testAllowsMutatingMethodWithValidCsrfToken(): void
    {
        $tokenManager = $this->createMock(CsrfTokenManagerInterface::class);
        $tokenManager->expects(self::once())
            ->method('isTokenValid')
            ->with(new CsrfToken('api_csrf', 'valid-token'))
            ->willReturn(true);

        $listener = new CsrfProtectionListener($tokenManager);
        $kernel = $this->createStub(HttpKernelInterface::class);
        $request = Request::create('/api/v1/orders', 'POST');
        $request->setSession(new Session(new MockArraySessionStorage()));
        $request->headers->set('X-CSRF-Token', 'valid-token');

        $event = new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);
        $listener($event);

        self::assertNull($event->getResponse());
    }
}
