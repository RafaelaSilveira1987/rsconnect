<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Responde somente a mensagens curtas e inequívocas configuradas pelo cliente.
 * Sem resposta configurada, o fluxo segue normalmente para a IA.
 */
final class AiLocalReplyService
{
    /** @return array{matched:bool,type:?string,reply:?string,normalized:string} */
    public function match(array $agent, string $message, array $conversation = []): array
    {
        $normalized = $this->normalize($message);
        if ((int) ($agent['ai_local_replies_enabled'] ?? 1) !== 1 || $normalized === '' || $this->length($normalized) > 60) {
            return ['matched' => false, 'type' => null, 'reply' => null, 'normalized' => $normalized];
        }

        $rules = [
            'greeting' => [
                'reply' => trim((string) ($agent['ai_greeting_reply'] ?? '')),
                'patterns' => ['oi', 'ola', 'bom dia', 'boa tarde', 'boa noite', 'oi tudo bem', 'ola tudo bem'],
            ],
            'gratitude' => [
                'reply' => trim((string) ($agent['ai_gratitude_reply'] ?? '')),
                'patterns' => ['obrigado', 'obrigada', 'muito obrigado', 'muito obrigada', 'valeu', 'agradeco', 'agradeço'],
            ],
            'farewell' => [
                'reply' => trim((string) ($agent['ai_farewell_reply'] ?? '')),
                'patterns' => ['tchau', 'ate logo', 'até logo', 'ate mais', 'até mais', 'falou'],
            ],
            'menu' => [
                'reply' => trim((string) ($agent['ai_menu_reply'] ?? '')),
                'patterns' => ['menu', 'opcoes', 'opções', 'ajuda', 'ver opcoes', 'ver opções'],
            ],
        ];

        foreach ($rules as $type => $rule) {
            if ($rule['reply'] === '' || !in_array($normalized, array_map([$this, 'normalize'], $rule['patterns']), true)) {
                continue;
            }

            if ($type === 'greeting' && !$this->greetingAllowed($agent, $conversation)) {
                continue;
            }

            return ['matched' => true, 'type' => $type, 'reply' => $rule['reply'], 'normalized' => $normalized];
        }

        return ['matched' => false, 'type' => null, 'reply' => null, 'normalized' => $normalized];
    }

    private function greetingAllowed(array $agent, array $conversation): bool
    {
        $mode = strtolower(trim((string) ($agent['ai_greeting_mode'] ?? 'all_contacts')));
        if (!in_array($mode, ['all_contacts', 'new_contacts', 'disabled'], true)) {
            $mode = 'all_contacts';
        }
        $isOpeningTurn = !array_key_exists('_is_opening_turn', $conversation) || !empty($conversation['_is_opening_turn']);
        if ($mode === 'disabled' || !$isOpeningTurn) {
            return false;
        }
        if ($mode === 'all_contacts') {
            return true;
        }

        $relationship = (new ConversationFlowService())->relationshipProfile([
            'status' => (string) ($conversation['contact_status'] ?? $conversation['status'] ?? ''),
            'contact_status' => (string) ($conversation['contact_status'] ?? ''),
            'contact_group' => (string) ($conversation['contact_group'] ?? 'unclassified'),
            'tags_json' => $conversation['tags_json'] ?? null,
        ]);

        return empty($relationship['is_existing_customer']);
    }

    public function normalize(string $value): string
    {
        $value = function_exists('mb_strtolower') ? mb_strtolower(trim($value)) : strtolower(trim($value));
        $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if (is_string($converted) && $converted !== '') {
            $value = $converted;
        }
        $value = preg_replace('/[^a-z0-9\s]+/i', ' ', $value) ?? $value;
        return trim((string) preg_replace('/\s+/', ' ', $value));
    }
    private function length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
    }

}
