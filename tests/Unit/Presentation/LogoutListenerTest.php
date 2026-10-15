<?php

declare(strict_types=1);

namespace Tests\Unit\Presentation;

use App\Shared\Presentation\Http\LogoutListener;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Http\Event\LogoutEvent;

final class LogoutListenerTest extends TestCase
{
    public function testSetsJsonResponseForApiLogout(): void
    {
        $listener = new LogoutListener();
        $request = Request::create('/api/v1/auth/logout', 'POST');
        $token = $this->createStub(TokenInterface::class);

        $event = new LogoutEvent($request, $token);
        $listener->onLogout($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertJsonStringEqualsJsonString(
            json_encode(['ok' => true]),
            (string) $response->getContent(),
        );
    }

    public function testIgnoresNonApiLogout(): void
    {
        $listener = new LogoutListener();
        $request = Request::create('/logout', 'POST');
        $token = $this->createStub(TokenInterface::class);

        $event = new LogoutEvent($request, $token);
        $listener->onLogout($event);

        self::assertNull($event->getResponse());
    }
}
