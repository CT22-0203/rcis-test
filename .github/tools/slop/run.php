<?php

/**
 * Entry point for the PHP AI-pattern detector.
 *
 * Usage:
 *   php .github/tools/slop/run.php [options] <source-dir> <report-file>
 */

declare(strict_types=1);

require_once __DIR__ . '/Categories.php';
require_once __DIR__ . '/Finding.php';
require_once __DIR__ . '/SourceText.php';
require_once __DIR__ . '/AnalyzerSupport.php';
require_once __DIR__ . '/AnalysisContext.php';
require_once __DIR__ . '/CodeAnalyzer.php';
require_once __DIR__ . '/NamingAnalyzer.php';
require_once __DIR__ . '/CommentAnalyzer.php';
require_once __DIR__ . '/StructureAnalyzer.php';
require_once __DIR__ . '/AiTraitsAnalyzer.php';
require_once __DIR__ . '/LogicAnalyzer.php';
require_once __DIR__ . '/DaoPatternAnalyzer.php';
require_once __DIR__ . '/ReviewPatternAnalyzer.php';
require_once __DIR__ . '/CrossFileAnalyzer.php';
require_once __DIR__ . '/DetectorOptions.php';
require_once __DIR__ . '/AiPatternDetector.php';

exit(\Slop\AiPatternDetector::main($argv));
