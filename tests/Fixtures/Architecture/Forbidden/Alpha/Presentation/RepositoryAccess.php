<?php

declare(strict_types=1);
namespace App\Alpha\Presentation;
final class RepositoryAccess { public function __construct(public \App\Alpha\Domain\Repository\Repository $repository) {} }
