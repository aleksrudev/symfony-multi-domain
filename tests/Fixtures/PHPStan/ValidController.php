<?php

declare(strict_types=1);

namespace App\RuleFixtures\Presentation\Http\Controller\Api\Admin;

final class UpdateProductController extends \Symfony\Bundle\FrameworkBundle\Controller\AbstractController
{
    public function __construct(private readonly string $label = 'updated')
    {
    }

    public function __invoke(): \Symfony\Component\HttpFoundation\Response
    {
        return new \Symfony\Component\HttpFoundation\Response($this->message());
    }

    private function message(): string
    {
        return $this->label;
    }
}
