<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Shared\Infrastructure\Messaging\IdempotentConsumer\Consumer\DoctrineIdempotentConsumer;
use App\Shared\Infrastructure\Messaging\IdempotentConsumer\Identity\MessageIdentity;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Clock\MockClock;
use Tests\Fixtures\Messaging\Consumer\ConsumerStorageTestCase;
use Tests\Fixtures\Messaging\Consumer\Entity\ConsumerProbe;

final class IdempotentConsumerOrmTest extends ConsumerStorageTestCase
{
    public function testUnflushedChangesCannotLeakIntoNextCallback(): void
    {
        $consumer = $this->consumer();
        $this->db->insert('public.consumer_test_probe', ['id' => 'first', 'value' => 1]);
        $this->db->insert('public.consumer_test_probe', ['id' => 'second', 'value' => 10]);
        try {
            $consumer->consume('record-delivery', new MessageIdentity('provider.webhook', 'failed'), function (): void {
                $probe = $this->em->find(ConsumerProbe::class, 'first');
                self::assertInstanceOf(ConsumerProbe::class, $probe);
                $probe->value = 2;
                throw new \RuntimeException('Before flush');
            });
            self::fail('Expected callback failure.');
        } catch (\RuntimeException $e) {
            self::assertSame('Before flush', $e->getMessage());
        }
        self::assertTrue($this->em->isOpen());
        $consumer->consume('record-delivery', new MessageIdentity('provider.webhook', 'next'), function (): void {
            $probe = $this->em->find(ConsumerProbe::class, 'second');
            self::assertInstanceOf(ConsumerProbe::class, $probe);
            $probe->value = 11;
        });
        self::assertSame(1, $this->db->fetchOne("SELECT value FROM public.consumer_test_probe WHERE id = 'first'"));
        self::assertSame(11, $this->db->fetchOne("SELECT value FROM public.consumer_test_probe WHERE id = 'second'"));
        self::assertSame(['next'], $this->db->fetchFirstColumn('SELECT message_id FROM shared.processed_messages'));
        self::assertSame([], $this->em->getUnitOfWork()->getIdentityMap());
    }

    public function testFlushFailureDoesNotCommitReceiptOrUndoPreviousSuccess(): void
    {
        $consumer = $this->consumer();
        $consumer->consume('record-delivery', new MessageIdentity('provider.webhook', 'success'), function (): void {
            $this->em->persist(new ConsumerProbe('success', 1));
        });
        try {
            $consumer->consume('record-delivery', new MessageIdentity('provider.webhook', 'failure'), function (): void {
                $this->em->persist(new ConsumerProbe('failure', -1));
            });
            self::fail('Expected an ORM constraint failure.');
        } catch (DriverException $e) {
            self::assertSame('23514', $e->getSQLState());
        }
        self::assertFalse($this->em->isOpen());
        self::assertSame(['success'], $this->db->fetchFirstColumn('SELECT message_id FROM shared.processed_messages'));
        self::assertSame(['effect', 'success'], $this->db->fetchFirstColumn('SELECT id FROM public.consumer_test_probe ORDER BY id'));
        $manager = $this->registry->resetManager();
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $this->em = $manager;
        $consumer->consume('record-delivery', new MessageIdentity('provider.webhook', 'failure'), function (): void {
            $this->em->persist(new ConsumerProbe('recovered', 2));
        });
        self::assertSame(['failure', 'success'], $this->db->fetchFirstColumn('SELECT message_id FROM shared.processed_messages ORDER BY message_id'));
        self::assertSame(['effect', 'recovered', 'success'], $this->db->fetchFirstColumn('SELECT id FROM public.consumer_test_probe ORDER BY id'));
        self::assertSame([], $this->em->getUnitOfWork()->getIdentityMap());
    }

    #[DataProvider('receiptStates')]
    public function testClosedEntityManagerRejectsCallbackWithoutReceipt(bool $alreadyProcessed): void
    {
        $consumer = $this->consumer();
        $identity = new MessageIdentity('provider.webhook', 'delivery-42');
        if ($alreadyProcessed) {
            $consumer->consume('record-delivery', $identity, static function (): void {});
        }
        $this->em->close();
        try {
            $consumer->consume('record-delivery', $identity, static function (): void {
                self::fail('Closed EntityManager must reject before callback.');
            });
            self::fail('Expected closed manager rejection.');
        } catch (\LogicException) {
            self::assertSame($alreadyProcessed ? 1 : 0, $this->db->fetchOne('SELECT COUNT(*) FROM shared.processed_messages'));
            self::assertFalse($this->em->isOpen());
            self::assertFalse($this->db->isTransactionActive());
        }
    }

    /** @return iterable<array{bool}> */
    public static function receiptStates(): iterable
    {
        yield 'new identity' => [false];
        yield 'duplicate identity' => [true];
    }

    public function testOuterCommitFailureRollsBackReceiptAndClearsManagedState(): void
    {
        $consumer = $this->consumer();
        $record = new ConsumerProbe('deferred', 1);
        $this->db->executeStatement("CREATE FUNCTION public.consumer_test_reject_commit() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN RAISE EXCEPTION USING ERRCODE = ''23503'', MESSAGE = ''commit rejected''; END'");
        $this->db->executeStatement('CREATE CONSTRAINT TRIGGER consumer_test_reject_commit AFTER INSERT ON public.consumer_test_probe DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION public.consumer_test_reject_commit()');
        try {
            try {
                $consumer->consume('record-delivery', new MessageIdentity('provider.webhook', 'deferred'), fn () => $this->em->persist($record));
                self::fail('Expected deferred commit failure.');
            } catch (ForeignKeyConstraintViolationException) {
                self::assertSame(0, $this->db->fetchOne('SELECT COUNT(*) FROM shared.processed_messages'));
                self::assertSame(['effect'], $this->db->fetchFirstColumn('SELECT id FROM public.consumer_test_probe'));
                self::assertFalse($this->em->contains($record));
                self::assertFalse($this->db->isTransactionActive());
            }
        } finally {
            $this->db->executeStatement('DROP TRIGGER consumer_test_reject_commit ON public.consumer_test_probe');
            $this->db->executeStatement('DROP FUNCTION public.consumer_test_reject_commit()');
        }
    }

    public function testDifferentManagerConnectionIsRejectedBeforeCallback(): void
    {
        $other = $this->observer();
        try {
            $consumer = new DoctrineIdempotentConsumer($other, $this->registry, new MockClock());
            try {
                $consumer->consume('record-delivery', new MessageIdentity('provider.webhook', 'delivery-42'), static function (): void {
                    self::fail('Receipt and ORM effects must share one connection.');
                });
                self::fail('Expected connection mismatch rejection.');
            } catch (\LogicException) {
                self::assertSame(0, $this->db->fetchOne('SELECT COUNT(*) FROM shared.processed_messages'));
                self::assertFalse($other->isTransactionActive());
            }
        } finally {
            $other->close();
        }
    }

    public function testNestedLocalTransactionDoesNotCommitReceiptEarly(): void
    {
        $consumer = $this->consumer();
        $observer = $this->observer();
        try {
            try {
                $consumer->consume('record-delivery', new MessageIdentity('provider.webhook', 'nested'), function () use ($observer): void {
                    $this->db->transactional(fn () => $this->db->executeStatement("UPDATE public.consumer_test_probe SET value = 1 WHERE id = 'effect'"));
                    self::assertSame(0, $observer->fetchOne('SELECT COUNT(*) FROM shared.processed_messages'));
                    self::assertSame(0, $observer->fetchOne("SELECT value FROM public.consumer_test_probe WHERE id = 'effect'"));
                    throw new \RuntimeException('Outer callback failure');
                });
                self::fail('Expected outer callback rollback.');
            } catch (\RuntimeException $e) {
                self::assertSame('Outer callback failure', $e->getMessage());
                self::assertSame(0, $observer->fetchOne('SELECT COUNT(*) FROM shared.processed_messages'));
                self::assertSame(0, $observer->fetchOne("SELECT value FROM public.consumer_test_probe WHERE id = 'effect'"));
            }
        } finally {
            $observer->close();
        }
    }
}
