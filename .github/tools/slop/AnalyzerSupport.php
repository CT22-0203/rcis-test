<?php

declare(strict_types=1);

namespace Slop;

/**
 * Shared helpers for counting and for normalizing counts by file size.
 */
final class AnalyzerSupport
{
    private function __construct()
    {
    }

    public static function count(string $text, string $needle): int
    {
        if ($needle === '') {
            return 0;
        }

        return substr_count($text, $needle);
    }

    public static function countPattern(string $pattern, string $text): int
    {
        $total = preg_match_all($pattern, $text);

        return $total === false ? 0 : $total;
    }

    /**
     * Normalizes a raw count so large files are not punished for their size.
     * Files up to 300 lines are compared as-is; bigger files need to show a
     * proportionally larger number of indicators.
     */
    public static function scaleForSize(int $count, int $lines): float
    {
        $factor = max(1.0, $lines / 300.0);

        return $count / $factor;
    }

    public static function truncate(string $text, int $maxLength): string
    {
        $compact = preg_replace('~\s+~u', ' ', trim($text)) ?? '';

        if (strlen($compact) <= $maxLength) {
            return $compact;
        }

        return rtrim(substr($compact, 0, $maxLength)) . '...';
    }

    /**
     * Returns the first line that the pattern matches, trimmed and shortened
     * so it can be shown in the report as a visible code fragment.
     */
    public static function matchedLine(string $source, string $pattern): string
    {
        if (preg_match($pattern, $source, $matches, PREG_OFFSET_CAPTURE) !== 1) {
            return '';
        }

        $match = $matches[0][0];
        $offset = $matches[0][1];

        $lineStart = strrpos(substr($source, 0, $offset), "\n");
        $lineStart = $lineStart === false ? 0 : $lineStart + 1;

        $lineEnd = strpos($source, "\n", $offset + strlen($match));
        $lineEnd = $lineEnd === false ? strlen($source) : $lineEnd;

        return self::truncate(substr($source, $lineStart, $lineEnd - $lineStart), 90);
    }

    /**
     * Extracts the readable value of every constant string literal in a file.
     *
     * Interpolated strings are skipped intentionally: their pieces are not a
     * complete message, and the raw source is still inspected by rules that
     * need to see concatenation.
     *
     * @return string[]
     */
    public static function stringLiterals(string $source): array
    {
        $literals = [];

        foreach (token_get_all($source) as $token) {
            if (!is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }

            $literal = $token[1];
            $quote = $literal[0];
            $body = substr($literal, 1, -1);

            if ($quote === "'") {
                $body = str_replace(["\\\\", "\\'"], ["\\", "'"], $body);
            } else {
                $body = stripcslashes($body);
            }

            $literals[] = $body;
        }

        return $literals;
    }
}
