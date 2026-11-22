<?php

declare(strict_types=1);

namespace Trk\Monitor\Support;

/**
 * Makes SQL safe to transmit: inline literals (strings, numbers, hex, IN-lists) are
 * replaced by placeholders, whitespace is collapsed and the statement is truncated.
 * Bindings are never part of the output.
 */
final class SqlSanitizer
{
    public static function sanitize(string $sql, int $maxLength = 2000): string
    {
        $sql = (string) preg_replace([
            "/'(?:[^'\\\\]|\\\\.|'')*'/s",   // single-quoted strings
            '/"(?:[^"\\\\]|\\\\.)*"(?=\s*(?:[,)=<>]|$|\s+(?:and|or|then|else|end)\b))/i', // double-quoted values (not identifiers in common positions)
            '/\b0x[0-9a-f]+\b/i',             // hex literals
            '/(?<![\w$`.])-?\d+(?:\.\d+)?\b/', // numbers
        ], ['?', '?', '?', '?'], $sql);

        // Collapse IN (?, ?, ?) lists so their length does not create distinct statements.
        $sql = (string) preg_replace('/\bin\s*\(\s*\?(?:\s*,\s*\?)*\s*\)/i', 'in (?)', $sql);
        $sql = trim((string) preg_replace('/\s+/', ' ', $sql));

        return mb_strlen($sql) > $maxLength ? mb_substr($sql, 0, $maxLength).'…' : $sql;
    }

    /** select | insert | update | delete | ddl | other */
    public static function category(string $sql): string
    {
        $keyword = strtolower((string) strtok(ltrim($sql, " \t\n\r("), " \t\n\r"));

        return match ($keyword) {
            'select', 'with' => 'select',
            'insert', 'replace' => 'insert',
            'update' => 'update',
            'delete' => 'delete',
            'create', 'alter', 'drop', 'truncate' => 'ddl',
            default => 'other',
        };
    }

    /** @return list<string> Lower-case table names referenced by the statement. */
    public static function tables(string $sql): array
    {
        preg_match_all('/\b(?:from|join|into|update|table)\s+[`"\[]?([A-Za-z0-9_.]+)[`"\]]?/i', $sql, $m);

        return array_values(array_unique(array_map(
            fn (string $t) => strtolower(str_contains($t, '.') ? substr($t, strrpos($t, '.') + 1) : $t),
            $m[1],
        )));
    }

    /** Removes quoted literals from database error messages (e.g. duplicate-key values). */
    public static function sanitizeMessage(string $message): string
    {
        return (string) preg_replace("/'(?:[^'\\\\]|\\\\.){1,500}'/", "'?'", $message);
    }
}
