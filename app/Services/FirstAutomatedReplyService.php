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
        $configuredName = trim((string) ($agent['name'] ?? ''));
        if ($mode === 'disabled' || $configuredName === '') {
            return $message;
        }

        // Se a própria mensagem determinística já se apresentou, não injeta outra abertura.
        if (self::containsAssistantIdentity($message, $configuredName)) {
            return $message;
        }

        $greeting = trim((string) ($agent['ai_greeting_reply'] ?? ''));
        // O template do LLM pode conter placeholders que este canal não conhece.
        if (str_contains($greeting, '{{')) {
            $greeting = '';
        }

        $spokenName = self::spokenName($configuredName);
        if ($greeting === '') {
            $greeting = 'Olá! Eu sou ' . $spokenName . ', assistente virtual.';
        } elseif (!self::containsAssistantIdentity($greeting, $configuredName)) {
            // O campo nome pode conter também a função (ex.: "Rafa, Assistente da psicóloga...").
            // Na fala, usa apenas a parte nominal para não produzir frases redundantes.
            $greeting = rtrim($greeting, '.!? ') . '. Eu sou ' . $spokenName . ', assistente virtual.';
        }

        // Nunca repete uma saudação configurada que já está na resposta.
        if (str_starts_with($message, $greeting)) {
            return $message;
        }

        return $greeting . "\n\n" . $message;
    }

    private static function spokenName(string $configuredName): string
    {
        $parts = preg_split('/[,;|–—]/u', trim($configuredName), 2);
        $name = trim((string) ($parts[0] ?? $configuredName));
        return $name !== '' ? $name : trim($configuredName);
    }

    private static function containsAssistantIdentity(string $text, string $configuredName): bool
    {
        $text = trim($text);
        $configuredName = trim($configuredName);
        if ($text === '' || $configuredName === '') {
            return false;
        }

        // O nome configurado completo é evidência suficiente, mas com fronteiras
        // de palavra para não confundir "Rafa" com "Rafaela".
        $configuredToken = '(?<![\p{L}\p{N}])' . preg_quote($configuredName, '/') . '(?![\p{L}\p{N}])';
        if (preg_match('/' . $configuredToken . '/iu', $text) === 1) {
            return true;
        }

        $spokenName = self::spokenName($configuredName);
        if ($spokenName === '') {
            return false;
        }

        $token = '(?<![\p{L}\p{N}])' . preg_quote($spokenName, '/') . '(?![\p{L}\p{N}])';

        // Identificação explícita: "eu sou Rafa", "aqui é a Rafa", "me chamo Rafa" etc.
        if (preg_match('/\b(?:eu\s+sou|sou|me\s+chamo|meu\s+nome\s+[ée]|aqui\s+[ée])\s+(?:a\s+|o\s+)?' . $token . '/iu', $text) === 1) {
            return true;
        }

        // Também aceita a forma nominal usada em saudações: "Rafa, assistente...".
        if (preg_match('/' . $token . '.{0,90}\bassistente\b|\bassistente\b.{0,90}' . $token . '/iu', $text) === 1) {
            return true;
        }

        return false;
    }
}
