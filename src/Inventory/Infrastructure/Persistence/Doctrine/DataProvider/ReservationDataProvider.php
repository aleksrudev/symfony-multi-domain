<?php

declare(strict_types=1);

namespace App\Inventory\Infrastructure\Persistence\Doctrine\DataProvider;

use App\Inventory\Domain\DataProvider\ReservationDataProviderInterface;
use App\Inventory\Domain\Entity\Reservation;
use App\Inventory\Domain\Exception\ReservationNotFoundException;
use App\Inventory\Domain\ValueObject\OrderId;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(ReservationDataProviderInterface::class)]
final readonly class ReservationDataProvider implements ReservationDataProviderInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function findByOrderId(OrderId $orderId, bool $lock = false): Reservation
    {
        $reservation = $this->entityManager->find(
            Reservation::class,
            $orderId,
            $lock ? LockMode::PESSIMISTIC_WRITE : null,
        );

        if (null === $reservation) {
            throw new ReservationNotFoundException($orderId->value);
        }

        return $reservation;
    }
}

