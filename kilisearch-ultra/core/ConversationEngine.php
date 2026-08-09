<?php

namespace Killi\Core;

/**
 * Deterministic, rule-based conversational layer — keyword pattern
 * matching and templated responses, no external AI/LLM call. Intent
 * patterns and response copy live in config/conversation.json so an
 * admin can edit tone/wording without touching code.
 */
class ConversationEngine
{
    /** @var array<int, array<string, mixed>> */
    private array $intents;

    public function __construct(array $config)
    {
        $this->intents = $config['intents'] ?? [];
    }

    public function detectIntent(string $message): string
    {
        $normalized = mb_strtolower(trim($message));
        if ($normalized === '') {
            return 'unknown';
        }

        foreach ($this->intents as $intent) {
            foreach ($intent['patterns'] ?? [] as $pattern) {
                if ($pattern !== '' && str_contains($normalized, mb_strtolower($pattern))) {
                    return $intent['id'];
                }
            }
        }

        // No small-talk pattern matched — treat it as a search query.
        return 'find_service';
    }

    /**
     * @param array{query?:string,count?:int,location?:string} $vars
     */
    public function respond(string $intentId, array $vars = []): string
    {
        $intent = $this->findIntent($intentId);
        if ($intent === null) {
            return '';
        }

        $responses = $intent['responses'] ?? [];

        // find_service responses are bucketed by result count (zero/one/many).
        if (isset($vars['count']) && is_array($responses) && isset($responses['many'])) {
            $bucket = $vars['count'] === 0 ? 'zero' : ($vars['count'] === 1 ? 'one' : 'many');
            $responses = $responses[$bucket] ?? [];
        }

        if (empty($responses)) {
            return '';
        }

        $template = $responses[array_rand($responses)];

        return $this->fillTemplate($template, $vars);
    }

    private function findIntent(string $id): ?array
    {
        foreach ($this->intents as $intent) {
            if ($intent['id'] === $id) {
                return $intent;
            }
        }

        return null;
    }

    private function fillTemplate(string $template, array $vars): string
    {
        $vars['location_suffix'] = !empty($vars['location']) ? ' in ' . $vars['location'] : '';

        return preg_replace_callback('/\{(\w+)\}/', function ($m) use ($vars) {
            return isset($vars[$m[1]]) ? (string) $vars[$m[1]] : '';
        }, $template);
    }
}
