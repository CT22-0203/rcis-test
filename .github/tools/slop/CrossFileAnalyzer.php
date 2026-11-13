<?php

declare(strict_types=1);

namespace Slop;

/**
 * Finds signals that only appear when several files are compared.
 *
 * The strongest cross-file tell is a generic exception sentence that is
 * reused verbatim across different repositories, for example
 * "An exception was thrown while ..." with only the noun changed. A single
 * file with that message is weak evidence; the same sentence family in four
 * different repositories is a strong shared-template signal.
 */
final class CrossFileAnalyzer
{
    private const EXCEPTION_MESSAGE = '~^\s*(?:an?\s+)?(?:exception|error|failed|failure|unable|could\s+not)\b.*~i';

    private function __construct()
    {
    }

    /**
     * Adds points to every repository that shares a generic exception message
     * with at least one other repository.
     *
     * @param AnalysisContext[] $results
     */
    public static function analyze(array $results): void
    {
        /** @var array<string, array<string, AnalysisContext>> $byTemplate */
        $byTemplate = [];

        foreach ($results as $context) {
            if (!self::isDao($context)) {
                continue;
            }

            foreach (AnalyzerSupport::stringLiterals($context->getCode()) as $rawMessage) {
                $message = trim($rawMessage);

                if (strlen($message) < 12 || strlen($message) > 200) {
                    continue;
                }

                if (preg_match(self::EXCEPTION_MESSAGE, $message) !== 1) {
                    continue;
                }

                $template = self::normalizeMessageTemplate($message);
                $byTemplate[$template][spl_object_id($context)] = $context;
            }
        }

        foreach ($byTemplate as $template => $files) {
            if (count($files) < 2) {
                continue;
            }

            foreach ($files as $context) {
                $context->addPoints(
                    Categories::LOGIC,
                    4,
                    'Cross-file consistency: the same generic exception '
                        . 'message appears in ' . count($files)
                        . ' repository files: "' . $template . ' ..."'
                );

                $context->addTechnicalNote(
                    'The same exception sentence is reused across '
                        . count($files) . ' repositories; check whether it was '
                        . 'copied from one template.'
                );
            }
        }
    }

    private static function isDao(AnalysisContext $context): bool
    {
        return preg_match(
            '~(?:Repository|Dao|DaoImpl)\.php$~i',
            $context->getFile()
        ) === 1;
    }

    private static function normalizeMessageTemplate(string $message): string
    {
        $words = preg_split('~\s+~', trim($message)) ?: [];
        $template = implode(' ', array_slice($words, 0, 5));

        $template = strtolower($template);
        $template = preg_replace('~[^a-z ]~', '', $template) ?? '';

        return trim($template);
    }
}
