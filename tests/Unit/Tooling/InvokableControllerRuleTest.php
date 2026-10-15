<?php

declare(strict_types=1);

namespace Tests\Unit\Tooling;

use PHPStan\Testing\RuleTestCase;
use Tools\PHPStan\Rules\InvokableControllerRule;

/** @extends RuleTestCase<InvokableControllerRule> */
final class InvokableControllerRuleTest extends RuleTestCase
{
    protected function getRule(): InvokableControllerRule
    {
        return new InvokableControllerRule();
    }

    public function testAcceptsActorConstructorPrivateHelpersAndInheritedFrameworkMethods(): void
    {
        $this->analyse([__DIR__.'/../../Fixtures/PHPStan/ValidController.php', __DIR__.'/../../Fixtures/PHPStan/AbstractController.php'], []);
    }

    public function testRejectsMissingActor(): void
    {
        $this->analyse([__DIR__.'/../../Fixtures/PHPStan/NoActorController.php'], [
            ['Controllers must be placed in Api/<Actor>/.', 7],
        ]);
    }

    public function testRejectsMultipleActionsAndMissingInvoke(): void
    {
        $this->analyse([__DIR__.'/../../Fixtures/PHPStan/MultiActionController.php'], [
            ['Controllers must declare a public non-static __invoke() method.', 7],
            ['Controllers may declare no public methods other than __construct() and __invoke(): list, item.', 7],
        ]);
    }

    public function testRejectsExtraPublicMethods(): void
    {
        $this->analyse([__DIR__.'/../../Fixtures/PHPStan/ExtraActionController.php'], [
            ['Controllers may declare no public methods other than __construct() and __invoke(): update.', 7],
        ]);
    }

    public function testRejectsNonPublicInvoke(): void
    {
        $this->analyse([__DIR__.'/../../Fixtures/PHPStan/PrivateInvokeController.php'], [
            ['Controllers must declare a public non-static __invoke() method.', 7],
        ]);
    }

    public function testRejectsExtraActionsImportedFromTraits(): void
    {
        $this->analyse([__DIR__.'/../../Fixtures/PHPStan/TraitActionController.php'], [
            ['Controllers may declare no public methods other than __construct() and __invoke(): update.', 14],
        ]);
    }
}
