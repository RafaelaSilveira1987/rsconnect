<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Crypto;
use App\Core\Env;
use Throwable;

/**
 * Audita a disponibilidade LOCAL de uma chave para o provedor efetivamente
 * utilizado por AiModelService. Não realiza chamadas externas nem expõe segredos.
 *
 * A seleção da credencial (agente > empresa > ambiente) precisa ser idêntica
 * à de AgentRoutingService / AgentSimulationService.
 */
final class AgentCredentialAvailabilityService
{
    /**
     * @param array<string,mixed> $agent
     * @param array<string,mixed>|null $credential Credencial escolhida pelas mesmas prioridades do runtime.
     * @return array{available:bool,provider:string,source:string,message:string}
     */
    public function evaluate(array $agent, ?array $credential): array
    {
        $source = $credential === null ? 'environment'
            : (((int) ($credential['agent_id'] ?? 0)) > 0 ? 'agent' : 'tenant');
        $provider = strtolower(trim((string) ($credential['provider'] ?? '')));
        if ($provider === '') {
            $provider = strtolower(trim((string) ($agent['model_provider'] ?? 'google')));
        }
        if ($provider === '') {
            $provider = 'google'; // Mesmo padrão de AiModelService::provider().
        }

        if (!in_array($provider, ['openai', 'google'], true)) {
            return $this->result(false, $provider, $source,
                'O serviço escolhido para gerar respostas não é compatível com o atendimento automático.');
        }

        $encrypted = trim((string) ($credential['api_key_encrypted'] ?? ''));
        if ($encrypted !== '') {
            try {
                // AiModelService usa a chave criptografada antes de consultar o ambiente.
                // Se a APP_KEY não a descriptografar, a ativação deve continuar bloqueada.
                if (trim(Crypto::decrypt($encrypted)) !== '') {
                    return $this->result(true, $provider, $source,
                        'Acesso ao serviço de respostas encontrado nas configurações ' .
                        ($source === 'agent' ? 'deste assistente.' : 'da empresa.'));
                }
            } catch (Throwable) {
                return $this->result(false, $provider, $source,
                    'A chave salva não pôde ser lida. Peça ao administrador para revisar o acesso e a APP_KEY.');
            }
        }

        // Sem chave criptografada, AiModelService cai neste mesmo fallback.
        $key = match ($provider) {
            'openai' => trim((string) Env::get('OPENAI_API_KEY', '')),
            'google' => trim((string) Env::get('GEMINI_API_KEY', Env::get('GOOGLE_GEMINI_API_KEY', ''))),
        };
        if ($key !== '') {
            return $this->result(true, $provider, 'environment',
                'Acesso ao serviço de respostas disponível pela configuração da plataforma.');
        }

        return $this->result(false, $provider, $source,
            'Não foi encontrada uma chave de acesso utilizável para o serviço de respostas deste assistente. ' .
            'Peça ao administrador para conferir as configurações de acesso.');
    }

    /** @return array{available:bool,provider:string,source:string,message:string} */
    private function result(bool $available, string $provider, string $source, string $message): array
    {
        return compact('available', 'provider', 'source', 'message');
    }
}
