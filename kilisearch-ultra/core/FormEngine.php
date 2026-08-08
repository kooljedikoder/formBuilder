<?php

namespace Kili\Core;

/**
 * Minimal conversational form state machine: one question per turn,
 * validated as it goes, state kept by the caller (chat.php stores it in
 * the PHP session) so the form survives across separate HTTP requests
 * without needing a database.
 */
class FormEngine
{
    /** @var array<string, array<string, mixed>> template id => template */
    private array $templates;

    public function __construct(array $templates)
    {
        $this->templates = [];
        foreach ($templates as $template) {
            $this->templates[$template['id']] = $template;
        }
    }

    public function template(string $id): ?array
    {
        return $this->templates[$id] ?? null;
    }

    public function start(string $templateId): array
    {
        return ['template_id' => $templateId, 'step' => 0, 'data' => []];
    }

    public function currentField(array $state): ?array
    {
        $template = $this->template($state['template_id']);

        return $template['fields'][$state['step']] ?? null;
    }

    /**
     * Validates and records the answer for the current field.
     * @return array{state: array, error: ?string} unchanged state + an error message if the answer was rejected (so the same field is re-asked)
     */
    public function submitAnswer(array $state, string $value): array
    {
        $field = $this->currentField($state);
        if ($field === null) {
            return ['state' => $state, 'error' => null];
        }

        $value = trim($value);
        if (!empty($field['required']) && $value === '') {
            return ['state' => $state, 'error' => $field['label'] . ' is required — could you share that?'];
        }

        $state['data'][$field['key']] = $value;
        $state['step']++;

        return ['state' => $state, 'error' => null];
    }

    public function isComplete(array $state): bool
    {
        $template = $this->template($state['template_id']);

        return $state['step'] >= count($template['fields'] ?? []);
    }

    public function successMessage(array $state): string
    {
        $template = $this->template($state['template_id']);
        $message = $template['success_message'] ?? 'Thanks — we received your request.';

        return preg_replace_callback('/\{(\w+)\}/', fn($m) => $state['data'][$m[1]] ?? '', $message);
    }
}
