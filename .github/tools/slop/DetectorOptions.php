<?php

declare(strict_types=1);

namespace Slop;

use InvalidArgumentException;

/**
 * Parsed command-line configuration.
 */
final class DetectorOptions
{
    public int $threshold;
    public ?string $sourceDir = null;
    public ?string $reportFile = null;
    public ?string $filesFrom = null;
    public bool $help = false;

    /** @var string[] */
    public array $excludes = [];

    private function __construct()
    {
        $this->threshold = self::defaultThreshold();
    }

    /**
     * @param string[] $args
     */
    public static function parse(array $args): self
    {
        $options = new self();
        $positional = [];

        for ($index = 0; $index < count($args); $index++) {
            $argument = $args[$index];

            switch ($argument) {
                case '--threshold':
                    $options->threshold = self::parseThreshold(
                        self::requireValue($args, ++$index, $argument)
                    );
                    break;
                case '--files-from':
                    $options->filesFrom = self::requireValue(
                        $args,
                        ++$index,
                        $argument
                    );
                    break;
                case '--exclude':
                    $options->excludes[] = self::requireValue(
                        $args,
                        ++$index,
                        $argument
                    );
                    break;
                case '-h':
                case '--help':
                    $options->help = true;
                    break;
                default:
                    if (str_starts_with($argument, '--')) {
                        throw new InvalidArgumentException(
                            'unknown option: ' . $argument
                        );
                    }
                    $positional[] = $argument;
            }
        }

        if ($options->help) {
            return $options;
        }

        if (count($positional) !== 2) {
            throw new InvalidArgumentException(
                'expected <source-dir> and <report-file>'
            );
        }

        $options->sourceDir = $positional[0];
        $options->reportFile = $positional[1];

        return $options;
    }

    public function summaryFile(): string
    {
        $parent = dirname($this->reportFile);

        if ($parent === '.') {
            return 'ai-pattern-summary.properties';
        }

        return $parent . DIRECTORY_SEPARATOR . 'ai-pattern-summary.properties';
    }

    /**
     * @param string[] $args
     */
    private static function requireValue(
        array $args,
        int $index,
        string $option
    ): string {
        if ($index >= count($args)) {
            throw new InvalidArgumentException(
                'missing value for ' . $option
            );
        }

        return $args[$index];
    }

    private static function parseThreshold(string $value): int
    {
        $trimmed = trim($value);

        if ($trimmed === '' || preg_match('~^-?\d+$~', $trimmed) !== 1) {
            throw new InvalidArgumentException(
                '--threshold must be a number between 0 and 100'
            );
        }

        $threshold = (int) $trimmed;

        if ($threshold < 0 || $threshold > 100) {
            throw new InvalidArgumentException(
                '--threshold must be between 0 and 100'
            );
        }

        return $threshold;
    }

    private static function defaultThreshold(): int
    {
        $configured = getenv('SLOP_THRESHOLD');

        if ($configured === false || trim($configured) === '') {
            return 10;
        }

        return self::parseThreshold($configured);
    }
}
