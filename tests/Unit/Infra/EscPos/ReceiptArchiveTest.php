<?php

declare(strict_types=1);

namespace Veliu\OrderPrinter\Tests\Unit\Infra\EscPos;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Veliu\OrderPrinter\Infra\EscPos\ReceiptArchive;

#[CoversClass(ReceiptArchive::class)]
final class ReceiptArchiveTest extends TestCase
{
    private string $projectDir;
    private ReceiptArchive $archive;

    #[\Override]
    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/receipt-archive-'.bin2hex(random_bytes(4));
        mkdir($this->projectDir.'/receipts/', 0777, true);
        $this->archive = new ReceiptArchive('/receipts/', $this->projectDir);
    }

    #[\Override]
    protected function tearDown(): void
    {
        foreach (glob($this->projectDir.'/receipts/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->projectDir.'/receipts');
        rmdir($this->projectDir);
    }

    public function testDeletesOnlyReceiptsModifiedBeforeTheCutoff(): void
    {
        $cutoff = new \DateTimeImmutable('2026-09-01 04:00:00');
        $this->receipt('10001_2026-07-01_12-00-00.txt', $cutoff->modify('-31 days'));
        $this->receipt('10002_2026-08-31_23-59-00.txt', $cutoff->modify('-1 minute'));
        $this->receipt('10003_2026-09-01_04-00-00.txt', $cutoff);
        $this->receipt('10004_2026-09-10_18-30-00.txt', $cutoff->modify('+9 days'));
        $this->receipt('queue.db', $cutoff->modify('-1 year'));

        self::assertSame(2, $this->archive->purgeOlderThan($cutoff));

        self::assertFileDoesNotExist($this->projectDir.'/receipts/10001_2026-07-01_12-00-00.txt');
        self::assertFileDoesNotExist($this->projectDir.'/receipts/10002_2026-08-31_23-59-00.txt');
        self::assertFileExists($this->projectDir.'/receipts/10003_2026-09-01_04-00-00.txt');
        self::assertFileExists($this->projectDir.'/receipts/10004_2026-09-10_18-30-00.txt');
        self::assertFileExists($this->projectDir.'/receipts/queue.db', 'only .txt receipt copies are touched');
    }

    public function testMissingDirectoryDeletesNothing(): void
    {
        $archive = new ReceiptArchive('/does-not-exist/', $this->projectDir);

        self::assertSame(0, $archive->purgeOlderThan(new \DateTimeImmutable()));
    }

    private function receipt(string $name, \DateTimeImmutable $modifiedAt): void
    {
        $file = $this->projectDir.'/receipts/'.$name;
        file_put_contents($file, "\x1b@receipt");
        touch($file, $modifiedAt->getTimestamp());
    }
}
