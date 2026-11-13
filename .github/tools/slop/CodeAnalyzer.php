<?php

declare(strict_types=1);

namespace Slop;

/**
 * A single rule set that adds evidence to a file's analysis context.
 */
interface CodeAnalyzer
{
    public function analyze(AnalysisContext $context): void;
}
