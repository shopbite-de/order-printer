<?php

declare(strict_types=1);

namespace Veliu\OrderPrinter\Tests\Integration;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Veliu\OrderPrinter\Infra\Doctrine\PrintJobRepository;

final class PrintJobRepositoryTest extends KernelTestCase
{
    private MockClock $clock;
    private EntityManagerInterface $entityManager;
    private PrintJobRepository $repository;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $this->clock = new MockClock('2026-09-13 12:00:00');
        $container->set('clock', $this->clock);

        $this->entityManager = $container->get(EntityManagerInterface::class);
        $schemaTool = new SchemaTool($this->entityManager);
        $metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);

        $this->repository = $container->get(PrintJobRepository::class);
    }

    public function testStartMakesTheOrderPendingUntilFinished(): void
    {
        self::assertFalse($this->repository->isPending('10556'));

        $this->repository->start('10556');
        self::assertTrue($this->repository->isPending('10556'));
        self::assertFalse($this->repository->isPending('10557'));

        $this->repository->finish('10556');
        self::assertFalse($this->repository->isPending('10556'));
    }

    public function testStaleJobsAreNotPendingAndCanBeRestarted(): void
    {
        $this->repository->start('10556');

        $this->clock->sleep(2 * 3600 - 1);
        self::assertTrue($this->repository->isPending('10556'));

        $this->clock->sleep(2);
        self::assertFalse($this->repository->isPending('10556'), 'a job older than the TTL is stale');

        $this->repository->start('10556');
        self::assertTrue($this->repository->isPending('10556'), 'restarting refreshes the timestamp');
    }

    public function testReadsChangesMadeByAnotherProcess(): void
    {
        $this->repository->start('10556');
        self::assertTrue($this->repository->isPending('10556'));

        // Simulate the print worker removing the row from another process.
        $this->entityManager->getConnection()->executeStatement('DELETE FROM print_job WHERE order_number = ?', ['10556']);

        self::assertFalse($this->repository->isPending('10556'), 'must not answer from the identity map');
    }

    public function testFinishingAnUnknownOrderIsANoOp(): void
    {
        $this->repository->finish('unknown');

        self::assertFalse($this->repository->isPending('unknown'));
    }
}
