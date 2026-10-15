<?php

declare(strict_types=1);

namespace App\Shared\Presentation\Http;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final readonly class CsrfProtectionListener
{
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function __construct(
        private CsrfTokenManagerInterface $csrfTokenManager,
        private string $tokenId = 'api_csrf',
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $path = $request->getPathInfo();

        if (!str_starts_with($path, '/api/v1')) {
            return;
        }

        if (in_array($request->getMethod(), self::SAFE_METHODS, true)) {
            return;
        }

        if (!$request->hasSession()) {
            $event->setResponse(new JsonResponse(
                ['text' => 'Invalid or missing CSRF token.', 'error' => 'invalid_csrf_token'],
                Response::HTTP_FORBIDDEN,
            ));

            return;
        }

        $token = $request->headers->get('X-CSRF-Token');
        if (null === $token || '' === $token || !$this->csrfTokenManager->isTokenValid(new CsrfToken($this->tokenId, $token))) {
            $event->setResponse(new JsonResponse(
                ['text' => 'Invalid or missing CSRF token.', 'error' => 'invalid_csrf_token'],
                Response::HTTP_FORBIDDEN,
            ));
        }
    }
}
