<?php

declare(strict_types=1);

namespace Slop;

use InvalidArgumentException;

/**
 * Per-file analysis state: the source, its comments, the score accumulated in
 * each category, and the reasons behind those points.
 */
final class AnalysisContext
{
    public const MAX_CATEGORY_SCORE = 20;

    private string $file;
    private string $code;
    private string $comments;
    private int $lineCount;

    /** @var array<string, int> */
    private array $scores = [];

    /** @var array<string, string[]> */
    private array $evidence = [];

    /** @var string[] */
    private array $technicalNotes = [];

    /** @var Finding[] */
    private array $findings = [];

    public function __construct(string $file, string $code, string $comments)
    {
        $this->file = $file;
        $this->code = $code;
        $this->comments = $comments;
        $this->lineCount = SourceText::countLines($code);

        foreach (Categories::ALL as $category) {
            $this->scores[$category] = 0;
            $this->evidence[$category] = [];
        }
    }

    public function getFile(): string
    {
        return $this->file;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getComments(): string
    {
        return $this->comments;
    }

    public function getLineCount(): int
    {
        return $this->lineCount;
    }

    public function addPoints(string $category, int $points, string $reason): void
    {
        $this->addFinding($category, $points, $reason, '');
    }

    /**
     * Adds points and records the visible fragment that triggered the rule.
     */
    public function addFinding(
        string $category,
        int $points,
        string $reason,
        string $fragment
    ): void {
        if (!array_key_exists($category, $this->scores)) {
            throw new InvalidArgumentException(
                'Unknown category: ' . $category
            );
        }

        if ($points <= 0) {
            return;
        }

        $current = $this->scores[$category];
        $added = min($points, self::MAX_CATEGORY_SCORE - $current);

        if ($added <= 0) {
            return;
        }

        $this->scores[$category] = $current + $added;

        if (!in_array($reason, $this->evidence[$category], true)) {
            $this->evidence[$category][] = $reason;
            $this->findings[] = new Finding(
                $category,
                $added,
                $reason,
                $fragment
            );
        }
    }

    public function addTechnicalNote(string $note): void
    {
        if (!in_array($note, $this->technicalNotes, true)) {
            $this->technicalNotes[] = $note;
        }
    }

    /** @return array<string, int> */
    public function getScores(): array
    {
        return $this->scores;
    }

    /** @return array<string, string[]> */
    public function getEvidence(): array
    {
        return $this->evidence;
    }

    /** @return string[] */
    public function getTechnicalNotes(): array
    {
        return $this->technicalNotes;
    }

    /** @return Finding[] */
    public function getFindings(): array
    {
        return $this->findings;
    }

    public function getScore(string $category): int
    {
        return $this->scores[$category] ?? 0;
    }

    public function getTotalScore(): int
    {
        return min(array_sum($this->scores), 100);
    }

    /** Category with the most points, or null when the file is clean. */
    public function getDominantCategory(): ?string
    {
        $dominant = null;
        $highest = 0;

        foreach ($this->scores as $category => $score) {
            if ($score > $highest) {
                $highest = $score;
                $dominant = $category;
            }
        }

        return $dominant;
    }
}
