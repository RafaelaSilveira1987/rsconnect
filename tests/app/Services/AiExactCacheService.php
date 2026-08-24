<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use Throwable;

/**
 * Cache conservador: somente perguntas curtas, exatas e com recurso ativado.
 * A chave inclui prompt/base/modelo para invalidar respostas quando a configuração muda.
 */
final class AiExactCacheService
{
    public function __construct(private readonly AiLocalReplyService $normalizer = new AiLocalReplyService())
    {
    }

    /** @return array{hit:bool,reply:?string,cache_id:?int,normalized:string} */
    public function lookup(PDO $pdo, int $tenantId, array $agent, string $message): array
    {
        $normalized = $this->normalizer->normalize($message);
        if (!$this->enabled($agent) || !$this->eligibleQuestion($normalized)) {
            return ['hit' => false, 'reply' => null, 'cache_id' => null, 'normalized' => $normalized];
        }

        try {
            $statement = $pdo->prepare(
                'SELECT id, response
                 FROM ai_response_cache
                 WHERE tenant_id = :tenant_id
                   AND agent_id = :agent_id
                   AND question_hash = :question_hash
                   AND context_hash = :context_hash
                   AND expires_at > NOW()
                 LIMIT 1'
            );
            $statement->execute([
                'tenant_id' => $tenantId,
                'agent_id' => (int) ($agent['id'] ?? 0),
                'question_hash' => hash('sha256', $normalized),
                'context_hash' => $this->contextHash($agent),
            ]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            if (!$row || trim((string) ($row['response'] ?? '')) === '') {
                return ['hit' => false, 'reply' => null, 'cache_id' => null, 'normalized' => $normalized];
            }

            $pdo->prepare('UPDATE ai_response_cache SET hits = hits + 1, last_hit_at = NOW() WHERE id = :id')
                ->execute(['id' => (int) $row['id']]);

            return [
                'hit' => true,
                'reply' => (string) $row['response'],
                'cache_id' => (int) $row['id'],
                'normalized' => $normalized,
            ];
        } catch (Throwable) {
            return ['hit' => false, 'reply' => null, 'cache_id' => null, 'normalized' => $normalized];
        }
    }

    public function store(PDO $pdo, int $tenantId, array $agent, string $message, string $reply): void
    {
        $normalized = $this->normalizer->normalize($message);
        $reply = trim($reply);
        if (!$this->enabled($agent) || !$this->eligibleQuestion($normalized) || !$this->eligibleReply($reply)) {
            return;
        }

        $ttlHours = max(1, min(720, (int) ($agent['ai_exact_cache_ttl_hours'] ?? 168)));
        try {
            $statement = $pdo->prepare(
                'INSERT INTO ai_response_cache
                    (tenant_id, agent_id, question_hash, context_hash, normalized_question, response, expires_at)
                 VALUES
                    (:tenant_id, :agent_id, :question_hash, :context_hash, :normalized_question, :response,
                     :expires_at)
                 ON DUPLICATE KEY UPDATE
                    normalized_question = VALUES(normalized_question),
                    response = VALUES(response),
                    expires_at = VALUES(expires_at),
                    updated_at = NOW()'
            );
            $statement->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
            $statement->bindValue(':agent_id', (int) ($agent['id'] ?? 0), PDO::PARAM_INT);
            $statement->bindValue(':question_hash', hash('sha256', $normalized));
            $statement->bindValue(':context_hash', $this->contextHash($agent));
            $statement->bindValue(':normalized_question', $this->substring($normalized, 0, 500));
            $statement->bindValue(':response', $this->substring($reply, 0, 4000));
            $statement->bindValue(':expires_at', date('Y-m-d H:i:s', time() + ($ttlHours * 3600)));
            $statement->execute();
        } catch (Throwable) {
            // Sem migration ou banco indisponível, o atendimento segue pela IA normalmente.
        }
    }

    private function enabled(array $agent): bool
    {
        return (int) ($agent['ai_exact_cache_enabled'] ?? 0) === 1 && (int) ($agent['id'] ?? 0) > 0;
    }

    private function eligibleQuestion(string $normalized): bool
    {
        $length = $this->length($normalized);
        if ($length < 8 || $length > 240 || substr_count($normalized, ' ') < 1) {
            return false;
        }
        if (preg_match('/(?:https?:\/\/|www\.|@|\b\d{4,}\b)/i', $normalized)) {
            return false;
        }
        if (preg_match('/\b(meu|minha|meus|minhas|eu|agora|ontem|hoje|amanha|amanhã|aqui|esse|essa|isso|pedido|protocolo|agendar|remarcar|cancelar|disponibilidade)\b/i', $normalized)) {
            return false;
        }

        return (bool) preg_match('/^(qual|quais|como|quanto|quantos|onde|quando|voces|vocês|aceita|tem|atende|funciona|horario|horário|endereco|endereço|valor|preco|preço|posso|e possivel|é possível)\b/i', $normalized);
    }

    private function eligibleReply(string $reply): bool
    {
        if ($reply === '' || $this->length($reply) > 4000) {
            return false;
        }
        return !preg_match('/\b(conforme conversamos|voce informou|você informou|seu pedido|sua solicitacao|sua solicitação|seu agendamento)\b/i', $reply);
    }

    private function length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
    }

    private function substring(string $value, int $start, int $length): string
    {
        return function_exists('mb_substr') ? mb_substr($value, $start, $length) : substr($value, $start, $length);
    }

    private function contextHash(array $agent): string
    {
        return hash('sha256', json_encode([
            'prompt' => (string) ($agent['system_prompt'] ?? ''),
            'knowledge' => (string) ($agent['knowledge_base'] ?? ''),
            'model' => (string) ($agent['_ai_selected_model'] ?? $agent['model_name'] ?? ''),
            'temperature' => (string) ($agent['temperature'] ?? ''),
            'business_hours' => (string) ($agent['business_hours_json'] ?? ''),
            'business_timezone' => (string) ($agent['business_timezone'] ?? ''),
            'updated_at' => (string) ($agent['updated_at'] ?? ''),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
    }
}
