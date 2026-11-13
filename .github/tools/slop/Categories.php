<?php

declare(strict_types=1);

namespace Slop;

/**
 * Canonical category names used by every analyzer.
 *
 * Keeping these in one place stops a typo in an analyzer from silently
 * dropping points: AnalysisContext::addPoints() rejects unknown categories
 * instead of ignoring them.
 */
final class Categories
{
    public const NAMING = 'Naming Style';
    public const COMMENTS = 'Comment Style';
    public const STRUCTURE = 'Code Structure';
    public const AI_TRAITS = 'Typical AI Traits';
    public const LOGIC = 'Business Logic Footprints';

    /** @var string[] */
    public const ALL = [
        self::NAMING,
        self::COMMENTS,
        self::STRUCTURE,
        self::AI_TRAITS,
        self::LOGIC,
    ];

    private function __construct()
    {
    }
}
