<?php

declare(strict_types=1);

namespace Trk\Monitor\Support;

use Throwable;

/**
 * Converts exceptions into the TrkMonitor exception payload.
 *
 * Frames are ordered innermost first (the throw site is frame 0). Paths are made relative
 * to the application base path; frames outside vendor/ are flagged as in-app. Function
 * arguments are never transmitted.
 */
final class StackTraceBuilder
{
    private const CONTEXT_LINES = 5;

    private const MAX_FRAMES = 100;

    private const MAX_CHAIN = 5;

    /** @var array<string, list<string>|false> */
    private array $fileCache = [];

    public function __construct(
        private readonly string $basePath,
        private readonly bool $sourceContext = true,
    ) {}

    /** @return list<array<string, mixed>> Outermost exception first, then previous exceptions. */
    public function exceptions(Throwable $e): array
    {
        $values = [];
        $seen = [];

        for ($current = $e; $current !== null && count($values) < self::MAX_CHAIN; $current = $current->getPrevious()) {
            $id = spl_object_id($current);
            if (isset($seen[$id])) {
                break;
            }
            $seen[$id] = true;

            $values[] = [
                'type' => $current::class,
                'value' => mb_substr($this->message($current), 0, 8192),
                'code' => $current->getCode(),
                'stacktrace' => ['frames' => $this->frames($current)],
            ];
        }

        return $values;
    }

    /**
     * Database errors embed the SQL with interpolated bindings and offending values in the
     * message; those are replaced by the sanitized statement and placeholders.
     */
    private function message(Throwable $e): string
    {
        if ($e instanceof \Illuminate\Database\QueryException) {
            $driverMessage = $e->getPrevious()?->getMessage() ?? 'Query failed';

            return SqlSanitizer::sanitizeMessage($driverMessage).' (Connection: '.$e->getConnectionName().', SQL: '.SqlSanitizer::sanitize($e->getSql()).')';
        }

        if ($e instanceof \PDOException) {
            return SqlSanitizer::sanitizeMessage($e->getMessage());
        }

        return $e->getMessage();
    }

    /** @return list<array<string, mixed>> */
    public function frames(Throwable $e): array
    {
        // The throw site is not part of getTrace(); prepend it as frame 0.
        $trace = $e->getTrace();
        $frames = [];
        $file = $e->getFile();
        $line = $e->getLine();

        foreach ($trace as $entry) {
            $frames[] = $this->frame($file, $line, $entry['function'], $entry['class'] ?? null);
            $file = $entry['file'] ?? '';
            $line = $entry['line'] ?? 0;

            if (count($frames) >= self::MAX_FRAMES) {
                break;
            }
        }

        if (count($frames) < self::MAX_FRAMES) {
            $frames[] = $this->frame($file, $line, '{main}', null);
        }

        return $frames;
    }

    /** @return array<string, mixed> */
    private function frame(string $file, int $line, ?string $function, ?string $class): array
    {
        $inApp = $this->isInApp($file);

        $frame = array_filter([
            'file' => $file === '' ? null : $this->relative($file),
            'line' => $line > 0 ? $line : null,
            'function' => $function,
            'class' => $class,
        ], fn ($v) => $v !== null);
        $frame['in_app'] = $inApp;

        if ($this->sourceContext && $inApp && $file !== '' && $line > 0) {
            $frame += $this->context($file, $line);
        }

        return $frame;
    }

    public function isInApp(string $file): bool
    {
        if ($file === '') {
            return false;
        }

        $normalized = str_replace('\\', '/', $file);
        $base = rtrim(str_replace('\\', '/', $this->basePath), '/').'/';

        return str_starts_with($normalized, $base)
            && ! str_contains($normalized, '/vendor/')
            && ! str_contains($normalized, '/storage/framework/');
    }

    private function relative(string $file): string
    {
        $normalized = str_replace('\\', '/', $file);
        $base = rtrim(str_replace('\\', '/', $this->basePath), '/').'/';

        return str_starts_with($normalized, $base) ? substr($normalized, strlen($base)) : $normalized;
    }

    /** @return array{pre_context?: list<string>, context_line?: string, post_context?: list<string>} */
    private function context(string $file, int $line): array
    {
        if (! array_key_exists($file, $this->fileCache)) {
            $this->fileCache[$file] = is_file($file) && is_readable($file) && filesize($file) < 2_000_000
                ? (@file($file, FILE_IGNORE_NEW_LINES) ?: false)
                : false;
        }

        $lines = $this->fileCache[$file];

        if ($lines === false || ! isset($lines[$line - 1])) {
            return [];
        }

        $start = max(0, $line - 1 - self::CONTEXT_LINES);
        $clip = fn (string $l) => mb_substr($l, 0, 500);

        return [
            'pre_context' => array_map($clip, array_slice($lines, $start, $line - 1 - $start)),
            'context_line' => $clip($lines[$line - 1]),
            'post_context' => array_map($clip, array_slice($lines, $line, self::CONTEXT_LINES)),
        ];
    }
}
