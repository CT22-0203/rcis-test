<?php

declare(strict_types=1);

namespace Slop;

/**
 * Reviewer-style fingerprints that a professor or code reviewer usually points
 * out when reading generated PDO and test code.
 *
 * Each rule records the visible fragment that triggered it, so the report can
 * show a "Probable AI-origin Markers" table with a file/fragment, a reason and
 * a confidence band instead of only a numeric score.
 *
 * The rules cover hardcoded parameter binding loops, generic exception
 * wrappers, inline SQL with aliased columns, null-safe ternary mapping, and
 * generic class or return PHPDoc.
 */
final class ReviewPatternAnalyzer implements CodeAnalyzer
{
    private const BINDING_LOOP =
        '~\bfor\s*\(\s*\$(\w+)\s*=\s*\d+\s*;\s*'
        . '\$\1\s*<=?\s*\$?([A-Za-z_\\\\][\w:\\\\]*|\d+)\s*;\s*'
        . '\$\1\s*\+\+\s*\)~';

    private const INT_CONSTANT =
        '~(?m)^\s*(?:(?:public|private|protected|final)\s+)*'
        . 'const\s+([A-Z][A-Z0-9_]*)\s*=\s*\d+\s*;~';

    private const EXCEPTION_WITH_CAUSE =
        '~throw\s+new\s+\\\\?(?:\w*Exception)\s*\(\s*([\'"])(.*?)\1\s*'
        . '(?:,\s*(?:\d+|\$\w+)\s*)?,\s*\$\w+\s*\)~is';

    private const EXCEPTION_GENERIC =
        '~throw\s+new\s+\\\\?(?:\w*Exception)\s*\(\s*([\'"])'
        . '((?:Failed|Failure|Error|Unable|Could\s+not|Exception)'
        . '[^\'"]{0,160})\1\s*\)~i';

    private const LOWER_LIKE = '~LOWER\s*\([^)]*\)\s*LIKE\s*LOWER\s*\(\s*\?\s*\)~i';

    private const NULL_SAFE_TERNARY = '~(?:===?|!==?)\s*null\s*\?\s*null\s*:\s*\$\w+(?:->|::)\w+\s*\([^)]*\)~';

    private const DOC_BLOCK = '~/\*\*(.*?)\*/~s';

    private const GENERIC_CLASS_SENTENCE =
        '~^\s*(?:Implements|Provides|Handles|Manages|Represents|Defines'
        . '|Contains)\b[^.]{10,160}\.~i';

    private const GENERIC_CLASS_WORDS = [
        'database', 'operations', 'retrieval', 'searching', 'functionality',
        'data access', 'information', 'records', 'management', 'processing',
    ];

    private const GENERIC_RETURN = '~@return\s+(?:a|an|the)\s+(?:new\s+)?(?:list|collection|map|set|array)\s+of\b~i';

    public function analyze(AnalysisContext $context): void
    {
        $raw = $context->getCode();
        $structure = SourceText::stripCommentsAndLiterals($raw);

        $this->scoreBindingLoop($context, $raw, $structure);
        $this->scoreRuntimeExceptionWrapper($context, $raw, $structure);
        $this->scoreInlineSql($context, $raw, $structure);
        $this->scoreNullSafeMapping($context, $raw, $structure);
        $this->scoreGenericClassDoc($context);
        $this->scoreGenericReturnDoc($context);
    }

    /**
     * Generated search code often binds parameters through a loop driven by a
     * hardcoded count, sometimes hidden behind a constant such as
     * SEARCH_PARAMETER_COUNT. The loop and the SQL text can then drift apart
     * without an error.
     */
    private function scoreBindingLoop(
        AnalysisContext $context,
        string $raw,
        string $structure
    ): void {
        $parameterCountConstant = $this->findParameterCountConstant($structure);

        if (preg_match(self::BINDING_LOOP, $structure, $loop) !== 1) {
            if ($parameterCountConstant !== null) {
                $context->addFinding(
                    Categories::STRUCTURE,
                    3,
                    "'Abstracted but brittle' " . $parameterCountConstant
                        . ' constant is coupled to the SQL text; if the '
                        . 'query changes, the binding silently breaks.',
                    $this->declarationLine($raw, $parameterCountConstant)
                );
            }

            return;
        }

        $variable = $loop[1];
        $bound = $loop[2];

        $binding = '~bind(?:Value|Param)?\s*\(\s*'
            . preg_quote('$' . $variable, '~') . '\s*,~i';

        if (preg_match($binding, $structure) !== 1) {
            return;
        }

        $fragment = AnalyzerSupport::matchedLine($raw, self::BINDING_LOOP);

        if ($bound === $parameterCountConstant) {
            $context->addFinding(
                Categories::STRUCTURE,
                5,
                "JDBC binding loop driven by the 'abstracted but brittle' "
                    . $bound . ' constant; if the SQL changes, the bind '
                    . 'count silently drifts.',
                $fragment
            );
        } else {
            $context->addFinding(
                Categories::STRUCTURE,
                4,
                'JDBC binding loop with a hardcoded parameter count is a '
                    . 'common LLM shortcut; the loop and the query can '
                    . 'silently fall out of sync.',
                $fragment
            );
        }
    }

    /**
     * A catch block that replaces the original failure with
     * throw new RuntimeException("Failed to ...", 0, $e) loses the domain
     * meaning of the error, which is a common generated-code reflex.
     */
    private function scoreRuntimeExceptionWrapper(
        AnalysisContext $context,
        string $raw,
        string $structure
    ): void {
        $message = null;
        $fragment = '';

        if (preg_match(self::EXCEPTION_WITH_CAUSE, $raw, $matches) === 1) {
            $message = $matches[2];
            $fragment = AnalyzerSupport::matchedLine(
                $raw,
                self::EXCEPTION_WITH_CAUSE
            );
        } elseif (
            preg_match(self::EXCEPTION_GENERIC, $raw, $matches) === 1
            && str_contains($structure, 'catch')
        ) {
            $message = $matches[2];
            $fragment = AnalyzerSupport::matchedLine(
                $raw,
                self::EXCEPTION_GENERIC
            );
        }

        if ($message === null) {
            return;
        }

        $context->addFinding(
            Categories::LOGIC,
            4,
            'Generic exception wrapper with a message ("'
                . AnalyzerSupport::truncate($message, 45)
                . '") is a common reflex; prefer a domain-specific '
                . 'exception and log the context.',
            $fragment
        );

        $context->addTechnicalNote(
            'Use a domain-specific exception and log the original cause '
                . 'instead of wrapping it in a generic exception.'
        );
    }

    /**
     * Generated search repositories tend to inline the whole SQL string and
     * alias columns (p.id AS p_id) instead of keeping query constants, often
     * with the same LOWER(...) LIKE LOWER(?) predicate repeated.
     */
    private function scoreInlineSql(
        AnalysisContext $context,
        string $raw,
        string $structure
    ): void {
        if (
            !$this->isDaoImpl($context)
            || preg_match('~\bprepare\s*\(~', $structure) !== 1
        ) {
            return;
        }

        $lowerLikes = AnalyzerSupport::countPattern(self::LOWER_LIKE, $raw);
        $alias = false;
        $fragment = '';

        foreach (AnalyzerSupport::stringLiterals($raw) as $sql) {
            $sql = trim($sql);

            if (strlen($sql) < 20 || strlen($sql) > 500) {
                continue;
            }

            $lower = strtolower($sql);

            if (!str_starts_with($lower, 'select ')) {
                continue;
            }

            if ($fragment === '') {
                $fragment = AnalyzerSupport::truncate($sql, 90);
            }

            if (
                str_contains($lower, ' as ')
                && preg_match('~\bas\s+[a-z]\w*_[a-z]\w*~', $lower) === 1
            ) {
                $alias = true;
                $fragment = AnalyzerSupport::truncate($sql, 90);
                break;
            }
        }

        if ($lowerLikes >= 2) {
            $fragment = AnalyzerSupport::matchedLine($raw, self::LOWER_LIKE);
        }

        if (!$alias && $lowerLikes < 2) {
            return;
        }

        $reason = 'Inline SQL in the repository instead of query constants, '
            . 'with aliased columns (for example p.id AS p_id) and/or '
            . 'repeated LOWER(...) LIKE LOWER(?) predicates. A very common '
            . 'AI-generated search pattern; the repeated LOWER() calls are '
            . 'also index-unfriendly.';

        $context->addFinding(Categories::STRUCTURE, 5, $reason, $fragment);
    }

    /**
     * Compact null-safe ternaries in row mappers are good practice, but the
     * exact value === null ? null : value->toX() shape is a frequent
     * generated-code fingerprint, so it carries a small weight only.
     */
    private function scoreNullSafeMapping(
        AnalysisContext $context,
        string $raw,
        string $structure
    ): void {
        if (!$this->isDaoImpl($context)) {
            return;
        }

        if (preg_match(self::NULL_SAFE_TERNARY, $structure) === 1) {
            $context->addFinding(
                Categories::LOGIC,
                1,
                'Null-safe mapping is good practice, but the compact '
                    . 'ternary style is common in AI-generated row '
                    . 'mappers.',
                AnalyzerSupport::matchedLine($raw, self::NULL_SAFE_TERNARY)
            );
        }
    }

    /**
     * A polished class PHPDoc that only restates the obvious ("Implements
     * database patient searching and medical record retrieval.") is typical
     * of generated documentation.
     */
    private function scoreGenericClassDoc(AnalysisContext $context): void
    {
        if (!$this->isDaoOrTest($context)) {
            return;
        }

        $text = $this->firstDocText($context->getComments());

        if ($text === '') {
            return;
        }

        if (preg_match(self::GENERIC_CLASS_SENTENCE, $text, $matches) !== 1) {
            return;
        }

        $value = trim($matches[0]);
        $lower = strtolower($value);
        $generic = false;

        foreach (self::GENERIC_CLASS_WORDS as $word) {
            if (str_contains($lower, $word)) {
                $generic = true;
                break;
            }
        }

        if (!$generic) {
            return;
        }

        $context->addFinding(
            Categories::COMMENTS,
            3,
            'Polished, generic class PHPDoc that restates the obvious ("'
                . AnalyzerSupport::truncate($value, 70)
                . '"). Very common LLM phrasing.',
            AnalyzerSupport::truncate($value, 90)
        );
    }

    /**
     * "@return a list of ..." restates the signature without adding
     * information, which is typical of generated documentation.
     */
    private function scoreGenericReturnDoc(AnalysisContext $context): void
    {
        if (!$this->isDaoOrTest($context)) {
            return;
        }

        if (preg_match(self::GENERIC_RETURN, $context->getComments(), $matches) === 1) {
            $value = AnalyzerSupport::truncate($matches[0], 60);

            $context->addFinding(
                Categories::COMMENTS,
                3,
                'Generic PHPDoc restating the return type ("' . $value
                    . '") is typical AI documentation.',
                $value
            );
        }
    }

    private function findParameterCountConstant(string $structure): ?string
    {
        if (preg_match_all(self::INT_CONSTANT, $structure, $matches) === false) {
            return null;
        }

        foreach ($matches[1] as $name) {
            if (str_contains($name, 'PARAM') && str_contains($name, 'COUNT')) {
                return $name;
            }
        }

        return null;
    }

    private function declarationLine(string $raw, string $name): string
    {
        foreach (preg_split('~\R~', $raw) ?: [] as $line) {
            if (
                str_contains($line, $name)
                && (str_contains($line, 'const') || str_contains($line, 'static'))
            ) {
                return trim($line);
            }
        }

        return '';
    }

    private function isDaoImpl(AnalysisContext $context): bool
    {
        return preg_match(
            '~(?:Repository|Dao|DaoImpl)\.php$~i',
            $context->getFile()
        ) === 1;
    }

    private function isDaoOrTest(AnalysisContext $context): bool
    {
        return preg_match(
            '~(?:Repository|Dao|DaoImpl|Service|ServiceImpl|Test|Tests)\.php$~i',
            $context->getFile()
        ) === 1;
    }

    /**
     * Returns the text of the first PHPDoc block with its leading asterisks
     * removed, so a sentence rule can run against the class description.
     */
    private function firstDocText(string $comments): string
    {
        if (preg_match(self::DOC_BLOCK, $comments, $matches) !== 1) {
            return '';
        }

        $text = preg_replace('~(?m)^\s*\*+\s?~', ' ', $matches[1]) ?? '';
        $text = preg_replace('~\s+~', ' ', $text) ?? '';

        return trim($text);
    }
}
