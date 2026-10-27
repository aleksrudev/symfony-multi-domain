<?php

declare(strict_types=1);

namespace App\Notifications\Presentation\Http\Request;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class GetNotificationsRequest
{
    public function __construct(
        #[Assert\Positive]
        public int $page = 1,
        #[Assert\Range(min: 1, max: 50)]
        public int $limit = 10,
    ) {
    }
}
