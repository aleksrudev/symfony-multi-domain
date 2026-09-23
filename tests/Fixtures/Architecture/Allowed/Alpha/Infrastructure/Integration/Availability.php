<?php

declare(strict_types=1);
namespace App\Alpha\Infrastructure\Integration;
final class Availability { public function __construct(private \App\Beta\Contracts\Availability $provider) {} }
