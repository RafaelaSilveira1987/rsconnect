<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Apresentação única do assistente também para a primeira resposta determinística.
 * A geração livre da IA já possui proteção própria para o seu turno de abertura.
 */
final class FirstAutomatedReplyService
{
    public static function compose(string $message, array $agent, bool $hasPriorOutgoing): string
    {
        $message = trim($message);
        if ($message === '' || $hasPriorOutgoing) {
            return $message;
        }
        $mode = strtolower(trim((string) ($agent['ai_greeting_mode'] ?? 'all_contacts')));
        $name = trim((string) ($agent['name'] ?? ''));
        if ($mode === 'disabled' || $name === '') {
            return $message;
        }
        // A identificação textual vem do nome público, não do nome do cliente.
        if (preg_match('/\b(?:sou|chamo|aqui [ée])\s+(?:a\s+|o\s+)?' . preg_quote($name, '/') . '\b/iu', $message) === 1) {
            return $message;
        }
        $greeting = trim((string) ($agent['ai_greeting_reply'] ?? ''));
        // O template do LLM pode conter placeholders que este canal não conhece.
        if (str_contains($greeting, '{{')) {
            $greeting = '';
        }
        if ($greeting === '') {
            $greeting = 'Olá! Eu sou ' . $name . ', assistente virtual.';
        } elseif (mb_stripos($greeting, $name) === false) {
            $greeting = rtrim($greeting, '.!? ') . '. Eu sou ' . $name . ', assistente virtual.';
        }
        // Nunca repete uma saudação configurada que já está na resposta.
        if (str_starts_with($message, $greeting)) {
            return $message;
        }
        return $greeting . "\n\n" . $message;
    }
}
