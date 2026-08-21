<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Decide o perfil efetivo antes de chamar o provedor.
 * Nesta fase o roteador é conservador: não muda de modelo por heurística.
 * A troca só ocorre quando o ambiente define explicitamente um modelo por modo.
 */
final class AiRouterService
{
    public function __construct(private readonly AiEfficiencyPolicyService $policy = new AiEfficiencyPolicyService())
    {
    }

    /** @return array{strategy:string,mode:string,complexity:string,agent_overrides:array<string,mixed>,profile:array<string,mixed>} */
    public function route(array $agent, array $conversation, string $incomingContent): array
    {
        $profile = $this->policy->profile($agent);
        $complexity = $this->complexity($incomingContent, $conversation);

        $overrides = [
            '_ai_efficiency_mode' => $profile['mode'],
            '_ai_max_output_tokens' => $profile['max_output_tokens'],
        ];
        if ($profile['model_override'] !== '') {
            $overrides['_ai_selected_model'] = $profile['model_override'];
        }

        return [
            'strategy' => 'provider_ai',
            'mode' => $profile['mode'],
            'complexity' => $complexity,
            'agent_overrides' => $overrides,
            'profile' => $profile,
        ];
    }

    private function complexity(string $message, array $conversation): string
    {
        $message = trim($message);
        $length = mb_strlen($message);
        $questionMarks = substr_count($message, '?');
        $intent = strtolower(trim((string) ($conversation['last_intent'] ?? '')));

        if ($length >= 550 || $questionMarks >= 3 || in_array($intent, ['schedule', 'reschedule'], true)) {
            return 'complex';
        }
        if ($length <= 90 && $questionMarks <= 1) {
            return 'simple';
        }
        return 'normal';
    }
}
