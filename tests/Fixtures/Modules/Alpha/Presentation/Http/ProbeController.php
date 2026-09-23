<?php

declare(strict_types=1);

namespace Tests\Fixtures\Modules\Alpha\Presentation\Http;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class ProbeController
{
    #[Route('/probe', name: 'probe', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        return new JsonResponse(['ok' => true]);
    }
}
