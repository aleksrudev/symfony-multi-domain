<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Symfony\DependencyInjection;

use App\Shared\Domain\Exception\DomainException;
use App\Shared\Presentation\Http\Error\DomainErrorProviderInterface;
use App\Shared\Presentation\Http\Error\DomainErrorRegistry;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class DomainErrorMappingPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(DomainErrorRegistry::class)) {
            return;
        }
        $errors = [];
        foreach ($container->findTaggedServiceIds('app.domain_error_provider') as $id => $tags) {
            $class = $container->getDefinition($id)->getClass();
            if (null === $class || !is_subclass_of($class, DomainErrorProviderInterface::class)) {
                throw new \LogicException(sprintf('Error provider "%s" must implement %s.', $id, DomainErrorProviderInterface::class));
            }
            foreach ($class::getErrors() as $exception => $error) {
                if (isset($errors[$exception])) {
                    throw new \LogicException(sprintf('Duplicate domain error mapping for "%s".', $exception));
                }
                if (!is_a($exception, DomainException::class, true) || !is_array($error) || !isset($error['error'], $error['status']) || !is_string($error['error']) || '' === $error['error'] || !is_int($error['status']) || $error['status'] < 400 || $error['status'] > 599) {
                    throw new \LogicException(sprintf('Invalid domain error mapping for "%s".', $exception));
                }
                $errors[$exception] = $error;
            }
        }
        $container->getDefinition(DomainErrorRegistry::class)->setArgument(0, $errors);
    }
}
