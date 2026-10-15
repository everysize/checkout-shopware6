<?php

declare(strict_types=1);

namespace Everysize\Checkout\Everysize;

/**
 * Amounts in the checkout API are strings from PHP's number_format() with the
 * default separators: "119.99", but "1,234.56" from 1,000 EUR on. PHP reads
 * "1,234.56" as non-numeric, so every amount is parsed here once before
 * anything is calculated with it.
 */
final class Amount
{
    private const PATTERN = '/^-?(\d{1,3}(,\d{3})+|\d+)(\.\d+)?$/';

    public static function parse(mixed $value, string $field): float
    {
        if (\is_int($value) || \is_float($value)) {
            return (float) $value;
        }

        $amount = \is_string($value) ? trim($value) : '';
        if (!preg_match(self::PATTERN, $amount)) {
            throw new \UnexpectedValueException(sprintf('Invalid amount in %s: %s', $field, var_export($value, true)));
        }

        return (float) str_replace(',', '', $amount);
    }
}
