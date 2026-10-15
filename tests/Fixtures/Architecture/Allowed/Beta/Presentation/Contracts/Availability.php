<?php

declare(strict_types=1);
namespace App\Beta\Presentation\Contracts;
interface Availability { public function getStock(): Dto\AvailableStock; }
