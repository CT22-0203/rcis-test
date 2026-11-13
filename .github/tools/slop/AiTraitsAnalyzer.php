<?php

declare(strict_types=1);

namespace Slop;

/**
 * Detects explicit generation markers and unfinished implementation
 * placeholders. These are the strongest, least ambiguous signals the detector
 * has, so they carry the most weight.
 *
 * Markers are not limited to ChatGPT: attributions to other assistants and
 * coding agents (Copilot, Claude Code, Gemini, Cursor, Codex, DeepSeek, Qwen,
 * Aider, Devin, ...) are recognised as well, including the common
 * "Co-authored-by:" and "@author" trailers.
 */
final class AiTraitsAnalyzer implements CodeAnalyzer
{
    /**
     * Assistant and model names that can appear in an attribution.
     */
    private const TOOLS =
        'github\s+copilot'
        . '|copilot-swe-agent|copilot'
        . '|claude\s+code|claude|anthropic'
        . '|chatgpt|gpt[-\w.]*|openai|codex'
        . '|gemini|bard|google\s+ai|vertex\s+ai'
        . '|cursor|windsurf|codeium|tabnine'
        . '|amazon\s+q|aws\s+codewhisperer|codewhisperer'
        . '|sourcegraph\s+cody'
        . '|deepseek|qwen|chatglm|ernie|doubao|kimi|minimax'
        . '|grok|xai|llama|meta\s+ai|mistral|codestral|perplexity'
        . '|devin|aider|cline|roo\s+code|kilo\s+code|trae'
        . '|continue\.dev'
        . '|bolt\.new|lovable|v0\.dev'
        . '|jetbrains\s+ai|ollama|groq|together\s+ai'
        . '|hugging\s+face|openrouter|fireworks\s+ai|replit'
        . '|llm|large\s+language\s+model|ai|a\.i\.';

    private const TOOL_MARKER =
        '~(?:generated|written|created|produced|authored|made|autocompleted'
        . '|suggested|assisted|powered)\s+(?:by|with|using)\s*\[?\s*'
        . '(?:an?\s+|the\s+)?(' . self::TOOLS . ')(?![\w])'
        . '|co-authored-by:\s*\[?\s*(' . self::TOOLS . ')(?![\w])'
        . '|@author\s+(' . self::TOOLS . ')(?![\w])~i';

    private const GENERIC_MARKER =
        '~(?:ai|llm)[-\s](?:generated|assisted|powered)'
        . '|with\s+ai\s+assistance'
        . '|vibe[-\s]coded'
        . '|this\s+(?:code|file|class|method)\s+(?:was|is|has\s+been)\s+'
        . '(?:auto-?)?generated'
        . '|\x{1F916}\s*(?:generated|written|created)\s+with~iu';

    private const PLACEHOLDER =
        '~TODO\s*:\s*implement|TODO\s+implement'
        . '|implementation\s+goes\s+here|your\s+code\s+here'
        . '|add\s+your\s+(?:code|logic)\s+here'
        . '|not\s+implemented\s+yet|lorem\s+ipsum~i';

    private const EMPTY_CATCH = '~catch\s*\([^)]*\)\s*\{\s*\}~';

    private const UNSUPPORTED =
        '~\b(?:UnsupportedOperationException|BadMethodCallException'
        . '|NotImplementedException)\b~';

    public function analyze(AnalysisContext $context): void
    {
        $raw = $context->getCode();
        $code = SourceText::stripCommentsAndLiterals($raw);

        // Comments are where generation markers and TODOs normally live, but
        // they must not be counted when they only appear inside a string
        // literal (test fixtures, messages, SQL).
        $prose = $context->getComments() . "\n" . $code;

        $marker = $this->findMarker($prose);

        if ($marker !== null) {
            $context->addPoints(
                Categories::AI_TRAITS,
                20,
                'Explicit AI-generation marker detected: "' . $marker . '"'
            );
        }

        $placeholder = $this->findPlaceholder($prose);

        if ($placeholder !== null) {
            $context->addPoints(
                Categories::AI_TRAITS,
                6,
                'Unfinished implementation placeholder: "' . $placeholder . '"'
            );
        }

        $unsupported = AnalyzerSupport::countPattern(self::UNSUPPORTED, $code);
        $todos = AnalyzerSupport::count($prose, 'TODO');

        if ($unsupported >= 2 && $todos >= 2) {
            $context->addPoints(
                Categories::AI_TRAITS,
                6,
                'Multiple unfinished implementation indicators '
                    . '(TODO + not-implemented exception).'
            );
        }

        if (preg_match(self::EMPTY_CATCH, $code) === 1) {
            $context->addPoints(
                Categories::AI_TRAITS,
                4,
                'Empty exception-handling block detected.'
            );
        }
    }

    /**
     * Returns a short description of the first generation marker found. A
     * named assistant or model is preferred over a generic phrase, so the
     * report can say which tool was credited.
     */
    private function findMarker(string $source): ?string
    {
        $tool = $this->firstCaptured(self::TOOL_MARKER, $source);

        if ($tool !== null) {
            return $tool;
        }

        if (preg_match(self::GENERIC_MARKER, $source, $matches) === 1) {
            return AnalyzerSupport::truncate($matches[0], 40);
        }

        return null;
    }

    private function firstCaptured(string $pattern, string $source): ?string
    {
        if (preg_match($pattern, $source, $matches) !== 1) {
            return null;
        }

        for ($group = 1; $group < count($matches); $group++) {
            if (isset($matches[$group]) && $matches[$group] !== '') {
                return AnalyzerSupport::truncate($matches[$group], 40);
            }
        }

        return AnalyzerSupport::truncate($matches[0], 40);
    }

    private function findPlaceholder(string $source): ?string
    {
        if (preg_match(self::PLACEHOLDER, $source, $matches) !== 1) {
            return null;
        }

        return AnalyzerSupport::truncate($matches[0], 60);
    }
}
