<?php

declare(strict_types=1);

namespace Slop;

use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;

/**
 * Heuristic scanner for coding patterns that commonly appear in generated
 * ("AI slop") PHP code.
 *
 * The score is an aggregate of five categories and is intentionally
 * explainable: every point is backed by an evidence line in the report. It is
 * not a satisfiable authorship test.
 *
 * Exit codes:
 *   0 - analysis completed and the score is below the threshold.
 *   1 - analysis completed and the score reached the threshold.
 *   2 - the detector could not run (bad arguments, unreadable input, failure).
 */
final class AiPatternDetector
{
    private const MAX_SCORE = 100;
    private const DEFAULT_THRESHOLD = 10;

    /**
     * Weight of the worst file relative to the mean. A single generated file
     * should not disappear into the average score of a large repository, but
     * the mean still matters so one noisy file alone does not define the
     * project.
     */
    private const MAX_FILE_WEIGHT = 0.60;

    private const MAX_TABLE_FILES = 20;
    private const MAX_FINDING_FILES = 15;
    private const MAX_MARKER_ROWS = 30;
    private const MAX_EVIDENCE_LINES = 40;
    private const MAX_LISTED_FILES = 50;

    private const DEFAULT_EXCLUDES = [
        'build', 'target', '.git', 'out', 'node_modules', 'generated',
        'vendor', 'storage', 'cache', '.phpunit.cache', '.github',
    ];

    private function __construct()
    {
    }

    /**
     * @param string[] $argv
     */
    public static function main(array $argv): int
    {
        try {
            $options = DetectorOptions::parse(array_slice($argv, 1));
        } catch (InvalidArgumentException $exception) {
            fwrite(STDERR, 'ai-detect: ' . $exception->getMessage() . PHP_EOL);
            self::printUsage(STDERR);

            return 2;
        }

        if ($options->help) {
            self::printUsage(STDOUT);

            return 0;
        }

        try {
            return self::run($options);
        } catch (Throwable $exception) {
            fwrite(STDERR, 'ai-detect: ' . $exception->getMessage() . PHP_EOL);

            return 2;
        }
    }

    private static function run(DetectorOptions $options): int
    {
        [$files, $skipped] = self::selectFiles($options);

        /** @var AnalysisContext[] $results */
        $results = [];
        $unreadable = 0;

        foreach ($files as $file) {
            $code = @file_get_contents($file);

            if ($code === false) {
                $unreadable++;
                $skipped[] = self::display($file)
                    . ' (unreadable: could not read file)';
                continue;
            }

            if (SourceText::isSuppressed($code)) {
                $skipped[] = self::display($file)
                    . ' (contains @generated, ai-detect:ignore, slop-ignore '
                    . 'or @SuppressWarnings("ai-detect"))';
                continue;
            }

            $context = new AnalysisContext(
                $file,
                $code,
                SourceText::extractComments($code)
            );

            foreach (self::analyzers() as $analyzer) {
                $analyzer->analyze($context);
            }

            $results[] = $context;
        }

        // Suppressed or deleted files are a legitimate pass; files that exist
        // but cannot be read mean the tool itself failed.
        if ($results === [] && $unreadable > 0) {
            throw new RuntimeException(
                'no files could be analyzed; check the source paths and permissions'
            );
        }

        CrossFileAnalyzer::analyze($results);

        $totalScore = 0;
        $maxFileScore = 0;

        foreach ($results as $result) {
            $score = $result->getTotalScore();
            $totalScore += $score;
            $maxFileScore = max($maxFileScore, $score);
        }

        $meanFileScore = $results === []
            ? 0
            : (int) round($totalScore / count($results));

        $aggregateScore = (int) round(
            self::MAX_FILE_WEIGHT * $maxFileScore
                + (1.0 - self::MAX_FILE_WEIGHT) * $meanFileScore
        );

        $aggregateScore = min(self::MAX_SCORE, $aggregateScore);

        $filesAtOrAboveThreshold = 0;

        foreach ($results as $result) {
            if ($result->getTotalScore() >= $options->threshold) {
                $filesAtOrAboveThreshold++;
            }
        }

        $detected = $aggregateScore >= $options->threshold
            || $filesAtOrAboveThreshold > 0;

        self::generateReport(
            $options,
            $results,
            $skipped,
            $aggregateScore,
            $meanFileScore,
            $maxFileScore,
            $filesAtOrAboveThreshold,
            $detected
        );

        self::writeSummary(
            $options,
            count($results),
            count($skipped),
            $filesAtOrAboveThreshold,
            $aggregateScore,
            $meanFileScore,
            $maxFileScore,
            self::highestFile($results),
            $detected
        );

        echo 'AI Detection: '
            . ($detected ? 'FAILED (threshold reached)' : 'PASSED') . PHP_EOL;
        echo 'Files analyzed: ' . count($results) . PHP_EOL;
        echo 'Files skipped: ' . count($skipped) . PHP_EOL;
        echo 'Aggregate score: ' . $aggregateScore . '/100'
            . ' (highest file ' . $maxFileScore
            . ', mean ' . $meanFileScore . ')' . PHP_EOL;
        echo 'Threshold: ' . $options->threshold . '/100' . PHP_EOL;
        echo 'Report: ' . self::display($options->reportFile) . PHP_EOL;

        return $detected ? 1 : 0;
    }

    /**
     * @return CodeAnalyzer[]
     */
    private static function analyzers(): array
    {
        return [
            new NamingAnalyzer(),
            new CommentAnalyzer(),
            new StructureAnalyzer(),
            new AiTraitsAnalyzer(),
            new LogicAnalyzer(),
            new DaoPatternAnalyzer(),
            new ReviewPatternAnalyzer(),
        ];
    }

    /**
     * Collects the files to analyze, either from a directory walk or from a
     * list produced by CI (for example, git diff --name-only).
     *
     * @return array{0: string[], 1: string[]}
     */
    private static function selectFiles(DetectorOptions $options): array
    {
        $files = [];
        $skipped = [];

        if ($options->filesFrom !== null) {
            if (!is_file($options->filesFrom)) {
                throw new RuntimeException(
                    'file list not found: ' . $options->filesFrom
                );
            }

            $contents = @file_get_contents($options->filesFrom);

            if ($contents === false) {
                throw new RuntimeException(
                    'file list not readable: ' . $options->filesFrom
                );
            }

            if (str_starts_with($contents, "\u{FEFF}")) {
                $contents = substr($contents, 3);
            }

            $seen = [];

            foreach (preg_split('~\R~', $contents) ?: [] as $line) {
                $entry = trim($line);

                if ($entry === '' || str_starts_with($entry, '#')) {
                    continue;
                }

                if (str_starts_with($entry, "\u{FEFF}")) {
                    $entry = ltrim(substr($entry, 3));
                }

                if ($entry === '' || str_starts_with($entry, '#')) {
                    continue;
                }

                if (preg_match('~\.php$~i', $entry) !== 1) {
                    continue;
                }

                if (!is_file($entry)) {
                    $skipped[] = $entry
                        . ' (listed for analysis but not found)';
                    continue;
                }

                $key = self::display($entry);

                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    $files[] = $entry;
                }
            }

            return [$files, $skipped];
        }

        if (!is_dir($options->sourceDir)) {
            throw new RuntimeException(
                'source directory not found: ' . $options->sourceDir
            );
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $options->sourceDir,
                RecursiveDirectoryIterator::SKIP_DOTS
            )
        );

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isFile()) {
                continue;
            }

            $path = $fileInfo->getPathname();

            if (preg_match('~\.php$~i', $path) !== 1) {
                continue;
            }

            if (self::isExcluded($options, $path)) {
                continue;
            }

            $files[] = $path;
        }

        sort($files);

        return [$files, $skipped];
    }

    private static function isExcluded(
        DetectorOptions $options,
        string $path
    ): bool {
        $normalized = self::display($path);

        foreach (self::DEFAULT_EXCLUDES as $directory) {
            if (
                preg_match(
                    '~(?:^|/)' . preg_quote($directory, '~') . '(?:/|$)~',
                    $normalized
                ) === 1
            ) {
                return true;
            }
        }

        foreach ($options->excludes as $glob) {
            if (fnmatch($glob, $normalized) || fnmatch($glob, basename($normalized))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param AnalysisContext[] $results
     * @param string[] $skipped
     */
    private static function generateReport(
        DetectorOptions $options,
        array $results,
        array $skipped,
        int $score,
        int $meanFileScore,
        int $maxFileScore,
        int $filesAtOrAboveThreshold,
        bool $detected
    ): void {
        $report = $options->reportFile;
        $parent = dirname($report);

        if ($parent !== '.' && !is_dir($parent)) {
            mkdir($parent, 0777, true);
        }

        $ranked = $results;
        usort(
            $ranked,
            static function (AnalysisContext $left, AnalysisContext $right): int {
                $byScore = $right->getTotalScore() <=> $left->getTotalScore();

                return $byScore !== 0
                    ? $byScore
                    : strcmp($left->getFile(), $right->getFile());
            }
        );

        $output = [];

        $output[] = '# AI Code Pattern Detection Report';
        $output[] = '';
        $output[] = '- Generated: '
            . gmdate('Y-m-d H:i:s') . ' UTC';
        $output[] = '- Scope: ' . self::describeScope($options, count($results));
        $output[] = '- Threshold: ' . $options->threshold . '/100';
        $output[] = '';
        $output[] = '## Result';
        $output[] = '';
        $output[] = '**' . ($detected ? 'FAILED' : 'PASSED') . '** - '
            . ($detected
                ? 'the aggregate score or at least one file reached the '
                    . 'configured threshold.'
                : 'neither the aggregate score nor any file reached the '
                    . 'configured threshold.');
        $output[] = '';
        $output[] = '| Metric | Value |';
        $output[] = '| --- | --- |';
        $output[] = '| Aggregate score | **' . $score . '/100** |';
        $output[] = '| Highest file score | ' . $maxFileScore . '/100'
            . (self::highestFile($results) === null
                ? ''
                : ' (`' . self::highestFile($results) . '`)')
            . ' |';
        $output[] = '| Mean file score | ' . $meanFileScore . '/100 |';
        $output[] = '| Files analyzed | ' . count($results) . ' |';
        $output[] = '| Files at/above threshold | '
            . $filesAtOrAboveThreshold . ' |';
        $output[] = '| Files skipped | ' . count($skipped) . ' |';
        $output[] = '';
        $output[] = 'Aggregate score = 60% of the highest file score + 40% of the '
            . 'mean file score. Detection also fails when any single file '
            . 'reaches the threshold, so one generated file is not diluted '
            . 'by a large, clean codebase.';
        $output[] = '';

        self::addFileTable($output, $ranked);
        self::addProbableMarkers($output, $ranked);
        self::addFileFindings($output, $ranked);
        self::addCategoryAnalysis($output, $ranked);
        self::addTechnicalNotes($output, $ranked);
        self::addSkippedFiles($output, $skipped);
        self::addAnalyzedFiles($output, $ranked);

        $output[] = '## How the score works';
        $output[] = '';
        $output[] = '- Five categories are scored 0-20 each; a file can score at '
            . 'most 100.';
        $output[] = '- Explicit AI markers (`Generated by ChatGPT`, '
            . '`Generated with Claude Code`, `Co-authored-by: Copilot`, '
            . '`@author Gemini`, ...) score 20 on their own.';
        $output[] = '- Generated test style is scored too: the same PHPDoc '
            . 'sentence repeated across many methods, PHPDoc on every '
            . 'field and test method, and sentence-length test names.';
        $output[] = '- Exception handlers that only print to the console also '
            . 'score, as does dynamic SQL in repository code.';
        $output[] = '- The check fails when the aggregate score or any single '
            . 'file reaches the threshold.';
        $output[] = '- Comments and string literals are stripped before '
            . 'declaration and structure rules run; getters/setters are '
            . 'excluded from duplication checks.';
        $output[] = '- To exclude a file, add `@generated`, '
            . '`ai-detect:ignore` or `slop-ignore` to a comment. Suppressed '
            . 'files are listed as skipped in the report.';
        $output[] = '';
        $output[] = '---';
        $output[] = '*Heuristic analysis only. This report identifies configured '
            . 'patterns and does not prove AI authorship. Common coding '
            . 'practices, templates, and project conventions can produce '
            . 'similar patterns.*';

        $written = @file_put_contents(
            $report,
            implode(PHP_EOL, $output) . PHP_EOL
        );

        if ($written === false) {
            throw new RuntimeException('could not write report: ' . $report);
        }
    }

    /**
     * @param string[] $output
     * @param AnalysisContext[] $ranked
     */
    private static function addFileTable(
        array &$output,
        array $ranked
    ): void {
        $output[] = '## Files With Detected Patterns';
        $output[] = '';

        $scored = array_values(array_filter(
            $ranked,
            static fn (AnalysisContext $result): bool => $result->getTotalScore() > 0
        ));

        if ($scored === []) {
            $output[] = 'No configured indicators were found in any file.';
            $output[] = '';
            return;
        }

        $output[] = '| File | Score | Strongest category |';
        $output[] = '| --- | --- | --- |';

        $shown = 0;

        foreach ($scored as $result) {
            if ($shown === self::MAX_TABLE_FILES) {
                $output[] = '';
                $output[] = '_' . (count($scored) - self::MAX_TABLE_FILES)
                    . ' more file(s) omitted; see the artifact for details._';
                break;
            }

            $output[] = '| `' . self::display($result->getFile()) . '` | '
                . $result->getTotalScore() . '/100 | '
                . ($result->getDominantCategory() ?? 'none') . ' |';
            $shown++;
        }

        $output[] = '';
    }

    /**
     * Reviewer-style marker table: the visible fragment, why it looks
     * AI-generated or AI-assisted, and how strong the signal is.
     *
     * @param string[] $output
     * @param AnalysisContext[] $ranked
     */
    private static function addProbableMarkers(
        array &$output,
        array $ranked
    ): void {
        $output[] = '## Probable AI-origin Markers';
        $output[] = '';

        $withFindings = array_values(array_filter(
            $ranked,
            static fn (AnalysisContext $result): bool => $result->getFindings() !== []
        ));

        if ($withFindings === []) {
            $output[] = 'No probable AI-origin markers were detected.';
            $output[] = '';
            return;
        }

        $output[] = '| File / visible fragment '
            . '| Why it looks AI-generated or AI-assisted | Confidence |';
        $output[] = '| --- | --- | --- |';

        $shown = 0;
        $truncated = false;

        foreach ($withFindings as $result) {
            $findings = $result->getFindings();
            usort(
                $findings,
                static fn (Finding $left, Finding $right): int =>
                    $right->getPoints() <=> $left->getPoints()
            );

            foreach ($findings as $finding) {
                if ($shown === self::MAX_MARKER_ROWS) {
                    $truncated = true;
                    break;
                }

                $output[] = '| ' . self::markerFileCell($result, $finding)
                    . ' | ' . self::escapeCell($finding->getReason())
                    . ' | ' . $finding->getConfidence() . ' |';
                $shown++;
            }

            if ($truncated) {
                break;
            }
        }

        if ($truncated) {
            $output[] = '';
            $output[] = '_Additional markers were omitted; see the category '
                . 'analysis below for the remaining evidence._';
        }

        $output[] = '';
        $output[] = '_Confidence bands reflect the weight of each marker: '
            . 'Low (1-2), Medium (3-4), Medium-high (5-7), Strong (8+). '
            . 'These are heuristic signals, not proof of authorship._';
        $output[] = '';
    }

    private static function markerFileCell(
        AnalysisContext $result,
        Finding $finding
    ): string {
        $cell = '`' . basename(self::display($result->getFile())) . '`';

        if ($finding->getFragment() !== '') {
            $cell .= ': `' . self::escapeCell($finding->getFragment()) . '`';
        }

        return $cell;
    }

    private static function escapeCell(string $text): string
    {
        $text = str_replace('|', '\|', $text);
        $text = preg_replace('~\R~', ' ', $text) ?? '';

        return trim($text);
    }

    /**
     * Reviewer-style findings for each scored file: score, confidence band and
     * every reason behind the points.
     *
     * @param string[] $output
     * @param AnalysisContext[] $ranked
     */
    private static function addFileFindings(
        array &$output,
        array $ranked
    ): void {
        $output[] = '## File-by-File Findings';
        $output[] = '';

        $shown = 0;

        foreach ($ranked as $result) {
            if ($result->getTotalScore() <= 0) {
                continue;
            }

            if ($shown === self::MAX_FINDING_FILES) {
                $output[] = '_' . (self::countScored($ranked) - self::MAX_FINDING_FILES)
                    . ' more scored file(s) omitted; see the file table._';
                $output[] = '';
                break;
            }

            $output[] = '### `' . self::display($result->getFile()) . '`';
            $output[] = '';
            $output[] = '- Score: **' . $result->getTotalScore() . '/100** - '
                . self::verdict($result->getTotalScore());
            $output[] = '- Strongest category: '
                . ($result->getDominantCategory() ?? 'none');

            $findings = $result->getFindings();
            usort(
                $findings,
                static fn (Finding $left, Finding $right): int =>
                    $right->getPoints() <=> $left->getPoints()
            );

            if ($findings !== []) {
                foreach ($findings as $finding) {
                    $output[] = '- **' . $finding->getConfidence() . '** - '
                        . $finding->getReason();

                    if ($finding->getFragment() !== '') {
                        $output[] = '  - `'
                            . self::escapeCell($finding->getFragment()) . '`';
                    }
                }
            } else {
                foreach (Categories::ALL as $category) {
                    foreach ($result->getEvidence()[$category] as $reason) {
                        $output[] = '- ' . $reason;
                    }
                }
            }

            $output[] = '';
            $shown++;
        }

        if ($shown === 0) {
            $output[] = 'No file scored above zero.';
            $output[] = '';
        }

        $output[] = '_Scores are heuristic evidence, not proof of authorship._';
        $output[] = '';
    }

    /** @param AnalysisContext[] $ranked */
    private static function countScored(array $ranked): int
    {
        return count(array_filter(
            $ranked,
            static fn (AnalysisContext $result): bool => $result->getTotalScore() > 0
        ));
    }

    private static function verdict(int $score): string
    {
        if ($score >= 20) {
            return '**very likely AI-generated or AI-assisted '
                . '(high confidence)**';
        }

        if ($score >= 10) {
            return '**likely AI-generated or AI-assisted '
                . '(medium confidence)**';
        }

        if ($score >= 5) {
            return '**possible AI-generated or AI-assisted content '
                . '(low-to-medium confidence)**';
        }

        return '**weak AI-style signals only (low confidence)**';
    }

    /**
     * @param string[] $output
     * @param AnalysisContext[] $ranked
     */
    private static function addCategoryAnalysis(
        array &$output,
        array $ranked
    ): void {
        $output[] = '## Category Analysis';
        $output[] = '';

        foreach (Categories::ALL as $category) {
            $total = 0;
            $highest = 0;
            $evidence = [];

            foreach ($ranked as $result) {
                $categoryScore = $result->getScore($category);
                $total += $categoryScore;
                $highest = max($highest, $categoryScore);

                foreach ($result->getEvidence()[$category] as $item) {
                    $evidence[] = '`' . self::display($result->getFile())
                        . '`: ' . $item;
                }
            }

            $mean = $ranked === []
                ? 0
                : (int) round($total / count($ranked));

            $output[] = '### ' . $category;
            $output[] = '';
            $output[] = 'Mean: ' . $mean . '/20 | Highest file: '
                . $highest . '/20';
            $output[] = '';

            if ($evidence === []) {
                $output[] = '- No configured indicators detected.';
            } else {
                $limit = min(count($evidence), self::MAX_EVIDENCE_LINES);

                for ($index = 0; $index < $limit; $index++) {
                    $output[] = '- ' . $evidence[$index];
                }

                if (count($evidence) > $limit) {
                    $output[] = '- _' . (count($evidence) - $limit)
                        . ' more indicator(s) omitted._';
                }
            }

            $output[] = '';
        }
    }

    /**
     * @param string[] $output
     * @param AnalysisContext[] $ranked
     */
    private static function addTechnicalNotes(
        array &$output,
        array $ranked
    ): void {
        $output[] = '## Technical Review Notes';
        $output[] = '';

        $hasNotes = false;

        foreach ($ranked as $result) {
            foreach ($result->getTechnicalNotes() as $note) {
                $output[] = '- `' . self::display($result->getFile())
                    . '`: ' . $note;
                $hasNotes = true;
            }
        }

        if (!$hasNotes) {
            $output[] = '- No technical review notes.';
        }

        $output[] = '';
    }

    /**
     * @param string[] $output
     * @param string[] $skipped
     */
    private static function addSkippedFiles(
        array &$output,
        array $skipped
    ): void {
        if ($skipped === []) {
            return;
        }

        $output[] = '## Skipped Files';
        $output[] = '';
        $output[] = 'These files were not scored:';
        $output[] = '';

        foreach ($skipped as $item) {
            $output[] = '- ' . $item;
        }

        $output[] = '';
    }

    /**
     * @param string[] $output
     * @param AnalysisContext[] $ranked
     */
    private static function addAnalyzedFiles(
        array &$output,
        array $ranked
    ): void {
        $output[] = '## Analyzed Files';
        $output[] = '';
        $output[] = '**Total PHP files analyzed: ' . count($ranked) . '**';
        $output[] = '';

        $limit = min(count($ranked), self::MAX_LISTED_FILES);

        for ($index = 0; $index < $limit; $index++) {
            $output[] = '- `' . self::display($ranked[$index]->getFile()) . '`';
        }

        if (count($ranked) > $limit) {
            $output[] = '- _' . (count($ranked) - $limit)
                . ' more file(s) omitted._';
        }

        $output[] = '';
    }

    private static function writeSummary(
        DetectorOptions $options,
        int $filesAnalyzed,
        int $filesSkipped,
        int $filesAtOrAboveThreshold,
        int $score,
        int $meanFileScore,
        int $maxFileScore,
        ?string $highestFile,
        bool $detected
    ): void {
        $summary = $options->summaryFile();
        $parent = dirname($summary);

        if ($parent !== '.' && !is_dir($parent)) {
            mkdir($parent, 0777, true);
        }

        $lines = [
            'score=' . $score,
            'threshold=' . $options->threshold,
            'detected=' . ($detected ? 'true' : 'false'),
            'files_analyzed=' . $filesAnalyzed,
            'files_skipped=' . $filesSkipped,
            'files_at_or_above_threshold=' . $filesAtOrAboveThreshold,
            'max_file_score=' . $maxFileScore,
            'mean_file_score=' . $meanFileScore,
            'highest_file=' . ($highestFile ?? ''),
            'scope=' . ($options->filesFrom !== null ? 'changed' : 'all'),
        ];

        $written = @file_put_contents(
            $summary,
            implode(PHP_EOL, $lines) . PHP_EOL
        );

        if ($written === false) {
            throw new RuntimeException('could not write summary: ' . $summary);
        }
    }

    private static function describeScope(
        DetectorOptions $options,
        int $fileCount
    ): string {
        $label = $options->filesFrom !== null
            ? 'PHP files listed in `' . self::display($options->filesFrom) . '`'
            : 'PHP files under `' . self::display($options->sourceDir) . '`';

        return $label . ' (' . $fileCount . ' analyzed)';
    }

    /**
     * @param AnalysisContext[] $results
     */
    private static function highestFile(array $results): ?string
    {
        $highest = null;

        foreach ($results as $result) {
            if (
                $highest === null
                || $result->getTotalScore() > $highest->getTotalScore()
            ) {
                $highest = $result;
            }
        }

        if ($highest === null || $highest->getTotalScore() === 0) {
            return null;
        }

        return self::display($highest->getFile());
    }

    private static function display(string $path): string
    {
        return str_replace('\\', '/', $path);
    }

    /** @param resource $out */
    private static function printUsage($out): void
    {
        $usage = [
            'Usage: AiPatternDetector [options] <source-dir> <report-file>',
            '',
            'Options:',
            '  --threshold <0-100>  Score at which the check fails'
                . ' (default: ' . self::DEFAULT_THRESHOLD . ')',
            '  --files-from <file>   Analyze only paths listed in <file>,',
            '                        one per line (used by CI diffs)',
            '  --exclude <glob>      Skip matching paths (repeatable)',
            '  -h, --help           Show this help',
            '',
            'A machine-readable summary is written next to the report as',
            'ai-pattern-summary.properties.',
            '',
            'Exit codes: 0 = passed, 1 = threshold reached, 2 = tool error.',
        ];

        foreach ($usage as $line) {
            fwrite($out, $line . PHP_EOL);
        }
    }
}
