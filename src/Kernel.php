<?php

declare(strict_types=1);

namespace App;

use App\Shared\Infrastructure\Schedule\ScheduleContributorInterface;
use App\Shared\Infrastructure\Symfony\DependencyInjection\DomainErrorMappingPass;
use App\Shared\Infrastructure\Symfony\DependencyInjection\IntegrationEventPass;
use App\Shared\Presentation\Http\Error\DomainErrorProviderInterface;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    /** @return array<string, string> Module name => absolute directory. */
    protected function moduleDirectories(): array
    {
        return [];
    }

    protected function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->registerForAutoconfiguration(DomainErrorProviderInterface::class)->addTag('app.domain_error_provider');
        $container->registerForAutoconfiguration(ScheduleContributorInterface::class)->addTag('app.schedule_contributor');
        $container->addCompilerPass(new DomainErrorMappingPass(), PassConfig::TYPE_BEFORE_REMOVING);
        IntegrationEventPass::register($container);
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $config = $this->getProjectDir().'/config';
        $container->import($config.'/{packages}/*.yaml');
        $container->import($config.'/{packages}/'.$this->environment.'/*.yaml');
        $container->import($config.'/services.yaml');
        $container->import($config.'/{services}_'.$this->environment.'.yaml');

        foreach ($this->configurationDirectories() as $directory) {
            $container->import($directory.'/{packages}/*.yaml');
            if (is_file($directory.'/services.yaml')) {
                $container->import($directory.'/services.yaml');
            }
        }
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $config = $this->getProjectDir().'/config';
        $routes->import($config.'/{routes}/'.$this->environment.'/*.yaml');
        $routes->import($config.'/{routes}/*.yaml');
        $routes->import($config.'/routes.yaml');

        foreach ($this->configurationDirectories() as $directory) {
            if (is_file($directory.'/routes.yaml')) {
                $routes->import($directory.'/routes.yaml');
            }
        }
    }

    /** @return list<string> */
    private function configurationDirectories(): array
    {
        $directories = [$this->getProjectDir().'/src/Shared/config'];
        foreach ($this->moduleDirectories() as $name => $directory) {
            if ('Shared' === $name || !preg_match('/^[A-Z][A-Za-z0-9]*$/D', $name) || !is_dir($directory.'/config')) {
                throw new \LogicException(sprintf('Invalid module "%s": expected a module directory with config/.', $name));
            }
            $directories[] = $directory.'/config';
        }

        return $directories;
    }
}
