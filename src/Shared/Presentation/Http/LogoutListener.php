<?php

declare(strict_types=1);

namespace App\Shared\Presentation\Http;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Security\Http\Event\LogoutEvent;

#[AsEventListener(event: LogoutEvent::class, method: 'onLogout', priority: 128)]
final readonly class LogoutListener
{
    public function onLogout(LogoutEvent $event): void
    {
        $path = $event->getRequest()->getPathInfo();
        if (!str_starts_with($path, '/api/v1')) {
            return;
        }

        $event->setResponse(new JsonResponse(['ok' => true]));
    }

    public function __invoke(LogoutEvent $event): void
    {
        $this->onLogout($event);
    }
}
