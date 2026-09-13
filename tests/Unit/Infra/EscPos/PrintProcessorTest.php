<?php

declare(strict_types=1);

namespace Veliu\OrderPrinter\Tests\Infra\EscPos;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Veliu\OrderPrinter\Domain\Address\Address;
use Veliu\OrderPrinter\Domain\Order\Order;
use Veliu\OrderPrinter\Domain\Order\OrderItem;
use Veliu\OrderPrinter\Domain\Order\OrderRepositoryInterface;
use Veliu\OrderPrinter\Domain\Receipt\ReceiptPositionPrintTypeEnum;
use Veliu\OrderPrinter\Infra\EscPos\PrintProcessor;

class PrintProcessorTest extends TestCase
{
    private const string PRINTER_DSN = 'dummy://';
    private const string DATA_DIR = '/tmp/test_data/';
    private const string PROJECT_DIR = '/tmp/test_project';

    private PrintProcessor $printProcessor;
    private OrderRepositoryInterface&MockObject $orderRepository;

    #[\Override]
    protected function setUp(): void
    {
        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);

        $this->printProcessor = new PrintProcessor(
            self::PRINTER_DSN,
            self::DATA_DIR,
            self::PROJECT_DIR,
            $this->orderRepository
        );

        // Ensure test directories exist
        if (!is_dir(self::PROJECT_DIR.self::DATA_DIR)) {
            mkdir(self::PROJECT_DIR.self::DATA_DIR, 0777, true);
        }
    }

    protected function tearDown(): void
    {
        // Clean up test files
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

    public function testInvokeCreatesReceiptAndMarksOrderInProgress(): void
    {
        // Arrange
        $order = $this->createTestOrder(isNew: true);

        // Assert that markInProgress is called when order is new and markInProgress is true
        $this->orderRepository
            ->expects($this->once())
            ->method('markInProgress')
            ->with($order);

        // Act
        $this->printProcessor->__invoke($order, true);

        // Assert that file was created
        $expectedFile = self::PROJECT_DIR.self::DATA_DIR.'ORDER123_2024-01-01_12-00-00.txt';
        $this->assertFileExists($expectedFile);
    }

    public function testInvokeDoesNotMarkOrderInProgressWhenFlagIsFalse(): void
    {
        // Arrange
        $order = $this->createTestOrder(isNew: true);

        // Assert that markInProgress is never called
        $this->orderRepository
            ->expects($this->never())
            ->method('markInProgress');

        // Act
        $this->printProcessor->__invoke($order, false);

        // Assert that file was still created
        $expectedFile = self::PROJECT_DIR.self::DATA_DIR.'ORDER123_2024-01-01_12-00-00.txt';
        $this->assertFileExists($expectedFile);
    }

    public function testInvokeDoesNotMarkOrderInProgressWhenOrderIsNotNew(): void
    {
        // Arrange
        $order = $this->createTestOrder(isNew: false);

        // Assert that markInProgress is never called
        $this->orderRepository
            ->expects($this->never())
            ->method('markInProgress');

        // Act
        $this->printProcessor->__invoke($order, true);
    }

    public function testReceiptContentContainsOrderInformation(): void
    {
        // Arrange
        $order = $this->createTestOrder();

        // Act
        $this->printProcessor->__invoke($order, false);

        // Assert
        $expectedFile = self::PROJECT_DIR.self::DATA_DIR.'ORDER123_2024-01-01_12-00-00.txt';
        $this->assertFileExists($expectedFile);

        $content = file_get_contents($expectedFile);

        // Convert the content to a readable format for testing
        // The printer outputs ESC/POS commands, so we need to extract the readable text
        $readableContent = $this->extractReadableText($content);

        // Check header information
        $this->assertStringContainsString('Bestellnummer: ORDER123', $readableContent);
        $this->assertStringContainsString('01.01.2024 13:00:00', $readableContent);

        // Check address information
        $this->assertStringContainsString('John Doe', $readableContent);
        $this->assertStringContainsString('Test Street 123', $readableContent);
        $this->assertStringContainsString('Test City', $readableContent);
        $this->assertStringContainsString('123456789', $readableContent);

        // Check order items (using CP858 encoded prices)
        $this->assertStringContainsString('2x Test Product 1', $readableContent);
        $this->assertStringContainsString('1x Test Product 2', $readableContent);

        // Check that prices are present (they'll be in CP858 encoding)
        $this->assertStringContainsString('10.99', $readableContent);
        $this->assertStringContainsString('15.99', $readableContent);

        // Check totals
        $this->assertStringContainsString('Lieferkosten:', $readableContent);
        $this->assertStringContainsString('5.99', $readableContent);
        $this->assertStringContainsString('Gesamt:', $readableContent);
        $this->assertStringContainsString('43.96', $readableContent);

        // Check dividers
        $this->assertStringContainsString(str_repeat('-', 42), $readableContent);
    }

    public function testReceiptHandlesItemsWithSpecialCharacters(): void
    {
        // Arrange
        $items = [
            new OrderItem('1', '+Extra Cheese', '2.50', 1, ReceiptPositionPrintTypeEnum::LABEL),
            new OrderItem('2', '-No Onions', '0.00', 1, ReceiptPositionPrintTypeEnum::LABEL),
        ];

        $order = $this->createTestOrderWithCustomItems($items);

        // Act
        $this->printProcessor->__invoke($order, false);

        // Assert
        $expectedFile = self::PROJECT_DIR.self::DATA_DIR.'ORDER123_2024-01-01_12-00-00.txt';
        $this->assertFileExists($expectedFile);

        $content = file_get_contents($expectedFile);
        $readableContent = $this->extractReadableText($content);

        $this->assertStringContainsString('+Extra Cheese', $readableContent);
        $this->assertStringContainsString('-No Onions', $readableContent);
    }

    public function testFilenamingUsesCorrectFormat(): void
    {
        // Arrange
        $createdAt = new \DateTimeImmutable('2024-12-25 15:30:45');
        $order = new Order(
            'XMAS2024',
            'XMAS2024',
            null,
            '100.00',
            '10.00',
            $this->createTestAddress(),
            [$this->createTestOrderItem()],
            false,
            'Lieferung',
            $createdAt
        );

        // Act
        $this->printProcessor->__invoke($order, false);

        // Assert
        $expectedFile = self::PROJECT_DIR.self::DATA_DIR.'XMAS2024_2024-12-25_15-30-45.txt';
        $this->assertFileExists($expectedFile);
    }

    public function testReceiptContainsEscPosCommands(): void
    {
        // Arrange
        $order = $this->createTestOrder();

        // Act
        $this->printProcessor->__invoke($order, false);

        // Assert
        $expectedFile = self::PROJECT_DIR.self::DATA_DIR.'ORDER123_2024-01-01_12-00-00.txt';
        $content = file_get_contents($expectedFile);

        // Check for ESC/POS commands
        $this->assertStringContainsString("\x1B\x40", $content); // Initialize command
        $this->assertStringContainsString("\x1B\x61", $content); // Alignment command
        $this->assertStringContainsString("\x1D\x21", $content); // Text size command
        $this->assertStringContainsString("\x1D\x56", $content); // Cut command
    }

    /**
     * Extract readable text from ESC/POS encoded content
     * This method removes ESC/POS control codes and converts CP858 to UTF-8.
     */
    private function extractReadableText(string $content): string
    {
        // Remove common ESC/POS control sequences
        $patterns = [
            '/\x1B\x40/',     // Initialize
            '/\x1B\x61./',    // Alignment
            '/\x1D\x21./',    // Text size
            '/\x1B\x45./',    // Bold on/off
            '/\x1D\x42./',    // Reverse colors on/off
            '/\x1D\x56./',    // Cut
            '/\x1B\x74./',    // Code page
        ];

        $cleanContent = preg_replace($patterns, '', $content);

        // Convert from CP858 to UTF-8 (this handles the Euro symbol conversion)
        $utf8Content = iconv('CP858', 'UTF-8//IGNORE', $cleanContent);

        return $utf8Content ?: $cleanContent;
    }

    private function createTestOrder(bool $isNew = true): Order
    {
        return new Order(
            'ORDER123',
            'ORDER123',
            null,
            '43.96',
            '5.99',
            $this->createTestAddress(),
            [
                new OrderItem('2', 'Test Product 1', '10.99', 2, ReceiptPositionPrintTypeEnum::LABEL),
                new OrderItem('1', 'Test Product 2', '15.99', 1, ReceiptPositionPrintTypeEnum::LABEL),
            ],
            $isNew,
            'Lieferung',
            new \DateTimeImmutable('2024-01-01 12:00:00')
        );
    }

    private function createTestOrderWithCustomItems(array $items): Order
    {
        return new Order(
            'ORDER123',
            'ORDER123',
            null,
            '43.96',
            '5.99',
            $this->createTestAddress(),
            $items,
            false,
            'Lieferung',
            new \DateTimeImmutable('2024-01-01 12:00:00')
        );
    }

    private function createTestAddress(): Address
    {
        return new Address(
            'John Doe',
            'Test Street 123',
            'Test City',
            '123456789',
            null
        );
    }

    private function createTestOrderItem(): OrderItem
    {
        return new OrderItem('1', 'Test Product', '10.99', 1, ReceiptPositionPrintTypeEnum::LABEL);
    }
}
