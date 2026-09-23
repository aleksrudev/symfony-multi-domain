<?php

declare(strict_types=1);
namespace App\Shared\Domain;
final class SharedLeak { public function __construct(public \App\Beta\Domain\Model $model) {} }
