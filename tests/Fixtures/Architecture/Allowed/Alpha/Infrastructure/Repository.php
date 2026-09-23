<?php

declare(strict_types=1);
namespace App\Alpha\Infrastructure;
final class Repository implements \App\Alpha\Domain\Repository\Repository
{
    public function load(): \App\Alpha\Domain\Model { return new \App\Alpha\Domain\Model(); }
}
