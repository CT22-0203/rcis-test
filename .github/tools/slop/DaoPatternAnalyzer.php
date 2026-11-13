<?php

declare(strict_types=1);

namespace Slop;

/**
 * Repository/DAO-specific fingerprints of generated PDO code.
 *
 * The patterns mirror what a reviewer notices when reading a generated
 * repository: the same generic exception sentence repeated in every method,
 * PHPDoc on every constant and private helper, a shared COLUMNS constant
 * reused by several queries, a toX(array $row) mapper, an ID generated inside
 * the repository, and the manual
 * "$index = 1; bindValue($index, ...); $index++;" binding style.
 *
 * This analyzer only runs on classes whose name ends with Repository, Dao or
 * DaoImpl, so ordinary services, models and tests are not affected.
 */
final class DaoPatternAnalyzer implements CodeAnalyzer
{
    private const CLASS_NAME = '~\b(?:class|interface|trait)\s+(\w+)~i';

    private const EXCEPTION_MESSAGE = '~^\s*(?:an?\s+)?(?:exception|error|failed|failure|unable|could\s+not)\b.*~i';

    private const INDEX_INCREMENT = '~\$index\s*\+\+~';

    private const INDEX_BINDING = '~\bbind(?:Value|Param)?\s*\(\s*\$index\b~i';

    private const SQL_CONSTANT = '~(?m)^\s*(?:(?:public|protected|private)\s+)?const\s+[A-Z_]+\s*=~';

    private const TRANSACTION_BLOCK = '~\bfinally\s*\{~';

    private const ROW_MAPPER = '~\bfunction\s+to[A-Z]\w*\s*\(~';

    private const NULL_GUARD_CHAIN = '~if\s*\([^)]*(?:===?|!==?)\s*null[^)]*\|\|[^)]*(?:===?|!==?)\s*null~';

    private const DOC_BLOCK = '~/\*\*~';

    private const METHOD_DECLARATION =
        '~(?m)^\s*(?:(?:public|protected|private|static|final|abstract)\s+)*'
        . 'function\s+\w+\s*\([^;{]*\)\s*(?::\s*[^{]+)?\{~';

    private const FIELD_DECLARATION =
        '~(?m)^\s*(?:(?:public|protected|private|static|readonly|var|final)\s+)+'
        . '(?:\??[A-Za-z_\\\\][\w\\\\|]*\s+)?\$?\w+\s*(?:=|;)~';

    private const CONST_DECLARATION = '~(?m)^\s*(?:(?:public|protected|private|final)\s+)*const\s+\w+\s*=~';

    private const GENERIC_DAO_DOC = [
        'implements the data access operations',
        'the sql statement that',
        'the columns selected for',
        'the current row of the result set',
        'the result set positioned at',
    ];

    public function analyze(AnalysisContext $context): void
    {
        $raw = $context->getCode();
        $code = SourceText::stripCommentsAndLiterals($raw);

        $className = '';
        if (preg_match(self::CLASS_NAME, $code, $matches) === 1) {
            $className = $matches[1];
        }

        if (!$this->isDaoName($className, $context->getFile())) {
            return;
        }

        $this->scoreRepeatedExceptionMessages($context, $raw);
        $this->scoreDocumentedMembers($context, $raw);
        $this->scoreManualIndexBinding($context, $code);
        $this->scoreDaoStructure($context, $code);
        $this->scoreNullGuardChain($context, $code);
        $this->scoreGenericDaoDoc($context, $context->getComments());
    }

    /**
     * The same generated exception sentence usually appears in every method,
     * for example "An exception was thrown while finding a record." and
     * "An exception was thrown while adding a record."
     */
    private function scoreRepeatedExceptionMessages(
        AnalysisContext $context,
        string $raw
    ): void {
        /** @var array<string, int> $templates */
        $templates = [];
        $worst = '';
        $worstCount = 0;

        foreach (AnalyzerSupport::stringLiterals($raw) as $message) {
            $message = trim($message);

            if (strlen($message) < 12 || strlen($message) > 200) {
                continue;
            }

            if (preg_match(self::EXCEPTION_MESSAGE, $message) !== 1) {
                continue;
            }

            $template = $this->normalizeMessageTemplate($message);
            $templates[$template] = ($templates[$template] ?? 0) + 1;
            $count = $templates[$template];

            if ($count > $worstCount) {
                $worstCount = $count;
                $worst = $template;
            }
        }

        if ($worstCount >= 2) {
            $context->addPoints(
                Categories::LOGIC,
                4,
                'The same generic exception message is repeated '
                    . $worstCount . ' times: "' . $worst . ' ..."'
            );

            $context->addTechnicalNote(
                'Replace the repeated console messages with real logging '
                    . 'and propagate the failure to the caller.'
            );
        }
    }

    /**
     * Generated repositories document constants and private helpers that
     * hand-written code usually leaves undocumented.
     */
    private function scoreDocumentedMembers(
        AnalysisContext $context,
        string $raw
    ): void {
        $docs = AnalyzerSupport::countPattern(self::DOC_BLOCK, $raw);
        $members = AnalyzerSupport::countPattern(self::METHOD_DECLARATION, $raw)
            + AnalyzerSupport::countPattern(self::FIELD_DECLARATION, $raw)
            + AnalyzerSupport::countPattern(self::CONST_DECLARATION, $raw);

        if ($members < 6) {
            return;
        }

        $coverage = min(1.0, $docs / $members);

        if ($coverage >= 0.9) {
            $context->addPoints(
                Categories::COMMENTS,
                4,
                'PHPDoc on nearly every constant, field and private helper ('
                    . $docs . ' blocks for ' . $members . ' members).'
            );
        }
    }

    /**
     * "$index = 1; bindValue($index, ...); $index++;" is readable, but the
     * verbose increment style is very common in generated PDO code.
     */
    private function scoreManualIndexBinding(
        AnalysisContext $context,
        string $code
    ): void {
        $increments = AnalyzerSupport::countPattern(self::INDEX_INCREMENT, $code);
        $bindings = AnalyzerSupport::countPattern(self::INDEX_BINDING, $code);

        if ($increments >= 2 && $bindings >= 2) {
            $context->addPoints(
                Categories::STRUCTURE,
                4,
                'Manual parameter index incremented ' . $increments
                    . ' times while binding ' . $bindings
                    . ' statement values ($index = 1; ... $index++).'
            );
        }
    }

    /**
     * Counts the structural features of the common generated repository
     * template: static SQL constants, a shared COLUMNS constant, a
     * toX(array $row) mapper, generated identifiers, transaction blocks and
     * null binding.
     */
    private function scoreDaoStructure(
        AnalysisContext $context,
        string $code
    ): void {
        $features = 0;
        $found = [];

        if (preg_match(self::SQL_CONSTANT, $code) === 1) {
            $features++;
            $found[] = 'static SQL constants';
        }

        if (AnalyzerSupport::countPattern('~\bCOLUMNS\b~', $code) >= 2) {
            $features++;
            $found[] = 'shared COLUMNS constant';
        }

        if (preg_match(self::ROW_MAPPER, $code) === 1) {
            $features++;
            $found[] = 'toX(array $row) mapper';
        }

        if (preg_match('~Ramsey\\\\Uuid|Uuid::|random_bytes|uniqid\s*\(~i', $code) === 1) {
            $features++;
            $found[] = 'identifier generated inside the repository';
        }

        if (preg_match(self::TRANSACTION_BLOCK, $code) === 1) {
            $features++;
            $found[] = 'transaction/finally handling';
        }

        if (preg_match('~PDO::PARAM_NULL|bindValue\s*\([^)]*,\s*null~i', $code) === 1) {
            $features++;
            $found[] = 'null binding handling';
        }

        if ($features >= 4) {
            $context->addPoints(
                Categories::STRUCTURE,
                6,
                'Standard AI repository template: ' . implode(', ', $found) . '.'
            );
        } elseif ($features === 3) {
            $context->addPoints(
                Categories::STRUCTURE,
                4,
                'Several generated-repository structure features: '
                    . implode(', ', $found) . '.'
            );
        }
    }

    private function scoreNullGuardChain(
        AnalysisContext $context,
        string $code
    ): void {
        if (preg_match(self::NULL_GUARD_CHAIN, $code) === 1) {
            $context->addPoints(
                Categories::LOGIC,
                2,
                'Defensive null-guard chain on several object levels.'
            );
        }
    }

    private function scoreGenericDaoDoc(
        AnalysisContext $context,
        string $comments
    ): void {
        $lower = strtolower($comments);
        $distinct = 0;

        foreach (self::GENERIC_DAO_DOC as $phrase) {
            if (str_contains($lower, $phrase)) {
                $distinct++;
            }
        }

        if ($distinct >= 3) {
            $context->addPoints(
                Categories::COMMENTS,
                4,
                'Polished, generic repository PHPDoc using ' . $distinct
                    . ' recurring description templates.'
            );
        } elseif ($distinct === 2) {
            $context->addPoints(
                Categories::COMMENTS,
                2,
                'Generic repository PHPDoc description templates detected.'
            );
        }
    }

    private function isDaoName(string $className, string $file): bool
    {
        if (preg_match('~(?:Repository|Dao|DaoImpl)\.php$~i', $file) === 1) {
            return true;
        }

        return (bool) preg_match('~(?:Repository|Dao|DaoImpl)$~i', $className);
    }

    private function normalizeMessageTemplate(string $message): string
    {
        $words = preg_split('~\s+~', trim($message)) ?: [];
        $template = implode(' ', array_slice($words, 0, 5));

        $template = strtolower($template);
        $template = preg_replace('~[^a-z ]~', '', $template) ?? '';

        return trim($template);
    }
}
