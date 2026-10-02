<?php

namespace App\Support;

/**
 * CSV formula-injection guard: a text cell that starts with = + - @ (or a tab / carriage return)
 * is run as a formula by Excel and Sheets, so it gets a leading single quote. Numbers stay numbers.
 */
final class CsvSafe
{
    /**
     * @param  array<int, mixed>  $cells
     * @return array<int, mixed>
     */
    public static function row(array $cells): array
    {
        return array_map(self::cell(...), $cells);
    }

    public static function cell(mixed $value): mixed
    {
        if (is_string($value) && $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$value;
        }

        return $value;
    }
}
