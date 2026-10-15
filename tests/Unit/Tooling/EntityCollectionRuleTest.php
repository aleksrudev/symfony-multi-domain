<?php

declare(strict_types=1);

namespace Tests\Unit\Tooling;

use PHPStan\Testing\RuleTestCase;
use Tools\PHPStan\Rules\EntityCollectionRule;

/** @extends RuleTestCase<EntityCollectionRule> */
final class EntityCollectionRuleTest extends RuleTestCase
{
    protected function getRule(): EntityCollectionRule
    {
        return new EntityCollectionRule();
    }

    public function testAcceptsSharedBaseAndKeepsPaginatedModelsSeparate(): void
    {
        $this->analyse([
            __DIR__.'/../../Fixtures/PHPStan/ValidCollection.php',
            __DIR__.'/../../Fixtures/PHPStan/ItemPaginatedCollection.php',
            __DIR__.'/../../../src/Shared/Domain/Collection/AbstractCollection.php',
        ], []);
    }

    public function testRejectsACollectionWithoutTheSharedBase(): void
    {
        $this->analyse([__DIR__.'/../../Fixtures/PHPStan/PlainCollection.php'], [
            ['Entity collections must extend App\\Shared\\Domain\\Collection\\AbstractCollection.', 7],
        ]);
    }

    public function testRejectsDirectDoctrineInheritance(): void
    {
        $this->analyse([__DIR__.'/../../Fixtures/PHPStan/DirectDoctrineCollection.php'], [
            ['Entity collections must extend App\\Shared\\Domain\\Collection\\AbstractCollection.', 8],
        ]);
    }
}
