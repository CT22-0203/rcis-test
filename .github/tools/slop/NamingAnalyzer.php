<?php

declare(strict_types=1);

namespace Slop;

/**
 * Looks for the generic, low-information identifiers that generated code
 * tends to reuse ($data, $result, $temp1, ...).
 *
 * Only real declarations are considered: comments and string literals are
 * removed first, and counts are normalized by file size so a large,
 * well-written class does not accumulate points.
 */
final class NamingAnalyzer implements CodeAnalyzer
{
    private const PLACEHOLDER = '~\b(?:test|method|variable|temp|object|foo|bar|baz|sample|example|demo|item)\d+\b~i';

    private const GENERIC_NAMES = [
        'data', 'result', 'results', 'temp', 'tmp', 'value', 'values',
        'object', 'obj', 'flag', 'response', 'list', 'map', 'item',
        'element', 'output', 'input', 'info', 'params', 'args',
    ];

    private const CLASS_NAME = '~\b(?:class|interface|trait|enum)\s+(\w+)~i';

    private const FUNCTION_NAME = '~\bfunction\s+&?\s*([a-z_][A-Za-z0-9_]*)\s*\(~i';

    private const TEST_CLASS = '~extends\s+TestCase|PHPUnit\\\\Framework\\\\TestCase|#\[Test\]|@test\b~i';

    public function analyze(AnalysisContext $context): void
    {
        $code = SourceText::stripCommentsAndLiterals($context->getCode());
        $lines = $context->getLineCount();

        /** @var array<string, int> $counts */
        $counts = [];

        foreach ($this->declaredNames($code) as $name) {
            $key = strtolower($name);
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        $genericNames = [];
        $genericDeclarations = 0;

        foreach (self::GENERIC_NAMES as $name) {
            $occurrences = $counts[$name] ?? 0;

            if ($occurrences >= 2) {
                $genericDeclarations += $occurrences;
                $genericNames[] = $name . ' (x' . $occurrences . ')';
            }
        }

        $distinctNames = count($genericNames);
        $scaledDeclarations = AnalyzerSupport::scaleForSize(
            $genericDeclarations,
            $lines
        );

        if ($distinctNames >= 3 && $scaledDeclarations >= 9) {
            $context->addPoints(
                Categories::NAMING,
                6,
                'Several generic variable names are repeatedly declared: '
                    . $this->summarize($genericNames)
            );
        } elseif ($distinctNames >= 2 && $scaledDeclarations >= 6) {
            $context->addPoints(
                Categories::NAMING,
                3,
                'Repeated generic variable declarations: '
                    . $this->summarize($genericNames)
            );
        }

        $placeholders = AnalyzerSupport::countPattern(self::PLACEHOLDER, $code);

        if ($placeholders >= 3) {
            $context->addPoints(
                Categories::NAMING,
                6,
                $placeholders . ' numbered placeholder identifiers detected.'
            );
        } elseif ($placeholders >= 2) {
            $context->addPoints(
                Categories::NAMING,
                3,
                $placeholders . ' numbered placeholder identifiers detected.'
            );
        }

        $this->scoreSentenceStyleTestNames($context, $code);
    }

    /**
     * Collects variables that are actually declared: properties, typed
     * parameters, local assignments, foreach bindings and catch variables.
     *
     * @return string[]
     */
    private function declaredNames(string $code): array
    {
        $names = [];

        $patterns = [
            // Properties and promoted constructor properties.
            '~\b(?:public|protected|private|static|readonly|var)\s+'
                . '(?:\??[A-Za-z_\\\\][\w\\\\|]*\s+)?\$([a-z_][A-Za-z0-9_]*)~',
            // Local assignments at the start of a statement.
            '~(?:^|[;{}])\s*\$([a-z_][A-Za-z0-9_]*)\s*=~m',
            // catch (Exception $e)
            '~\bcatch\s*\([^)]*\$([a-z_][A-Za-z0-9_]*)~',
            // foreach ($items as $item)
            '~\bforeach\s*\([^)]*\bas\s*\$([a-z_][A-Za-z0-9_]*)~',
            // list($a, $b) = ...
            '~\blist\s*\(([^)]*)\)~',
        ];

        foreach ($patterns as $index => $pattern) {
            if (preg_match_all($pattern, $code, $matches) === false) {
                continue;
            }

            if ($index === 4) {
                foreach ($matches[1] as $group) {
                    preg_match_all('~\$([a-z_][A-Za-z0-9_]*)~', $group, $inner);
                    foreach ($inner[1] as $name) {
                        $names[] = $name;
                    }
                }
                continue;
            }

            foreach ($matches[1] as $name) {
                $names[] = $name;
            }
        }

        // Function parameters.
        if (
            preg_match_all(
                '~\bfunction\s+\w*\s*\(([^)]*)\)~',
                $code,
                $functions
            ) !== false
        ) {
            foreach ($functions[1] as $parameters) {
                preg_match_all(
                    '~\$([a-z_][A-Za-z0-9_]*)~',
                    $parameters,
                    $inner
                );
                foreach ($inner[1] as $name) {
                    $names[] = $name;
                }
            }
        }

        return $names;
    }

    /**
     * Generated test suites often use long sentence-style method names such as
     * filterMedicalRecordsWithNoCriteriaAppliesNoFilter. Hand-written tests
     * usually use shorter names, so several of them in one test class is a
     * useful, low-weight signal.
     */
    private function scoreSentenceStyleTestNames(
        AnalysisContext $context,
        string $code
    ): void {
        $className = '';
        if (preg_match(self::CLASS_NAME, $code, $matches) === 1) {
            $className = $matches[1];
        }

        $testClass = str_ends_with($className, 'Test')
            || str_ends_with($className, 'Tests')
            || preg_match(self::TEST_CLASS, $code) === 1;

        if (!$testClass) {
            return;
        }

        preg_match_all(self::FUNCTION_NAME, $code, $methods);

        $longNames = 0;
        $firstLongName = null;

        foreach ($methods[1] as $method) {
            if ($this->wordParts($method) >= 5) {
                $longNames++;

                if ($firstLongName === null) {
                    $firstLongName = $method;
                }
            }
        }

        if ($longNames >= 5) {
            $context->addFinding(
                Categories::NAMING,
                4,
                $longNames . ' test methods use long sentence-style names.',
                $firstLongName ?? ''
            );
        } elseif ($longNames >= 3) {
            $context->addFinding(
                Categories::NAMING,
                2,
                $longNames . ' test methods use long sentence-style names.',
                $firstLongName ?? ''
            );
        }
    }

    private function wordParts(string $identifier): int
    {
        if (str_contains($identifier, '_')) {
            return count(array_filter(explode('_', $identifier), 'strlen'));
        }

        $parts = 1;

        for ($index = 1; $index < strlen($identifier); $index++) {
            if (ctype_upper($identifier[$index])) {
                $parts++;
            }
        }

        return $parts;
    }

    /** @param string[] $names */
    private function summarize(array $names): string
    {
        sort($names);

        if (count($names) > 5) {
            return implode(', ', array_slice($names, 0, 5))
                . ' and ' . (count($names) - 5) . ' more';
        }

        return implode(', ', $names);
    }
}
