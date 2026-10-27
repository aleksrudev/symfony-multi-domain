<?php

declare(strict_types=1);

namespace App\Shared\Presentation\Http;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;

#[AsEventListener(event: LoginFailureEvent::class, method: 'onLoginFailure')]
final readonly class LoginFailureListener
{
    public function onLoginFailure(LoginFailureEvent $event): void
    {
        if ($event->getException() instanceof TooManyLoginAttemptsAuthenticationException) {
            $event->setResponse(new JsonResponse([
                'text' => $event->getException()->getMessageKey(),
                'error' => 'too_many_login_attempts',
            ], Response::HTTP_TOO_MANY_REQUESTS));
        }
    }

    public function __invoke(LoginFailureEvent $event): void
    {
        $this->onLoginFailure($event);
    }
}
