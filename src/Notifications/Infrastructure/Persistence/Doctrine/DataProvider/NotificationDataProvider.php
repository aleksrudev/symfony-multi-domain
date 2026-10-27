<?php

declare(strict_types=1);

namespace App\Notifications\Infrastructure\Persistence\Doctrine\DataProvider;

use App\Notifications\Domain\Collection\NotificationCollection;
use App\Notifications\Domain\DataProvider\NotificationDataProviderInterface;
use App\Notifications\Domain\Entity\Notification;
use App\Notifications\Domain\Enum\NotificationType;
use App\Notifications\Domain\Exception\NotificationNotFoundException;
use App\Notifications\Domain\ValueObject\NotificationId;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(NotificationDataProviderInterface::class)]
final readonly class NotificationDataProvider implements NotificationDataProviderInterface
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {
    }

    public function findById(NotificationId $id, bool $lock = false): Notification
    {
        $notification = $this->em->find(
            Notification::class,
            $id,
            $lock ? LockMode::PESSIMISTIC_WRITE : null,
        );

        if (!$notification instanceof Notification) {
            throw new NotificationNotFoundException($id);
        }

        return $notification;
    }

    public function findByOrderIdAndType(string $orderId, NotificationType $type): ?Notification
    {
        /** @var ?Notification $notification */
        $notification = $this->em->createQueryBuilder()
            ->select('n')
            ->from(Notification::class, 'n')
            ->where('n.orderId = :orderId')
            ->andWhere('n.type = :type')
            ->setParameter('orderId', $orderId)
            ->setParameter('type', $type)
            ->getQuery()
            ->getOneOrNullResult();

        return $notification;
    }

    public function findByOrderId(string $orderId): NotificationCollection
    {
        /** @var list<Notification> $items */
        $items = $this->em->createQueryBuilder()
            ->select('n')
            ->from(Notification::class, 'n')
            ->where('n.orderId = :orderId')
            ->setParameter('orderId', $orderId)
            ->orderBy('n.createdAt', 'ASC')
            ->addOrderBy('n.id', 'ASC')
            ->getQuery()
            ->getResult();

        return new NotificationCollection($items);
    }

    public function findAllPaginated(int $page, int $limit): NotificationCollection
    {
        $offset = max(0, ($page - 1) * $limit);

        /** @var list<Notification> $items */
        $items = $this->em->createQueryBuilder()
            ->select('n')
            ->from(Notification::class, 'n')
            ->orderBy('n.createdAt', 'DESC')
            ->addOrderBy('n.id', 'ASC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return new NotificationCollection($items);
    }

    public function countAll(): int
    {
        return (int) $this->em->createQueryBuilder()
            ->select('COUNT(n.id)')
            ->from(Notification::class, 'n')
            ->getQuery()
            ->getSingleScalarResult();
    }
}
