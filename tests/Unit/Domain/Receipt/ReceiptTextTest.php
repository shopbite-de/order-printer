<?php

declare(strict_types=1);

namespace Veliu\OrderPrinter\Tests\Unit\Domain\Receipt;

use PHPUnit\Framework\TestCase;
use Veliu\OrderPrinter\Domain\Receipt\ReceiptText;

final class ReceiptTextTest extends TestCase
{
    public function testKeepsPrintableTextAndUmlauts(): void
    {
        self::assertSame('Pizza Größe 32 cm +Käse (scharf) 5,50 €', ReceiptText::line('Pizza Größe 32 cm +Käse (scharf) 5,50 €'));
    }

    public function testRemovesEscAndGsSequences(): void
    {
        self::assertSame('Pizza', ReceiptText::line("Pi\x1B@zza"));
        self::assertSame('Pizza', ReceiptText::line("Pizza\x1DV\x00"));
        self::assertSame('Pizza', ReceiptText::line("Pizza\x1Bp\x00\x19\x19"));
        self::assertSame('Pizza', ReceiptText::line("Pizza\x1C"));
    }

    public function testRemovesOtherControlCharacters(): void
    {
        self::assertSame('Pizza', ReceiptText::line("P\x00i\x07z\x08z\x7Fa\t"));
        self::assertSame('Pizza', ReceiptText::line("Pizza\u{009B}"));
    }

    public function testDropsInvalidUtf8(): void
    {
        self::assertSame('Pizza?', ReceiptText::line("Pizza\xC3"));
    }

    public function testALineHasNoLineBreaks(): void
    {
        self::assertSame('Hauptstr. 1 Hinterhaus', ReceiptText::line("Hauptstr. 1\r\nHinterhaus"));
    }

    public function testATextKeepsLineBreaks(): void
    {
        self::assertSame("Bitte klingeln\nbei Müller", ReceiptText::text("Bitte klingeln\r\nbei Müller\x1B@"));
    }

    public function testCapsLongTextWithAnEllipsis(): void
    {
        self::assertSame('Pizz...', ReceiptText::line('Pizza Margherita', 7));
        self::assertSame('Pizza', ReceiptText::line('Pizza', 5));
        $capped = ReceiptText::text(str_repeat('ä', 600), 500);
        self::assertSame(500, mb_strlen($capped));
        self::assertStringEndsWith('...', $capped);
    }

    public function testCapsAfterCleaning(): void
    {
        self::assertSame('Pizza', ReceiptText::line("\x1B@\x1B@\x1B@Pizza", 5));
    }

    public function testFallsBackForTextThatIsOnlyControlCharacters(): void
    {
        self::assertSame('-', ReceiptText::nonEmptyLine("\x1B@"));
        self::assertNull(ReceiptText::nullableLine("\x1B@"));
        self::assertNull(ReceiptText::nullableText(null));
    }
}
