<?php

declare(strict_types=1);

namespace App\Shared\Domain\ValueObject;

use Symfony\Component\HttpFoundation\Response;

abstract class UlidId
{
    public function response(): Response
    {
        return new Response();
    }
}
