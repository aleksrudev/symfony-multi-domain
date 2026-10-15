<?php

declare(strict_types=1);

namespace App\Shared\Presentation\Http;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final readonly class AuthController
{
    public function __construct(
        private CsrfTokenManagerInterface $csrfTokenManager,
        private Security $security,
    ) {
    }

    public function session(Request $request): JsonResponse
    {
        $token = $this->csrfTokenManager->getToken('api_csrf')->getValue();
        $user = $this->security->getUser();

        return new JsonResponse([
            'authenticated' => null !== $user,
            'user' => $user?->getUserIdentifier(),
            'roles' => $user?->getRoles() ?? [],
            'csrfToken' => $token,
        ]);
    }

    public function login(#[CurrentUser] ?UserInterface $user): JsonResponse
    {
        if (null === $user) {
            return new JsonResponse(
                ['text' => 'Authentication failed.', 'error' => 'invalid_credentials'],
                Response::HTTP_UNAUTHORIZED,
            );
        }

        return new JsonResponse([
            'ok' => true,
            'user' => $user->getUserIdentifier(),
            'roles' => $user->getRoles(),
        ]);
    }
}

