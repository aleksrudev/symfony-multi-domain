<?php

declare(strict_types=1);

namespace App\Inventory\Infrastructure\Persistence\Doctrine\Repository;

use App\Inventory\Domain\Entity\Reservation;
use App\Inventory\Domain\Repository\ReservationRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(ReservationRepositoryInterface::class)]
final readonly class ReservationRepository implements ReservationRepositoryInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function save(Reservation $reservation): void
    {
        $this->entityManager->persist($reservation);
    }

    public function remove(Reservation $reservation): void
    {
        $this->entityManager->remove($reservation);
    }
}
