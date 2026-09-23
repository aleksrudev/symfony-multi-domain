<?php

declare(strict_types=1);

namespace App\Shared\Presentation\Http;

use App\Shared\Domain\Exception\DomainException;
use App\Shared\Presentation\Http\Error\DomainErrorRegistry;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\Messenger\Exception\HandlerFailedException;

final readonly class ExceptionListener
{
    public function __construct(private DomainErrorRegistry $registry)
    {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        if (!preg_match('{^/api(?:/|$)}', $event->getRequest()->getPathInfo())) {
            return;
        }
        $exception = $event->getThrowable();
        while ($exception instanceof HandlerFailedException && $exception->getPrevious()) {
            $exception = $exception->getPrevious();
        }
        if (!$exception instanceof DomainException || null === ($error = $this->registry->find($exception))) {
            return;
        }
        $event->setResponse(new JsonResponse(['text' => $exception->getMessage(), 'error' => $error['error']], $error['status']));
    }
}
