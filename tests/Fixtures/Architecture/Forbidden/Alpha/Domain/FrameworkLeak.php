<?php

declare(strict_types=1);
namespace App\Alpha\Domain;
final class FrameworkLeak { public function __construct(public \Symfony\Component\HttpFoundation\Request $request) {} }
