<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Bus\Symfony;

use App\Shared\Application\Bus\Query\QueryBusInterface;
use App\Shared\Application\Bus\Query\QueryInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsAlias(QueryBusInterface::class)]
class QueryMessageBus implements QueryBusInterface
{
    use HandleTrait;

    public function __construct(MessageBusInterface $queryBus)
    {
        $this->messageBus = $queryBus;
    }

    public function dispatch(QueryInterface $query): mixed
    {
        try {
            return $this->handle($query);
        } catch (HandlerFailedException $exception) {
            if ($exception->getPrevious()) {
                throw $exception->getPrevious();
            }
            throw $exception;
        }
    }
}
