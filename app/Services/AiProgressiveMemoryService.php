<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use Throwable;

/**
 * Mantém um resumo progressivo pequeno e fatos estruturados da conversa.
 * A atualização ocorre somente a cada N mensagens para que a própria memória
 * não vire uma nova fonte de custo desnecessário.
 */
final class AiProgressiveMemoryService
{
    public function __construct(
        private readonly AiModelService $model = new AiModelService(),
        private readonly AiUsageService $usage = new AiUsageService()
    ) {
    }

    /** @return array<string,mixed>|null */
    public function context(PDO $pdo, int $conversationId): ?array
    {
        if ($conversationId < 1) {
            return null;
        }
        try {
            $statement = $pdo->prepare(
                'SELECT summary_text, facts_json, source_message_id, source_message_count,
                        refresh_count, last_refreshed_at, status
                 FROM conversation_ai_memory
                 WHERE conversation_id = :conversation_id
                 LIMIT 1'
            );
            $statement->execute(['conversation_id' => $conversationId]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            if ($row && trim((string) ($row['summary_text'] ?? '')) !== '') {
                return $this->memoryRow($row, 'conversation');
            }

            // Se uma nova conversa foi aberta para o mesmo contato, reaproveita a
            // memória consolidada do contato sem carregar o histórico antigo.
            $contactStatement = $pdo->prepare(
                'SELECT cm.summary_text, cm.facts_json, NULL AS source_message_id, 0 AS source_message_count,
                        cm.refresh_count, cm.last_refreshed_at, cm.status
                 FROM conversations c
                 INNER JOIN contact_ai_memory cm
                    ON cm.tenant_id = c.tenant_id AND cm.contact_id = c.contact_id
                 WHERE c.id = :conversation_id
                 LIMIT 1'
            );
            $contactStatement->execute(['conversation_id' => $conversationId]);
            $contactRow = $contactStatement->fetch(PDO::FETCH_ASSOC);
            if ($contactRow && trim((string) ($contactRow['summary_text'] ?? '')) !== '') {
                return $this->memoryRow($contactRow, 'contact');
            }
            return null;
        } catch (Throwable) {
            // Compatibilidade enquanto a migration 080 ainda não tiver sido aplicada.
            return null;
        }
    }

    /** @return array<string,mixed> */
    private function memoryRow(array $row, string $scope): array
    {
        $facts = json_decode((string) ($row['facts_json'] ?? ''), true);
        return [
            'summary' => trim((string) ($row['summary_text'] ?? '')),
            'facts' => is_array($facts) ? $facts : [],
            'source_message_id' => (int) ($row['source_message_id'] ?? 0),
            'source_message_count' => (int) ($row['source_message_count'] ?? 0),
            'refresh_count' => (int) ($row['refresh_count'] ?? 0),
            'last_refreshed_at' => $row['last_refreshed_at'] ?? null,
            'status' => (string) ($row['status'] ?? 'active'),
            'scope' => $scope,
        ];
    }

    /** @return array<string,mixed> */
    public function refreshIfNeeded(PDO $pdo, int $tenantId, array $agent, array $conversation, bool $force = false): array
    {
        $conversationId = (int) ($conversation['id'] ?? 0);
        if ($tenantId < 1 || $conversationId < 1 || (int) ($agent['ai_progressive_memory_enabled'] ?? 1) !== 1) {
            return ['status' => 'disabled'];
        }

        $threshold = max(4, min(30, (int) ($agent['ai_memory_refresh_messages'] ?? 8)));
        $maxChars = max(800, min(6000, (int) ($agent['ai_memory_max_chars'] ?? 2200)));
        $current = $this->context($pdo, $conversationId);
        $sourceMessageId = (int) ($current['source_message_id'] ?? 0);

        try {
            $countStatement = $pdo->prepare(
                'SELECT COUNT(*)
                 FROM conversation_messages
                 WHERE conversation_id = :conversation_id
                   AND id > :source_message_id
                   AND NOT (direction = "outgoing" AND status = "failed")'
            );
            $countStatement->execute([
                'conversation_id' => $conversationId,
                'source_message_id' => $sourceMessageId,
            ]);
            $newCount = (int) $countStatement->fetchColumn();
        } catch (Throwable $exception) {
            return ['status' => 'error', 'error' => $exception->getMessage()];
        }

        $minimumForFirstMemory = max(6, $threshold);
        if (!$force && $newCount < ($current === null ? $minimumForFirstMemory : $threshold)) {
            return ['status' => 'skipped', 'new_messages' => $newCount, 'threshold' => $threshold];
        }

        try {
            $limit = max(10, min(40, $threshold * 3));
            $statement = $pdo->prepare(
                'SELECT id, direction, sender_type, content, sent_at
                 FROM conversation_messages
                 WHERE conversation_id = :conversation_id
                   AND id > :source_message_id
                   AND NOT (direction = "outgoing" AND status = "failed")
                   AND TRIM(COALESCE(content, "")) <> ""
                 ORDER BY id ASC
                 LIMIT ' . $limit
            );
            $statement->execute([
                'conversation_id' => $conversationId,
                'source_message_id' => $sourceMessageId,
            ]);
            $messages = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
            if ($messages === []) {
                return ['status' => 'skipped', 'new_messages' => 0];
            }

            $lastMessageId = (int) ($messages[array_key_last($messages)]['id'] ?? 0);
            $transcript = [];
            foreach ($messages as $message) {
                $speaker = ((string) ($message['direction'] ?? '') === 'incoming') ? 'Cliente' : (((string) ($message['sender_type'] ?? '') === 'user') ? 'Atendente' : 'Assistente');
                $content = trim((string) ($message['content'] ?? ''));
                if ($content !== '') {
                    $transcript[] = $speaker . ': ' . mb_substr($content, 0, 1200);
                }
            }

            $existingSummary = trim((string) ($current['summary'] ?? ''));
            $existingFacts = is_array($current['facts'] ?? null) ? $current['facts'] : [];
            $input = "RESUMO ANTERIOR:\n" . ($existingSummary !== '' ? $existingSummary : '(nenhum)')
                . "\n\nFATOS ANTERIORES:\n" . json_encode($existingFacts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                . "\n\nNOVAS MENSAGENS:\n" . implode("\n", $transcript);
            $input = mb_substr($input, 0, 18000);

            $instructions = 'Você atualiza memória operacional de atendimento. Retorne SOMENTE JSON válido, sem markdown. '
                . 'Formato: {"summary":"...","facts":{"name":null,"relationship":null,"interests":[],"preferences":[],"important_facts":[],"pending_items":[],"commitments":[],"restrictions":[],"last_intent":null,"next_action":null}}. '
                . 'O summary deve ser objetivo, cumulativo e preservar decisões, pedidos, respostas já dadas, pendências e contexto necessário para continuar sem repetir perguntas. '
                . 'Nos facts, mantenha somente informações explícitas ou fortemente confirmadas na conversa; não invente dados, diagnósticos, preços, datas ou preferências. '
                . 'Remova fatos superados quando as novas mensagens os corrigirem.';

            // A memória é uma chamada técnica e também respeita o orçamento quando
            // utiliza credencial custeada pela RS Connect. Não faz sentido resumir
            // consumindo mais saldo depois de um bloqueio financeiro.
            if ($this->usage->credentialOwner($agent) === 'rs_connect') {
                $budgetDecision = (new AiBudgetPolicyService())->decision($tenantId);
                if (empty($budgetDecision['allowed'])) {
                    return [
                        'status' => 'budget_blocked',
                        'new_messages' => count($messages),
                        'budget_usd' => $budgetDecision['budget_usd'] ?? null,
                        'used_usd' => $budgetDecision['used_usd'] ?? null,
                    ];
                }
            }

            $raw = $this->model->generateCompactTask($agent, $instructions, $input, 360);
            $payload = $this->decodeJson($raw);
            $summary = trim((string) ($payload['summary'] ?? ''));
            if ($summary === '') {
                throw new \RuntimeException('A memória progressiva retornou um resumo vazio.');
            }
            $summary = mb_substr($summary, 0, $maxChars);
            $facts = $this->sanitizeFacts(is_array($payload['facts'] ?? null) ? $payload['facts'] : []);
            $modelUsage = $this->model->lastUsage();

            $totalStatement = $pdo->prepare(
                'SELECT COUNT(*) FROM conversation_messages WHERE conversation_id = :conversation_id'
            );
            $totalStatement->execute(['conversation_id' => $conversationId]);
            $totalCount = (int) $totalStatement->fetchColumn();

            $upsert = $pdo->prepare(
                'INSERT INTO conversation_ai_memory
                    (tenant_id, conversation_id, contact_id, agent_id, summary_text, facts_json,
                     source_message_id, source_message_count, refresh_count, last_provider, last_model,
                     last_input_tokens, last_output_tokens, last_refreshed_at, status, last_error)
                 VALUES
                    (:tenant_id, :conversation_id, :contact_id, :agent_id, :summary_text, :facts_json,
                     :source_message_id, :source_message_count, 1, :last_provider, :last_model,
                     :last_input_tokens, :last_output_tokens, NOW(), "active", NULL)
                 ON DUPLICATE KEY UPDATE
                    contact_id = VALUES(contact_id), agent_id = VALUES(agent_id),
                    summary_text = VALUES(summary_text), facts_json = VALUES(facts_json),
                    source_message_id = VALUES(source_message_id), source_message_count = VALUES(source_message_count),
                    refresh_count = refresh_count + 1, last_provider = VALUES(last_provider), last_model = VALUES(last_model),
                    last_input_tokens = VALUES(last_input_tokens), last_output_tokens = VALUES(last_output_tokens),
                    last_refreshed_at = NOW(), status = "active", last_error = NULL'
            );
            $upsert->execute([
                'tenant_id' => $tenantId,
                'conversation_id' => $conversationId,
                'contact_id' => (int) ($conversation['contact_id'] ?? 0) ?: null,
                'agent_id' => (int) ($agent['id'] ?? 0) ?: null,
                'summary_text' => $summary,
                'facts_json' => json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'source_message_id' => $lastMessageId ?: null,
                'source_message_count' => $totalCount,
                'last_provider' => $modelUsage['provider'] ?? null,
                'last_model' => $modelUsage['model'] ?? null,
                'last_input_tokens' => $modelUsage['input_tokens'] ?? null,
                'last_output_tokens' => $modelUsage['output_tokens'] ?? null,
            ]);

            $contactId = (int) ($conversation['contact_id'] ?? 0);
            if ($contactId > 0) {
                $contactUpsert = $pdo->prepare(
                    'INSERT INTO contact_ai_memory
                        (tenant_id, contact_id, last_conversation_id, agent_id, summary_text, facts_json,
                         refresh_count, last_refreshed_at, status, last_error)
                     VALUES
                        (:tenant_id, :contact_id, :conversation_id, :agent_id, :summary_text, :facts_json,
                         1, NOW(), "active", NULL)
                     ON DUPLICATE KEY UPDATE
                        last_conversation_id = VALUES(last_conversation_id), agent_id = VALUES(agent_id),
                        summary_text = VALUES(summary_text), facts_json = VALUES(facts_json),
                        refresh_count = refresh_count + 1, last_refreshed_at = NOW(),
                        status = "active", last_error = NULL'
                );
                $contactUpsert->execute([
                    'tenant_id' => $tenantId,
                    'contact_id' => $contactId,
                    'conversation_id' => $conversationId,
                    'agent_id' => (int) ($agent['id'] ?? 0) ?: null,
                    'summary_text' => $summary,
                    'facts_json' => json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]);
            }

            $this->usage->recordTechnicalEvent($tenantId, $agent, $conversationId, 'summary', 'success', $modelUsage);

            return [
                'status' => 'refreshed',
                'summary' => $summary,
                'facts' => $facts,
                'source_message_id' => $lastMessageId,
                'new_messages' => count($messages),
                'usage' => $modelUsage,
            ];
        } catch (Throwable $exception) {
            try {
                $pdo->prepare(
                    'INSERT INTO conversation_ai_memory
                        (tenant_id, conversation_id, contact_id, agent_id, status, last_error)
                     VALUES (:tenant_id, :conversation_id, :contact_id, :agent_id, "error", :last_error)
                     ON DUPLICATE KEY UPDATE status = "error", last_error = VALUES(last_error)'
                )->execute([
                    'tenant_id' => $tenantId,
                    'conversation_id' => $conversationId,
                    'contact_id' => (int) ($conversation['contact_id'] ?? 0) ?: null,
                    'agent_id' => (int) ($agent['id'] ?? 0) ?: null,
                    'last_error' => mb_substr($exception->getMessage(), 0, 500),
                ]);
                $this->usage->recordTechnicalEvent($tenantId, $agent, $conversationId, 'summary', 'failed', $this->model->lastUsage(), $exception->getMessage());
            } catch (Throwable) {
            }
            return ['status' => 'error', 'error' => $exception->getMessage()];
        }
    }

    /** @return array<string,mixed> */
    private function decodeJson(string $raw): array
    {
        $raw = trim($raw);
        $raw = preg_replace('/^```(?:json)?\s*/i', '', $raw) ?? $raw;
        $raw = preg_replace('/\s*```$/', '', $raw) ?? $raw;
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }
        $start = strpos($raw, '{');
        $end = strrpos($raw, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $decoded = json_decode(substr($raw, $start, $end - $start + 1), true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        throw new \RuntimeException('A memória progressiva retornou JSON inválido.');
    }

    /** @return array<string,mixed> */
    private function sanitizeFacts(array $facts): array
    {
        $result = [];
        foreach (['name','relationship','last_intent','next_action'] as $key) {
            $value = trim((string) ($facts[$key] ?? ''));
            $result[$key] = $value !== '' ? mb_substr($value, 0, 300) : null;
        }
        foreach (['interests','preferences','important_facts','pending_items','commitments','restrictions'] as $key) {
            $values = is_array($facts[$key] ?? null) ? $facts[$key] : [];
            $clean = [];
            foreach ($values as $value) {
                $value = trim((string) $value);
                if ($value !== '') {
                    $clean[] = mb_substr($value, 0, 300);
                }
                if (count($clean) >= 8) {
                    break;
                }
            }
            $result[$key] = array_values(array_unique($clean));
        }
        return $result;
    }
}
