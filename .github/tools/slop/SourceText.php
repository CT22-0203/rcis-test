<?php

declare(strict_types=1);

namespace Slop;

/**
 * Dependency-free helpers for separating PHP code from its comments and
 * string literals.
 *
 * Naive regular expressions over raw source produce two kinds of mistakes:
 * rules match inside comments or string literals (inflating scores), and brace
 * counting breaks when a string contains "{" or "}". Both problems disappear
 * once literals and comments are removed before the code checks run.
 *
 * The PHP tokenizer is used instead of hand-written scanners, so heredocs,
 * nowdocs, interpolated strings and attributes are handled correctly.
 */
final class SourceText
{
    /** Files containing this marker are skipped by the detector. */
    private const SUPPRESSION = '~@generated\b|ai-detect\s*:\s*ignore|slop-ignore~i';

    /**
     * Attribute/annotation form of the opt-out. It is checked against source
     * with string literals intact, because the annotation value is a string.
     */
    private const ANNOTATION_SUPPRESSION = '~@SuppressWarnings\s*\([^)]*[\'"](?:ai-detect|slop)[\'"]~i';

    private function __construct()
    {
    }

    /**
     * Removes comments but keeps string and char literals intact. Use this
     * when the rule needs to inspect literal content (SQL text, messages).
     */
    public static function stripComments(string $source): string
    {
        return self::transform($source, false);
    }

    /**
     * Removes comments and blanks out string literals while preserving line
     * breaks, so line-based heuristics still see the original layout.
     */
    public static function stripCommentsAndLiterals(string $source): string
    {
        return self::transform($source, true);
    }

    private static function transform(string $source, bool $stripLiterals): string
    {
        $code = '';
        $tokens = token_get_all($source);
        $inString = false;

        foreach ($tokens as $token) {
            if (is_array($token)) {
                $id = $token[0];
                $text = $token[1];

                if ($id === T_COMMENT || $id === T_DOC_COMMENT) {
                    $code .= self::blankPreservingNewlines($text);
                    continue;
                }

                if ($id === T_START_HEREDOC) {
                    $inString = true;
                    continue;
                }

                if ($id === T_END_HEREDOC) {
                    $inString = false;
                    $code .= '""';
                    continue;
                }

                if ($id === T_INLINE_HTML) {
                    $code .= self::blankPreservingNewlines($text);
                    continue;
                }

                if (
                    $stripLiterals && ($inString
                        || $id === T_CONSTANT_ENCAPSED_STRING
                        || $id === T_ENCAPSED_AND_WHITESPACE)
                ) {
                    $code .= self::blankPreservingNewlines($text);
                    continue;
                }

                $code .= $text;
                continue;
            }

            if ($token === '"' || $token === '`') {
                if ($stripLiterals) {
                    $inString = !$inString;
                    $code .= '""';
                } else {
                    $code .= $token;
                }
                continue;
            }

            if ($stripLiterals && $inString) {
                $code .= self::blankPreservingNewlines($token);
                continue;
            }

            $code .= $token;
        }

        return $code;
    }

    /**
     * Collects only the comment text of a source file.
     */
    public static function extractComments(string $source): string
    {
        $comments = '';

        foreach (token_get_all($source) as $token) {
            if (
                is_array($token)
                    && ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT)
            ) {
                $comments .= $token[1] . "\n";
            }
        }

        return $comments;
    }

    /** Number of lines, with a sensible minimum of one for empty input. */
    public static function countLines(string $text): int
    {
        if ($text === '') {
            return 1;
        }

        return substr_count($text, "\n") + 1;
    }

    /**
     * True when the file opts out of detection or is generated code.
     *
     * Only comments and code are inspected, so a marker that merely appears
     * inside a string literal (for example a test fixture or a message) does
     * not silently skip the whole file.
     */
    public static function isSuppressed(string $source): bool
    {
        return preg_match(self::SUPPRESSION, self::extractComments($source)) === 1
            || preg_match(
                self::SUPPRESSION,
                self::stripCommentsAndLiterals($source)
            ) === 1
            || preg_match(
                self::ANNOTATION_SUPPRESSION,
                self::stripComments($source)
            ) === 1;
    }

    /** Blanks every character except line breaks. */
    private static function blankPreservingNewlines(string $text): string
    {
        return preg_replace('~[^\r\n]~', ' ', $text) ?? '';
    }
}
