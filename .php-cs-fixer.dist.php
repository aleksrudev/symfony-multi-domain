<?php

$finder = (new PhpCsFixer\Finder())
    ->in(__DIR__.'/src')
    ->in(__DIR__.'/migrations')
    ->in(__DIR__.'/tests')
    ->exclude('Fixtures/Architecture')
    ->exclude('Fixtures/PHPStan')
    ->in(__DIR__.'/tools')
;

return (new PhpCsFixer\Config())
    ->setRules([
        '@Symfony' => true,
        '@PHP84Migration' => true,
        'strict_param' => true,
        'declare_strict_types' => true,
        'array_syntax' => ['syntax' => 'short'],
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'no_unused_imports' => true,
        'single_line_throw' => false,
    ])
    ->setFinder($finder)
    ->setRiskyAllowed(true)
;
