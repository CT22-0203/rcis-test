<?php

declare(strict_types=1);

namespace Slop;

/**
 * Detects documentation that reads like generated boilerplate: repeated
 * "this method is used to ..." PHPDoc, tutorial-style numbered steps and
 * filler phrases such as "note that" / "here is an example".
 *
 * It also looks for the copy-paste documentation style common to generated
 * test suites: the exact same PHPDoc sentence repeated across many members,
 * and a PHPDoc block on nearly every field and method.
 */
final class CommentAnalyzer implements CodeAnalyzer
{
    private const TEMPLATE_PHRASES = [
        'this method is used to',
        'this test verifies that',
        'this test checks that',
        'the purpose of this method',
        'this function is responsible for',
        'this class represents',
        'this method returns',
        'this method takes',
        'is used to represent',
        'initializes the test environment',
        'sets up the test environment',
        'assert that the result',
        'helper method to',
        'utility method to',
        'here is an example',
        "here's an example",
        'the following code',
        'as shown below',
        'note that this',
        'please note that',
        'it is important to note',
        'in summary',
    ];

    private const NUMBERED_STEPS = '~(?m)^\s*(?://|#|\*)\s*(?:step\s*)?\d+\s*[.):]\s+\S~i';

    /** A single PHPDoc block. Used to measure documentation coverage. */
    private const DOC_BLOCK = '~/\*\*~';

    private const CLASS_NAME = '~\b(?:class|interface|trait|enum)\s+(\w+)~i';

    /**
     * Any method declaration, including package-private test methods. Test
     * classes often leave test methods without an access modifier.
     */
    private const METHOD_DECLARATION =
        '~(?m)^\s*(?:(?:public|protected|private|static|final|abstract)\s+)*'
        . 'function\s+\w+\s*\(~';

    private const FIELD_DECLARATION =
        '~(?m)^\s*(?:(?:public|protected|private|static|readonly|var|final)\s+)+'
        . '(?:\??[A-Za-z_\\\\][\w\\\\|]*\s+)?\$?\w+\s*(?:=|;)~';

    private const CONST_DECLARATION = '~(?m)^\s*(?:(?:public|protected|private|final)\s+)*const\s+\w+\s*=~';

    private const TEST_CLASS = '~extends\s+TestCase|PHPUnit\\\\Framework\\\\TestCase|#\[Test\]|@test\b~i';

    /** Comment lines that are too generic or legal to count as boilerplate. */
    private const REPEAT_EXCLUSIONS = [
        'copyright', 'license', 'licensed', 'spdx', 'all rights reserved',
        'http://', 'https://', 'www.', '@author', '@version',
        '@since', '<p>', '{@link', '{@code', 'all rights reserved',
    ];

    private const COMMENT_PREFIX = '~^(?://+|#+|/\*+|\*+/?)\s*~';

    public function analyze(AnalysisContext $context): void
    {
        $comments = strtolower($context->getComments());
        $lines = $context->getLineCount();

        $phraseCount = 0;
        $distinctPhrases = [];

        foreach (self::TEMPLATE_PHRASES as $phrase) {
            $occurrences = AnalyzerSupport::count($comments, $phrase);

            if ($occurrences > 0) {
                $phraseCount += $occurrences;
                $distinctPhrases[$phrase] = true;
            }
        }

        $scaledPhrases = AnalyzerSupport::scaleForSize($phraseCount, $lines);

        if (count($distinctPhrases) >= 3 && $scaledPhrases >= 6) {
            $context->addPoints(
                Categories::COMMENTS,
                10,
                $phraseCount . ' template-like documentation phrases across '
                    . count($distinctPhrases) . ' distinct patterns.'
            );
        } elseif (count($distinctPhrases) >= 2 && $scaledPhrases >= 3) {
            $context->addPoints(
                Categories::COMMENTS,
                5,
                $phraseCount . ' generic documentation phrase(s) detected: '
                    . $this->summarize(array_keys($distinctPhrases))
            );
        }

        $numberedSteps = AnalyzerSupport::countPattern(
            self::NUMBERED_STEPS,
            $comments
        );

        if ($numberedSteps >= 8 && $phraseCount >= 2) {
            $context->addPoints(
                Categories::COMMENTS,
                5,
                $numberedSteps . ' numbered step comments combined with '
                    . 'generic descriptions.'
            );
        }

        $this->scoreRepeatedDocumentation($context, $comments);
        $this->scoreDocumentationCoverage($context);
    }

    /**
     * Detects the same long documentation sentence copied onto many members.
     */
    private function scoreRepeatedDocumentation(
        AnalysisContext $context,
        string $comments
    ): void {
        /** @var array<string, int> $seen */
        $seen = [];
        $worstLine = '';
        $worstCount = 0;

        foreach (preg_split('~\R~', $comments) ?: [] as $rawLine) {
            $line = $this->normalizeCommentLine($rawLine);

            if (!$this->isScorableCommentLine($line)) {
                continue;
            }

            $seen[$line] = ($seen[$line] ?? 0) + 1;
            $count = $seen[$line];

            if ($count > $worstCount) {
                $worstCount = $count;
                $worstLine = $line;
            }
        }

        if ($worstCount >= 5) {
            $context->addPoints(
                Categories::COMMENTS,
                10,
                'The same documentation sentence is repeated '
                    . $worstCount . ' times: "'
                    . AnalyzerSupport::truncate($worstLine, 60) . '"'
            );
        } elseif ($worstCount >= 3) {
            $context->addPoints(
                Categories::COMMENTS,
                6,
                'The same documentation sentence is repeated '
                    . $worstCount . ' times: "'
                    . AnalyzerSupport::truncate($worstLine, 60) . '"'
            );
        } elseif ($worstCount === 2 && $this->wordCount($worstLine) >= 10) {
            $context->addPoints(
                Categories::COMMENTS,
                3,
                'A long documentation sentence is duplicated verbatim: "'
                    . AnalyzerSupport::truncate($worstLine, 60) . '"'
            );
        }
    }

    /**
     * Detects test suites where every field and test method carries its own
     * PHPDoc. Generated tests tend to document even private mocks this way.
     */
    private function scoreDocumentationCoverage(AnalysisContext $context): void
    {
        $code = $context->getCode();

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

        $docs = AnalyzerSupport::countPattern(self::DOC_BLOCK, $code);

        if ($docs < 8) {
            return;
        }

        $members = AnalyzerSupport::countPattern(self::METHOD_DECLARATION, $code)
            + AnalyzerSupport::countPattern(self::FIELD_DECLARATION, $code)
            + AnalyzerSupport::countPattern(self::CONST_DECLARATION, $code);

        if ($members < 8) {
            return;
        }

        // PHPDoc blocks include the class-level comment, so cap the ratio.
        $coverage = min(1.0, $docs / $members);

        if ($coverage >= 0.9) {
            $context->addFinding(
                Categories::COMMENTS,
                5,
                'PHPDoc on nearly every field and test method ('
                    . $docs . ' blocks for ' . $members . ' members).',
                $docs . ' PHPDoc blocks for ' . $members . ' members'
            );
        }
    }

    private function normalizeCommentLine(string $rawLine): string
    {
        $line = preg_replace(self::COMMENT_PREFIX, '', trim($rawLine)) ?? '';

        return trim(preg_replace('~\s+~', ' ', $line) ?? '');
    }

    private function isScorableCommentLine(string $line): bool
    {
        if (strlen($line) < 25 || $this->wordCount($line) < 5) {
            return false;
        }

        $lower = strtolower($line);

        foreach (self::REPEAT_EXCLUSIONS as $exclusion) {
            if (str_contains($lower, $exclusion)) {
                return false;
            }
        }

        return true;
    }

    private function wordCount(string $line): int
    {
        if ($line === '') {
            return 0;
        }

        return count(explode(' ', $line));
    }

    /** @param string[] $phrases */
    private function summarize(array $phrases): string
    {
        $summary = '';
        $shown = 0;

        foreach ($phrases as $phrase) {
            if ($shown === 3) {
                $summary .= ', ...';
                break;
            }

            if ($shown > 0) {
                $summary .= ', ';
            }

            $summary .= '"' . $phrase . '"';
            $shown++;
        }

        return $summary;
    }
}
