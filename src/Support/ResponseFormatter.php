<?php

namespace Questionnaire\Support;

final class ResponseFormatter
{
    public static function extractContent(array $openAiResponse): string
    {
        if (!isset($openAiResponse['output']) || !is_array($openAiResponse['output'])) {
            return '';
        }

        $chunks = array_filter($openAiResponse['output'], static function ($entry) {
            return isset($entry['content']) && is_array($entry['content']);
        });

        $buffer = '';
        foreach ($chunks as $chunk) {
            foreach ($chunk['content'] as $content) {
                $type = $content['type'] ?? '';

                if ($type === 'output_text' || $type === 'output_text_delta') {
                    $buffer .= $content['text'] ?? '';
                }
            }
        }

        return trim($buffer);
    }

    public static function detectPhase(string $markdown, string $fallback): string
    {
        $normalized = mb_strtolower($markdown);
        return match (true) {
            str_contains($normalized, 'phase finale') || str_contains($normalized, 'phase final') => 'final',
            str_contains($normalized, 'phase sections') || str_contains($normalized, 'phase section') => 'sections',
            str_contains($normalized, 'phase plan') => 'plan',
            str_contains($normalized, 'phase collecte') => 'collecte',
            default => $fallback,
        };
    }

    public static function hasFinalMarkdown(string $markdown): bool
    {
        return str_contains($markdown, "```markdown");
    }
}
