<?php

declare(strict_types=1);

namespace Tests\Unit\Presentation;

use App\Shared\Domain\Exception\DomainException;
use App\Shared\Presentation\Http\Error\DomainErrorRegistry;
use App\Shared\Presentation\Http\ExceptionListener;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;

final class ExceptionListenerTest extends TestCase
{
    public function testDirectAndWrappedErrorsHaveTheSameResponse(): void
    {
        $error = new DomainException('Unavailable');
        $listener = new ExceptionListener(new DomainErrorRegistry([
            DomainException::class => ['error' => 'unavailable', 'status' => 409],
        ]));
        foreach ([$error, new HandlerFailedException(new Envelope(new \stdClass()), [$error])] as $exception) {
            $event = new ExceptionEvent($this->createStub(HttpKernelInterface::class), Request::create('/api/v1/probe'), HttpKernelInterface::MAIN_REQUEST, $exception);
            $listener($event);
            self::assertSame(409, $event->getResponse()?->getStatusCode());
            self::assertSame(['text' => 'Unavailable', 'error' => 'unavailable'], json_decode($event->getResponse()->getContent(), true));
        }
    }

    public function testUnknownErrorsRemainUnhandled(): void
    {
        $listener = new ExceptionListener(new DomainErrorRegistry([]));
        $event = new ExceptionEvent($this->createStub(HttpKernelInterface::class), Request::create('/api/v1/probe'), HttpKernelInterface::MAIN_REQUEST, new \RuntimeException('private'));
        $listener($event);
        self::assertNull($event->getResponse());
    }

    public function testNonApiErrorsRemainUnhandled(): void
    {
        $listener = new ExceptionListener(new DomainErrorRegistry([DomainException::class => ['error' => 'conflict', 'status' => 409]]));
        $event = new ExceptionEvent($this->createStub(HttpKernelInterface::class), Request::create('/page'), HttpKernelInterface::MAIN_REQUEST, new DomainException('Conflict'));
        $listener($event);
        self::assertNull($event->getResponse());
    }
}
