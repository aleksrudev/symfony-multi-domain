<?php

declare(strict_types=1);

namespace Tests\Fixtures\Modules\Beta\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Tests\Fixtures\Modules\Beta\Application\Command\FailProbe\FailProbeCommand;
use Tests\Fixtures\Modules\Beta\Application\Command\FailProbe\FailProbeStoreInterface;
use Tests\Fixtures\Modules\Beta\Domain\Entity\FailureProbe;

#[AsAlias(FailProbeStoreInterface::class)]
final readonly class FailProbeStore implements FailProbeStoreInterface
{
    public function __construct(private Connection $connection, private EntityManagerInterface $em)
    {
    }

    public function record(FailProbeCommand $command): void
    {
        $this->connection->insert('beta.probe_failed_effects', ['event_id' => $command->operationId, 'value' => $command->value]);
        $mode = $this->connection->fetchOne('SELECT mode FROM beta.probe_control');
        if ('runtime' === $mode) {
            throw new \RuntimeException('Fixture subscriber is unavailable.');
        }
        $this->em->persist(new FailureProbe($command->operationId, 'orm' === $mode ? -1 : $command->value));
    }
}
