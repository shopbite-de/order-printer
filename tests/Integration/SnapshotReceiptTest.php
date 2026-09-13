<?php

declare(strict_types=1);

namespace Veliu\OrderPrinter\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Veliu\OrderPrinter\Domain\Order\OrderRepositoryInterface;
use Veliu\OrderPrinter\Infra\EscPos\PrintProcessor;
use Veliu\OrderPrinter\Infra\Shopware\Api\OrderDelivery\FindDeliveriesResponse;
use Veliu\OrderPrinter\Infra\Shopware\OrderRepository;

class SnapshotReceiptTest extends TestCase
{
    private const string PROJECT_DIR = __DIR__.'/../../';
    private const string DATA_DIR = 'var/tests/receipts/';
    private const string PRINTER_DSN = 'file://php://memory';

    #[\Override]
    protected function setUp(): void
    {
        if (!is_dir(self::PROJECT_DIR.self::DATA_DIR)) {
            mkdir(self::PROJECT_DIR.self::DATA_DIR, 0777, true);
        }
    }

    #[\Override]
    protected function tearDown(): void
    {
        $testDir = self::PROJECT_DIR.self::DATA_DIR;
        if (is_dir($testDir)) {
            $files = glob($testDir.'*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }

    public function testSnapshots(): void
    {
        $snapshotsDir = self::PROJECT_DIR.'tests/Snapshots';
        $directories = glob($snapshotsDir.'/*', GLOB_ONLYDIR);

        foreach ($directories as $dir) {
            $orderNumber = basename($dir);
            $orderJsonFile = $dir.'/order.json';
            $receiptTxtFile = $dir.'/receipt.txt';

            $this->assertFileExists($orderJsonFile);
            $this->assertFileExists($receiptTxtFile);

            $orderJson = json_decode(file_get_contents($orderJsonFile), true);
            $response = FindDeliveriesResponse::fromArray($orderJson);
            $delivery = $response->first();
            $this->assertNotNull($delivery, "No delivery found in $orderJsonFile");

            $order = OrderRepository::transform($delivery);

            $orderRepository = $this->createMock(OrderRepositoryInterface::class);
            $processor = new PrintProcessor(
                self::PRINTER_DSN,
                self::DATA_DIR,
                self::PROJECT_DIR,
                $orderRepository
            );

            $processor->__invoke($order, false);

            $expectedReceiptContent = file_get_contents($receiptTxtFile);

            // The file created by PrintProcessor has a name like: {orderNumber}_{Y-m-d_H-i-s}.txt
            $generatedFiles = glob(self::PROJECT_DIR.self::DATA_DIR.$order->number.'_*.txt');
            $this->assertCount(1, $generatedFiles, "Expected one generated file for order $orderNumber");

            $generatedReceiptContent = file_get_contents($generatedFiles[0]);

            if ($expectedReceiptContent !== $generatedReceiptContent) {
                file_put_contents($dir.'/actual.txt', $generatedReceiptContent);
                // Optionally update the snapshot if a certain environment variable is set
                if (getenv('UPDATE_SNAPSHOTS')) {
                    file_put_contents($receiptTxtFile, $generatedReceiptContent);
                    $expectedReceiptContent = $generatedReceiptContent;
                }
            }

            $this->assertEquals($expectedReceiptContent, $generatedReceiptContent, "Snapshot mismatch for order $orderNumber. Actual output saved to $dir/actual.txt. Run with UPDATE_SNAPSHOTS=1 to update snapshots.");
        }
    }
}
