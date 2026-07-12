<?php
declare(strict_types=1);

namespace App\Core;

/**
 * CSV writing with formula-injection protection built in — Excel, Sheets,
 * and most spreadsheet apps treat a cell starting with =, +, -, @, tab, or
 * CR as a formula to evaluate on open, so any value that came from user
 * input must be neutralized before it reaches a cell, not just correctly
 * comma/quote-escaped.
 */
final class Csv
{
    /** @param list<string> $row */
    public static function row(array $row): string
    {
        return implode(',', array_map(self::field(...), $row)) . "\r\n";
    }

    /**
     * Stream rows to php://output as they're generated — see
     * Response::streamDownload() for the HTTP-response half of this.
     *
     * @param iterable<list<string>> $rows
     */
    public static function stream(iterable $rows): void
    {
        foreach ($rows as $row) {
            echo self::row($row);
        }
    }

    private static function field(string $value): string
    {
        $value = self::neutralizeFormula($value);
        if (preg_match('/[",\r\n]/', $value) === 1) {
            $value = '"' . str_replace('"', '""', $value) . '"';
        }
        return $value;
    }

    /**
     * Prefix a leading formula-trigger character with a single quote, which
     * spreadsheet apps render as literal text instead of evaluating — the
     * standard mitigation (OWASP CSV Injection). Applied before quoting, so
     * a value that also needs quoting (e.g. it contains a comma) still gets
     * the prefix first.
     */
    private static function neutralizeFormula(string $value): string
    {
        if ($value !== '' && str_contains("=+-@\t\r", $value[0])) {
            return "'" . $value;
        }
        return $value;
    }
}
