<?php

declare(strict_types=1);
namespace App\Alpha\Domain;
final class ForeignModel { public function __construct(public \App\Beta\Domain\Model $model) {} }
