<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Env;

/**
 * Centraliza a política de economia de contexto e saída da IA.
 *
 * O modo define tetos seguros, mas não troca o modelo automaticamente a menos
 * que uma variável AI_MODEL_<PROVIDER>_<MODE> esteja configurada.
 */
final class AiEfficiencyPolicyService
{
    public const MODES = ['economy', 'balanced', 'quality'];

    /** @return array{mode:string,history_limit:int,max_output_tokens:int,knowledge_budget_chars:int,selective_knowledge:bool,model_override:string} */
    public function profile(array $agent): array
    {
        $mode = strtolower(trim((string) ($agent['ai_efficiency_mode'] ?? 'balanced')));
        if (!in_array($mode, self::MODES, true)) {
            $mode = 'balanced';
        }

        $defaults = match ($mode) {
            'economy' => [
                'history_limit' => 6,
                'max_output_tokens' => 160,
                'knowledge_budget_chars' => 6000,
            ],
            'quality' => [
                'history_limit' => 20,
                'max_output_tokens' => 420,
                'knowledge_budget_chars' => 22000,
            ],
            default => [
                'history_limit' => 10,
                'max_output_tokens' => 260,
                'knowledge_budget_chars' => 11000,
            ],
        };

        $configuredHistory = max(4, min(30, (int) ($agent['max_context_messages'] ?? 12)));
        $historyLimit = $mode === 'quality'
            ? min($configuredHistory, $defaults['history_limit'])
            : min($configuredHistory, $defaults['history_limit']);

        $customOutput = (int) ($agent['ai_max_output_tokens'] ?? 0);
        $maxOutput = $customOutput > 0
            ? max(64, min(2000, $customOutput))
            : (int) $defaults['max_output_tokens'];

        $customKnowledge = (int) ($agent['ai_knowledge_budget_chars'] ?? 0);
        $knowledgeBudget = $customKnowledge > 0
            ? max(1000, min(120000, $customKnowledge))
            : (int) $defaults['knowledge_budget_chars'];

        $selectiveKnowledge = !array_key_exists('ai_selective_knowledge', $agent)
            || (int) ($agent['ai_selective_knowledge'] ?? 1) === 1;

        $provider = strtolower(trim((string) ($agent['credential_provider'] ?? $agent['model_provider'] ?? 'openai')));
        $envProvider = strtoupper(preg_replace('/[^A-Z0-9]+/', '_', strtoupper($provider)) ?: 'OPENAI');
        $envMode = strtoupper($mode);
        $modelOverride = trim((string) Env::get('AI_MODEL_' . $envProvider . '_' . $envMode, ''));

        return [
            'mode' => $mode,
            'history_limit' => max(4, $historyLimit),
            'max_output_tokens' => $maxOutput,
            'knowledge_budget_chars' => $knowledgeBudget,
            'selective_knowledge' => $selectiveKnowledge,
            'model_override' => $modelOverride,
        ];
    }

    public function label(string $mode): string
    {
        return match ($mode) {
            'economy' => 'Econômico',
            'quality' => 'Qualidade máxima',
            default => 'Equilibrado',
        };
    }
}
