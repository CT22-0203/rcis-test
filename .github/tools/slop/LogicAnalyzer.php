<?php

declare(strict_types=1);

namespace Slop;

/**
 * Business-logic anti-patterns that show up frequently in generated
 * repository/service code: swallowing database errors into empty results,
 * returning null from a catch block, and building SQL by concatenation.
 *
 * Patterns are scoped to the catch block or to SQL construction, so an empty
 * array literal elsewhere in the file is not treated as a fallback.
 */
final class LogicAnalyzer implements CodeAnalyzer
{
    private const CATCH_RETURNS_EMPTY_COLLECTION =
        '~catch\s*\([^)]*\)\s*\{\s*return\s+'
        . '(?:\[\s*\]|array\s*\(\s*\)|collect\s*\(\s*\))\s*;~';

    private const CATCH_RETURNS_NULL = '~catch\s*\([^)]*\)\s*\{\s*return\s+null\s*;~';

    private const DYNAMIC_SQL = '~where\s+1\s*=\s*1~i';

    private const SQL_CONCATENATION = '~[\'"]\s*(?:select|insert|update|delete)\b[^\'"]*[\'"]\s*\.\s*\$[A-Za-z_]~i';

    private const SQL_BUILDER = '~(?:\$sql|\$query|\$where|\$sqlQuery)\s*(?:\.=|=)[^;]*\.~i';

    public function analyze(AnalysisContext $context): void
    {
        $raw = $context->getCode();

        // SQL rules need the literal text, so only comments are removed.
        $code = SourceText::stripComments($raw);

        // Control-flow rules must not match text that only appears inside a
        // string, so literals are blanked for those checks.
        $structure = SourceText::stripCommentsAndLiterals($raw);

        if (preg_match(self::CATCH_RETURNS_EMPTY_COLLECTION, $structure) === 1) {
            $context->addPoints(
                Categories::LOGIC,
                6,
                'Database or service exception is swallowed by returning an '
                    . 'empty collection.'
            );

            $context->addTechnicalNote(
                'Review whether database failures should be logged or propagated '
                    . 'instead of returning an empty collection.'
            );
        }

        if (preg_match(self::CATCH_RETURNS_NULL, $structure) === 1) {
            $context->addPoints(
                Categories::LOGIC,
                5,
                'Exception handler returns null directly.'
            );

            $context->addTechnicalNote(
                'Review exception handling and null-return behavior.'
            );
        }

        $hasBuilder = preg_match(self::SQL_BUILDER, $structure) === 1;

        if ($hasBuilder && preg_match(self::DYNAMIC_SQL, $code) === 1) {
            $context->addPoints(
                Categories::LOGIC,
                4,
                'Dynamic SQL built with an always-true WHERE clause; '
                    . 'review query construction.'
            );

            $context->addTechnicalNote(
                'Review dynamic SQL for injection risk and parameterization.'
            );
        }

        if (preg_match(self::SQL_CONCATENATION, $code) === 1) {
            $context->addPoints(
                Categories::LOGIC,
                4,
                'SQL string built by concatenating a variable into the query.'
            );

            $context->addTechnicalNote(
                'Prefer prepared statement parameters over SQL concatenation.'
            );
        }
    }
}
