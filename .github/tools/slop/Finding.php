<?php

declare(strict_types=1);

namespace Slop;

/**
 * One explainable piece of evidence behind a file's score.
 *
 * A finding carries the visible code fragment that triggered the rule, the
 * reviewer-style reason, and a confidence band derived from the rule's weight.
 * The report uses these to render the "Probable AI-origin Markers" table.
 */
final class Finding
{
    private string $category;
    private int $points;
    private string $reason;
    private string $fragment;
    private string $confidence;

    public function __construct(
        string $category,
        int $points,
        string $reason,
        string $fragment
    ) {
        $this->category = $category;
        $this->points = $points;
        $this->reason = $reason;
        $this->fragment = $fragment;
        $this->confidence = self::confidenceFor($points);
    }

    /**
     * Maps a rule's weight to a reviewer-friendly confidence band.
     */
    public static function confidenceFor(int $points): string
    {
        if ($points >= 8) {
            return 'Strong';
        }

        if ($points >= 5) {
            return 'Medium-high';
        }

        if ($points >= 3) {
            return 'Medium';
        }

        return 'Low';
    }

    public function getCategory(): string
    {
        return $this->category;
    }

    public function getPoints(): int
    {
        return $this->points;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function getFragment(): string
    {
        return $this->fragment;
    }

    public function getConfidence(): string
    {
        return $this->confidence;
    }
}
