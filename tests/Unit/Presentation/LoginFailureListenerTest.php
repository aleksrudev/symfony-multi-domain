<?php

declare(strict_types=1);

namespace Tests\Unit\Presentation;

use App\Shared\Presentation\Http\LoginFailureListener;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;

final class LoginFailureListenerTest extends TestCase
{
    public function testConvertsTooManyLoginAttemptsTo429(): void
    {
        $listener = new LoginFailureListener();
        $exception = new TooManyLoginAttemptsAuthenticationException(1);
        $authenticator = $this->createStub(AuthenticatorInterface::class);
        $request = Request::create('/api/v1/auth/login', 'POST');

        $event = new LoginFailureEvent($exception, $authenticator, $request, null, 'main');
        $listener->onLoginFailure($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(Response::HTTP_TOO_MANY_REQUESTS, $response->getStatusCode());
        /** @var array{text: string, error: string} $data */
        $data = json_decode((string) $response->getContent(), true);
        self::assertSame('too_many_login_attempts', $data['error']);
    }

    public function testIgnoresOtherAuthenticationExceptions(): void
    {
        $listener = new LoginFailureListener();
        $exception = new BadCredentialsException();
        $authenticator = $this->createStub(AuthenticatorInterface::class);
        $request = Request::create('/api/v1/auth/login', 'POST');

        $event = new LoginFailureEvent($exception, $authenticator, $request, null, 'main');
        $listener->onLoginFailure($event);

        self::assertNull($event->getResponse());
    }
}

