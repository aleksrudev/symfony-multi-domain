<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure;

use App\Shared\Domain\Exception\DomainException;
use App\Shared\Infrastructure\Symfony\DependencyInjection\DomainErrorMappingPass;
use App\Shared\Presentation\Http\Error\DomainErrorProviderInterface;
use App\Shared\Presentation\Http\Error\DomainErrorRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class DomainErrorMappingPassTest extends TestCase
{
    public function testDuplicateMappingsFailDuringCompilation(): void
    {
        $container = new ContainerBuilder();
        $container->register(DomainErrorRegistry::class);
        $container->register('one', ErrorProvider::class)->addTag('app.domain_error_provider');
        $container->register('two', ErrorProvider::class)->addTag('app.domain_error_provider');
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Duplicate');
        new DomainErrorMappingPass()->process($container);
    }

    public function testInvalidStatusTypeIsRejectedBeforeDumpingContainer(): void
    {
        $container = new ContainerBuilder();
        $container->register(DomainErrorRegistry::class);
        $container->register('bad', MalformedErrorProvider::class)->addTag('app.domain_error_provider');
        $this->expectException(\LogicException::class);
        new DomainErrorMappingPass()->process($container);
    }
}

final class ErrorProvider implements DomainErrorProviderInterface
{
    public static function getErrors(): array
    {
        return [DomainException::class => ['error' => 'probe', 'status' => 409]];
    }
}

final class MalformedErrorProvider implements DomainErrorProviderInterface
{
    public static function getErrors(): array
    {
        return json_decode(json_encode([DomainException::class => ['error' => 'probe', 'status' => '409']], JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
    }
}
