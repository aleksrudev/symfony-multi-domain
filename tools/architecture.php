<?php

declare(strict_types=1);

/**
 * Builds separate layers per module, including modules not enabled in Kernel.
 * Cross-module contracts require an explicit, acyclic dependency map.
 *
 * @return Closure(string, array<string, list<string>>): array<string, mixed>
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
    $layers = [
        ['name' => 'PHP', 'collectors' => [$internal]],
        ['name' => 'ORMMapping', 'collectors' => [$mapping]],
        ['name' => 'DoctrineCollections', 'collectors' => [$doctrineCollections]],
        ['name' => 'CollectionBase', 'collectors' => [$collectionBase]],
        ['name' => 'SymfonyUid', 'collectors' => [$uid]],
        ['name' => 'UidBase', 'collectors' => [$uidBase]],
        ['name' => 'Validation', 'collectors' => [$validation]],
        ['name' => 'Vendor', 'collectors' => [['type' => 'bool', 'must' => [$class('^(?!App\\\\).*')], 'must_not' => [$internal, $mapping, $validation, $doctrineCollections, $uid]]]],
        ['name' => 'Kernel', 'collectors' => [$class('^App\\\\Kernel$')]],
    ];
    $rules = ['PHP' => [], 'ORMMapping' => [], 'DoctrineCollections' => [], 'CollectionBase' => ['PHP', 'DoctrineCollections'], 'SymfonyUid' => [], 'UidBase' => ['PHP', 'SymfonyUid'], 'Validation' => [], 'Vendor' => [], 'Kernel' => ['PHP', 'Vendor', 'DoctrineCollections', 'CollectionBase', 'SymfonyUid', 'UidBase']];
    $modules = ['Shared'];
    foreach (glob($source.'/*', GLOB_ONLYDIR) ?: [] as $directory) {
        $name = basename($directory);
        if ('Shared' !== $name && preg_match('/^[A-Z][A-Za-z0-9]*$/D', $name)) {
            $modules[] = $name;
        }
    }
    foreach ($dependencies as $consumer => $providers) {
        if ('Shared' === $consumer || !in_array($consumer, $modules, true)) {
            throw new LogicException('Unknown or forbidden dependency consumer: '.$consumer);
        }
        foreach ($providers as $provider) {
            if ($consumer === $provider || 'Shared' === $provider || !in_array($provider, $modules, true)) {
                throw new LogicException('Unknown or forbidden dependency provider: '.$provider);
            }
        }
    }
    $visit = static function (string $module, array $path = []) use (&$visit, $dependencies): void {
        if (in_array($module, $path, true)) {
            throw new LogicException('Cyclic module dependency: '.implode(' -> ', [...$path, $module]));
        }
        foreach ($dependencies[$module] ?? [] as $provider) {
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
            'Contracts' => [$class($prefix.'Presentation\\\\Contracts\\\\')],
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
        $rules[$module.'.Application'] = ['PHP', 'Validation', $module.'.Domain', $module.'.Repository', $module.'.Collection'];
        $rules[$module.'.Presentation'] = ['PHP', 'Vendor', 'DoctrineCollections', 'SymfonyUid', 'Validation', $module.'.Application', $module.'.Domain', $module.'.Collection', $module.'.Contracts'];
        foreach (['Infrastructure', 'Integration', 'Wiring'] as $layer) {
            $rules[$module.'.'.$layer] = ['PHP', 'Vendor', 'DoctrineCollections', 'SymfonyUid', 'ORMMapping', 'Validation', $module.'.Domain', $module.'.Repository', $module.'.Collection', $module.'.Application', $module.'.Contracts'];
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
            foreach ($dependencies[$module] ?? [] as $provider) {
                $rules[$module.'.Integration'][] = $provider.'.Contracts';
            }
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
