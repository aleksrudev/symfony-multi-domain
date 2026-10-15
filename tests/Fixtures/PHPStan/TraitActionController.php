<?php

declare(strict_types=1);

namespace App\RuleFixtures\Presentation\Http\Controller\Api\Admin;

trait ExtraAction
{
    public function update(): void
    {
    }
}

final class TraitActionController
{
    use ExtraAction;

    public function __invoke(): void
    {
    }
}
