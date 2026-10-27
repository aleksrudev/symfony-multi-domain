<?php

declare(strict_types=1);

namespace App\Ordering\Application\Query\GetOrderDiagnostics;

use App\Shared\Application\Bus\Query\QueryInterface;

final readonly class GetOrderDiagnosticsQuery implements QueryInterface
{
    public function __construct(
        public string $orderId,
    ) {
    }
}

