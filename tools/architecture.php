<?php

declare(strict_types=1);

/**
 * Builds separate layers per module, including modules not enabled in Kernel.
 * Synchronous contracts and event subscriptions have separate dependency maps.
 *
 * @return Closure(string, array<string, mixed>): array<string, mixed>
 */
return static function (string $source, array $dependencies = []): array {
    $class = static fn (string $pattern): array => ['type' => 'classLike', 'value' => $pattern];
    $internal = ['type' => 'php_internal', 'value' => '.*'];
    $mapping = $class('^Doctrine\\\\ORM\\\\Mapping\\\\');
    $doctrineCollections = $class('^Doctrine\\\\Common\\\\Collections\\\\');
    $collectionBase = $class('^App\\\\Shared\\\\Domain\\\\Collection\\\\AbstractCollection$');
    $uid = $class('^Symfony\\\\Component\\\\Uid\\\\');
    $uidBase = $class('^App\\\\Shared\\\\Domain\\\\ValueObject\\\\(?:UuidId|UlidId)$');
    $validation = $class('^Symfony\\\\Component\\\\Validator\\\\Constraints\\\\');
    $handlerAttributes = $class('^Symfony\\\\Component\\\\(?:Messenger\\\\Attribute\\\\AsMessageHandler|EventDispatcher\\\\Attribute\\\\AsEventListener)$');
    $integrationEvent = $class('^App\\\\Shared\\\\Presentation\\\\Contracts\\\\Event\\\\IntegrationEventInterface$');
    $layers = [
        ['name' => 'PHP', 'collectors' => [$internal]],
        ['name' => 'ORMMapping', 'collectors' => [$mapping]],
        ['name' => 'DoctrineCollections', 'collectors' => [$doctrineCollections]],
        ['name' => 'CollectionBase', 'collectors' => [$collectionBase]],
        ['name' => 'SymfonyUid', 'collectors' => [$uid]],
        ['name' => 'UidBase', 'collectors' => [$uidBase]],
        ['name' => 'Validation', 'collectors' => [$validation]],
        ['name' => 'HandlerAttributes', 'collectors' => [$handlerAttributes]],
        ['name' => 'IntegrationEventContract', 'collectors' => [$integrationEvent]],
        ['name' => 'Vendor', 'collectors' => [['type' => 'bool', 'must' => [$class('^(?!App\\\\).*')], 'must_not' => [$internal, $mapping, $validation, $doctrineCollections, $uid, $handlerAttributes]]]],
        ['name' => 'Kernel', 'collectors' => [$class('^App\\\\Kernel$')]],
    ];
    $rules = ['PHP' => [], 'ORMMapping' => [], 'DoctrineCollections' => [], 'CollectionBase' => ['PHP', 'DoctrineCollections'], 'SymfonyUid' => [], 'UidBase' => ['PHP', 'SymfonyUid'], 'Validation' => [], 'HandlerAttributes' => [], 'IntegrationEventContract' => ['PHP'], 'Vendor' => [], 'Kernel' => ['PHP', 'Vendor', 'DoctrineCollections', 'CollectionBase', 'SymfonyUid', 'UidBase', 'HandlerAttributes', 'IntegrationEventContract']];
    $modules = ['Shared'];
    foreach (glob($source.'/*', GLOB_ONLYDIR) ?: [] as $directory) {
        $name = basename($directory);
        if ('Shared' !== $name && preg_match('/^[A-Z][A-Za-z0-9]*$/D', $name)) {
            $modules[] = $name;
        }
    }
    /** @var array<string, array{sync: list<string>, events: list<string>}> $graph */
    $graph = [];
    foreach ($dependencies as $consumer => $channels) {
        if ('Shared' === $consumer || !in_array($consumer, $modules, true)) {
            throw new LogicException('Unknown or forbidden dependency consumer: '.$consumer);
        }
        if (!is_array($channels) || !array_key_exists('sync', $channels) || !array_key_exists('events', $channels)) {
            throw new LogicException('Module '.$consumer.' dependencies must define sync and events.');
        }
        if ([] !== array_diff(array_keys($channels), ['sync', 'events'])) {
            throw new LogicException('Unknown dependency channels for module '.$consumer.'.');
        }
        $graph[$consumer] = ['sync' => [], 'events' => []];
        foreach (['sync', 'events'] as $channel) {
            $providers = $channels[$channel];
            if (!is_array($providers) || !array_is_list($providers)) {
                throw new LogicException('Module '.$consumer.' dependency channel '.$channel.' must be a list.');
            }
            foreach ($providers as $provider) {
                if (!is_string($provider) || $consumer === $provider || 'Shared' === $provider || !in_array($provider, $modules, true)) {
                    throw new LogicException('Unknown or forbidden dependency provider for '.$consumer.'.'.$channel.': '.(is_string($provider) ? $provider : get_debug_type($provider)));
                }
                $graph[$consumer][$channel][] = $provider;
            }
        }
    }
    $visit = static function (string $module, array $path = []) use (&$visit, $graph): void {
        if (in_array($module, $path, true)) {
            throw new LogicException('Cyclic synchronous module dependency: '.implode(' -> ', [...$path, $module]));
        }
        foreach ($graph[$module]['sync'] ?? [] as $provider) {
            $visit($provider, [...$path, $module]);
        }
    };
    foreach ($modules as $module) {
        $visit($module);
        $prefix = '^App\\\\'.preg_quote($module).'\\\\';
        $collectors = [
            'Repository' => [$class($prefix.'Domain\\\\Repository\\\\')],
            'Collection' => [['type' => 'bool', 'must' => [$class($prefix.'Domain\\\\Collection\\\\')], 'must_not' => [$collectionBase]]],
            'Domain' => [['type' => 'bool', 'must' => [$class($prefix.'Domain\\\\')], 'must_not' => [$class($prefix.'Domain\\\\Repository\\\\'), $class($prefix.'Domain\\\\Collection\\\\'), $uidBase]]],
            'Application' => [$class($prefix.'Application\\\\')],
            'Contracts' => [['type' => 'bool', 'must' => [$class($prefix.'Presentation\\\\Contracts\\\\')], 'must_not' => [$integrationEvent, $class($prefix.'Presentation\\\\Contracts\\\\Event\\\\'), $class($prefix.'Presentation\\\\Contracts\\\\Dto\\\\')]]],
            'ContractEvent' => [['type' => 'bool', 'must' => [$class($prefix.'Presentation\\\\Contracts\\\\Event\\\\')], 'must_not' => [$integrationEvent]]],
            'ContractDto' => [$class($prefix.'Presentation\\\\Contracts\\\\Dto\\\\')],
            'Presentation' => [['type' => 'bool', 'must' => [$class($prefix.'Presentation\\\\')], 'must_not' => [$class($prefix.'Presentation\\\\Contracts\\\\')]]],
            'Integration' => [$class($prefix.'Infrastructure\\\\Integration\\\\')],
            'Wiring' => [$class($prefix.'Infrastructure\\\\Symfony\\\\DependencyInjection\\\\')],
            'Infrastructure' => [['type' => 'bool', 'must' => [$class($prefix.'Infrastructure\\\\')], 'must_not' => [$class($prefix.'Infrastructure\\\\Integration\\\\'), $class($prefix.'Infrastructure\\\\Symfony\\\\DependencyInjection\\\\')]]],
        ];
        foreach ($collectors as $layer => $selection) {
            $layers[] = ['name' => $module.'.'.$layer, 'collectors' => $selection];
            $rules[$module.'.'.$layer] = ['PHP'];
            $rules['Kernel'][] = $module.'.'.$layer;
        }
        $rules[$module.'.Domain'] = ['PHP', 'ORMMapping', $module.'.Repository', $module.'.Collection'];
        $rules[$module.'.Collection'] = ['PHP', 'CollectionBase', $module.'.Domain'];
        $rules[$module.'.Repository'] = ['PHP', $module.'.Domain', $module.'.Collection'];
        $contracts = [$module.'.Contracts', $module.'.ContractEvent', $module.'.ContractDto'];
        $rules[$module.'.Contracts'][] = $module.'.ContractDto';
        $rules[$module.'.ContractEvent'] = ['PHP', 'IntegrationEventContract', $module.'.ContractDto'];
        $rules[$module.'.Application'] = ['PHP', 'Validation', 'HandlerAttributes', $module.'.Domain', $module.'.Repository', $module.'.Collection'];
        $rules[$module.'.Presentation'] = ['PHP', 'Vendor', 'DoctrineCollections', 'SymfonyUid', 'Validation', 'HandlerAttributes', 'IntegrationEventContract', $module.'.Application', $module.'.Domain', $module.'.Collection', ...$contracts];
        foreach (['Infrastructure', 'Integration', 'Wiring'] as $layer) {
            $rules[$module.'.'.$layer] = ['PHP', 'Vendor', 'DoctrineCollections', 'SymfonyUid', 'ORMMapping', 'Validation', 'HandlerAttributes', 'IntegrationEventContract', $module.'.Domain', $module.'.Repository', $module.'.Collection', $module.'.Application', ...$contracts];
        }
        $rules[$module.'.Integration'][] = $module.'.Infrastructure';
        $rules[$module.'.Wiring'] = array_merge($rules[$module.'.Wiring'], [$module.'.Infrastructure', $module.'.Integration', $module.'.Presentation']);
        foreach (['Domain', 'Collection', 'Repository', 'Application', 'Presentation', 'Infrastructure', 'Integration', 'Wiring'] as $layer) {
            $rules[$module.'.'.$layer][] = 'UidBase';
        }
        if ('Shared' !== $module) {
            foreach (['Domain', 'Collection', 'Repository', 'Application', 'Presentation', 'Infrastructure', 'Integration', 'Wiring'] as $layer) {
                $rules[$module.'.'.$layer][] = 'Shared.Domain';
            }
            foreach (['Application', 'Presentation', 'Infrastructure', 'Integration', 'Wiring'] as $layer) {
                $rules[$module.'.'.$layer][] = 'Shared.Application';
            }
            $rules[$module.'.Presentation'][] = 'Shared.Presentation';
            foreach (['Infrastructure', 'Integration', 'Wiring'] as $layer) {
                $rules[$module.'.'.$layer][] = 'Shared.Infrastructure';
            }
            $rules[$module.'.Wiring'][] = 'Shared.Presentation';
            foreach ($graph[$module]['sync'] ?? [] as $provider) {
                $rules[$module.'.Integration'][] = $provider.'.Contracts';
                $rules[$module.'.Integration'][] = $provider.'.ContractDto';
            }
            foreach ($graph[$module]['events'] ?? [] as $provider) {
                $rules[$module.'.Integration'][] = $provider.'.ContractEvent';
                $rules[$module.'.Integration'][] = $provider.'.ContractDto';
            }
            $rules[$module.'.Integration'] = array_values(array_unique($rules[$module.'.Integration']));
        }
    }

    return [
        'deptrac' => ['paths' => [$source], 'analyser' => ['types' => ['class', 'use']], 'layers' => $layers, 'ruleset' => $rules],
        'services' => [
            Tools\Deptrac\InheritedCollectionDependencySubscriber::class => [
                'arguments' => ['$baseFile' => $source.'/Shared/Domain/Collection/AbstractCollection.php'],
                'tags' => ['kernel.event_subscriber'],
            ],
            Tools\Deptrac\InheritedUidDependencySubscriber::class => [
                'arguments' => ['$baseFiles' => [
                    $source.'/Shared/Domain/ValueObject/UuidId.php',
                    $source.'/Shared/Domain/ValueObject/UlidId.php',
                ]],
                'tags' => ['kernel.event_subscriber'],
            ],
        ],
    ];
};
