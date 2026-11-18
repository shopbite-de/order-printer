<?php

declare(strict_types=1);

namespace Veliu\OrderPrinter\Domain\Receipt;

/**
 * Text from an order (labels, product numbers, comments, names, addresses) is printed as is,
 * so it must not carry printer commands. ESC/POS commands start with ESC, FS or GS followed by
 * a command byte; both are removed, as is every other control character except the line break
 * of multi-line text. Parameter bytes that follow a command are printable at worst.
 */
final class ReceiptText
{
    private const string ELLIPSIS = '...';

    /** One line: line breaks become spaces. */
    public static function line(string $text, ?int $maxLength = null): string
    {
        $clean = self::clean(preg_replace('/\R/u', ' ', self::scrub($text)) ?? '');

        return self::cap($clean, $maxLength);
    }

    /** Multi-line text such as the customer comment: line breaks are kept as "\n". */
    public static function text(string $text, ?int $maxLength = null): string
    {
        $clean = self::clean(preg_replace('/\R/u', "\n", self::scrub($text)) ?? '');

        return self::cap($clean, $maxLength);
    }

    /** @psalm-return non-empty-string */
    public static function nonEmptyLine(string $text): string
    {
        $clean = self::line($text);

        return '' === $clean ? '-' : $clean;
    }

    /** @psalm-return non-empty-string|null */
    public static function nullableLine(?string $text): ?string
    {
        $clean = null === $text ? '' : self::line($text);

        return '' === $clean ? null : $clean;
    }

    /** @psalm-return non-empty-string|null */
    public static function nullableText(?string $text): ?string
    {
        $clean = null === $text ? '' : trim(self::text($text));

        return '' === $clean ? null : $clean;
    }

    private static function scrub(string $text): string
    {
        return mb_scrub($text, 'UTF-8');
    }

    private static function clean(string $text): string
    {
        // ESC, FS, GS + command byte, then any other C0/C1 control character but "\n", and DEL
        $text = preg_replace('/[\x1B\x1C\x1D].?/su', '', $text) ?? '';

        return preg_replace('/[\x00-\x09\x0B-\x1F\x7F\x{80}-\x{9F}]/u', '', $text) ?? '';
    }

    private static function cap(string $text, ?int $maxLength): string
    {
        if (null === $maxLength || mb_strlen($text) <= $maxLength) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, max(0, $maxLength - mb_strlen(self::ELLIPSIS)))).self::ELLIPSIS;
    }
}
