<?php

declare(strict_types=1);

namespace Slop;

/**
 * Detects groups of methods that share an identical normalized body, which is
 * a common fingerprint of generated CRUD/boilerplate code.
 *
 * Getters, setters, __toString, __construct and other trivial accessors are
 * excluded: they are legitimately repetitive in hand-written code and were
 * the main source of false positives.
 */
final class StructureAnalyzer implements CodeAnalyzer
{
    private const METHOD =
        '~(?m)(?:^|[;{}])\s*'
        . '(?:(?:public|protected|private|static|final|abstract)\s+)*'
        . 'function\s+&?\s*(\w+)\s*\([^)]*\)\s*(?::\s*[^{;]+)?\{~';

    private const TEST_ANNOTATION = '~@test\b|#\[Test\]~i';

    public function analyze(AnalysisContext $context): void
    {
        $code = SourceText::stripCommentsAndLiterals($context->getCode());

        preg_match_all(
            self::METHOD,
            $code,
            $matches,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE
        );

        /** @var array<string, string[]> $bodies */
        $bodies = [];
        $candidateMethods = 0;

        foreach ($matches as $match) {
            $methodName = $match[1][0];
            $openingBrace = $match[0][1] + strlen($match[0][0]) - 1;
            $closingBrace = $this->findClosingBrace($code, $openingBrace);

            if ($closingBrace === -1) {
                continue;
            }

            $body = substr($code, $openingBrace + 1, $closingBrace - $openingBrace - 1);

            if ($this->isTrivial($methodName, $body)) {
                continue;
            }

            $normalized = $this->normalize($body);

            if (strlen($normalized) < 40) {
                continue;
            }

            $candidateMethods++;
            $bodies[$normalized][] = $methodName;
        }

        $duplicatedMethods = 0;
        $groups = [];

        foreach ($bodies as $methods) {
            if (count($methods) < 3) {
                continue;
            }

            $duplicatedMethods += count($methods);

            $names = $methods;
            sort($names);

            if (count($groups) < 3) {
                $groups[] = count($methods) . ' methods share a body ('
                    . $this->summarize($names) . ')';
            }
        }

        $meaningfulShare = $candidateMethods > 0
            && $duplicatedMethods * 10 >= $candidateMethods;

        if ($duplicatedMethods >= 6 && $meaningfulShare) {
            $context->addPoints(
                Categories::STRUCTURE,
                10,
                'Repeated normalized method bodies: '
                    . implode('; ', $groups)
            );
        } elseif ($duplicatedMethods >= 3 && $meaningfulShare) {
            $context->addPoints(
                Categories::STRUCTURE,
                5,
                'Several methods share a normalized body: '
                    . implode('; ', $groups)
            );
        }

        $testCount = AnalyzerSupport::countPattern(self::TEST_ANNOTATION, $code);

        if ($testCount >= 10 && $duplicatedMethods >= 3) {
            $context->addPoints(
                Categories::STRUCTURE,
                5,
                'Large test class (' . $testCount
                    . ' tests) with repeated method-body patterns.'
            );
        }
    }

    /**
     * Accessors and object plumbing are regularly duplicated in hand-written
     * code, so they are not evidence of generated code.
     */
    private function isTrivial(string $methodName, string $body): bool
    {
        $name = strtolower($methodName);

        if (
            str_starts_with($name, 'get')
            || str_starts_with($name, 'set')
            || str_starts_with($name, 'is')
            || str_starts_with($name, '__')
            || in_array($name, ['equals', 'hashcode', 'canequal', 'compareto'], true)
        ) {
            return true;
        }

        $compact = $this->normalize($body);

        if (preg_match('~^return\s+(?:\$this->)?\w+\s*;$~', $compact) === 1) {
            return true;
        }

        if (preg_match('~^(?:\$this->)?\w+\s*=\s*[^;]+;$~', $compact) === 1) {
            return true;
        }

        if (preg_match('~^parent::__construct\s*\([^;]*\)\s*;$~', $compact) === 1) {
            return true;
        }

        return AnalyzerSupport::count($compact, ';') <= 2;
    }

    private function normalize(string $body): string
    {
        return trim(preg_replace('~\s+~', ' ', $body) ?? '');
    }

    /** @param string[] $names */
    private function summarize(array $names): string
    {
        if (count($names) <= 4) {
            return implode(', ', $names);
        }

        return implode(', ', array_slice($names, 0, 4))
            . ', ... (' . count($names) . ' total)';
    }

    private function findClosingBrace(string $code, int $openingBrace): int
    {
        $depth = 0;

        for ($i = $openingBrace; $i < strlen($code); $i++) {
            $current = $code[$i];

            if ($current === '{') {
                $depth++;
            } elseif ($current === '}') {
                $depth--;

                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return -1;
    }
}
