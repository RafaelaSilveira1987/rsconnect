<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Core\Database;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;
use Throwable;

final class CommercialRequestService
{
    private const DEFAULTS = [
        'ready' => false,
        'enabled' => false,
        'detect_quote_requests' => true,
        'create_task' => true,
        'show_conversation_alert' => true,
        'notify_team' => true,
        'move_stage_mode' => 'follow_crm',
        'target_stage_id' => null,
        'default_assignee_user_id' => null,
        'response_sla_minutes' => 30,
    ];

    public function settings(int $tenantId): array
    {
        if ($tenantId < 1) {
            return self::DEFAULTS;
        }
        try {
            $pdo = Database::connection();
            if (!$this->hasTable($pdo, 'tenant_commercial_request_settings')) {
                return self::DEFAULTS;
            }
            $statement = $pdo->prepare('SELECT * FROM tenant_commercial_request_settings WHERE tenant_id = :tenant_id LIMIT 1');
            $statement->execute(['tenant_id' => $tenantId]);
            $row = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
            return $this->normalizeSettings($row, true);
        } catch (Throwable) {
            return self::DEFAULTS;
        }
    }

    public function saveSettings(int $tenantId, array $data, ?int $userId): void
    {
        if ($tenantId < 1) {
            throw new RuntimeException('Empresa inválida.');
        }
        $pdo = Database::connection();
        if (!$this->hasTable($pdo, 'tenant_commercial_request_settings')) {
            throw new RuntimeException('Execute a migration 091 para ativar solicitações comerciais pendentes.');
        }

        $mode = in_array((string) ($data['move_stage_mode'] ?? ''), ['none', 'follow_crm', 'suggest', 'automatic'], true)
            ? (string) $data['move_stage_mode']
            : 'follow_crm';
        $targetStageId = (int) ($data['target_stage_id'] ?? 0);
        $assigneeId = (int) ($data['default_assignee_user_id'] ?? 0);
        if ($targetStageId > 0 && !$this->stageBelongsToTenant($pdo, $tenantId, $targetStageId)) {
            throw new RuntimeException('A etapa selecionada não pertence à empresa.');
        }
        if ($assigneeId > 0 && !$this->userBelongsToTenant($pdo, $tenantId, $assigneeId)) {
            throw new RuntimeException('O responsável selecionado não pertence à empresa.');
        }

        $statement = $pdo->prepare(
            'INSERT INTO tenant_commercial_request_settings
                (tenant_id, enabled, detect_quote_requests, create_task, show_conversation_alert,
                 notify_team, move_stage_mode, target_stage_id, default_assignee_user_id,
                 response_sla_minutes, updated_by_user_id)
             VALUES
                (:tenant_id, :enabled, :detect_quote_requests, :create_task, :show_conversation_alert,
                 :notify_team, :move_stage_mode, :target_stage_id, :default_assignee_user_id,
                 :response_sla_minutes, :updated_by_user_id)
             ON DUPLICATE KEY UPDATE
                enabled = VALUES(enabled),
                detect_quote_requests = VALUES(detect_quote_requests),
                create_task = VALUES(create_task),
                show_conversation_alert = VALUES(show_conversation_alert),
                notify_team = VALUES(notify_team),
                move_stage_mode = VALUES(move_stage_mode),
                target_stage_id = VALUES(target_stage_id),
                default_assignee_user_id = VALUES(default_assignee_user_id),
                response_sla_minutes = VALUES(response_sla_minutes),
                updated_by_user_id = VALUES(updated_by_user_id),
                updated_at = CURRENT_TIMESTAMP'
        );
        $statement->execute([
            'tenant_id' => $tenantId,
            'enabled' => !empty($data['enabled']) ? 1 : 0,
            'detect_quote_requests' => !empty($data['detect_quote_requests']) ? 1 : 0,
            'create_task' => !empty($data['create_task']) ? 1 : 0,
            'show_conversation_alert' => !empty($data['show_conversation_alert']) ? 1 : 0,
            'notify_team' => !empty($data['notify_team']) ? 1 : 0,
            'move_stage_mode' => $mode,
            'target_stage_id' => $targetStageId > 0 ? $targetStageId : null,
            'default_assignee_user_id' => $assigneeId > 0 ? $assigneeId : null,
            'response_sla_minutes' => max(5, min(1440, (int) ($data['response_sla_minutes'] ?? 30))),
            'updated_by_user_id' => $userId && $userId > 0 ? $userId : null,
        ]);
    }

    public function processIncoming(
        PDO $pdo,
        array $instance,
        int $contactId,
        int $conversationId,
        ?int $leadId,
        string $incomingContent,
        ?int $incomingMessageId = null
    ): array {
        $tenantId = (int) ($instance['tenant_id'] ?? 0);
        if ($tenantId < 1 || $contactId < 1 || $conversationId < 1 || trim($incomingContent) === '') {
            return ['handled' => false, 'reason' => 'invalid_context'];
        }
        if (!$this->hasTable($pdo, 'tenant_commercial_request_settings') || !$this->hasTable($pdo, 'crm_commercial_requests')) {
            return ['handled' => false, 'reason' => 'migration_pending'];
        }

        $settings = $this->settingsFromPdo($pdo, $tenantId);
        if (empty($settings['enabled']) || empty($settings['detect_quote_requests'])) {
            return ['handled' => false, 'reason' => 'disabled'];
        }

        $messages = $this->conversationMessages($pdo, $tenantId, $conversationId, 14);
        $detection = $this->detectQuoteRequest($incomingContent, $messages);
        if ($detection === null) {
            return ['handled' => false, 'reason' => 'not_detected'];
        }

        $contact = $this->contact($pdo, $tenantId, $contactId);
        $dueAt = $this->calculateDueAt($pdo, $instance, $conversationId, $incomingContent, (int) $settings['response_sla_minutes']);
        $existing = $this->activeRequest($pdo, $tenantId, $conversationId);

        if ($existing) {
            $requestId = (int) $existing['id'];
            $taskId = (int) ($existing['task_id'] ?? 0);
            if ($taskId < 1 && !empty($settings['create_task'])) {
                $taskId = $this->createTask($pdo, $settings, $tenantId, $contactId, $leadId, $contact, $dueAt, $detection, $conversationId);
            } else {
                $this->refreshTask($pdo, $taskId, $dueAt, $detection);
            }
            $statement = $pdo->prepare(
                'UPDATE crm_commercial_requests
                 SET lead_id = COALESCE(:lead_id, lead_id),
                     incoming_message_id = :incoming_message_id,
                     task_id = COALESCE(:task_id, task_id),
                     assigned_user_id = COALESCE(:assigned_user_id, assigned_user_id),
                     confidence = GREATEST(confidence, :confidence),
                     reason = :reason,
                     excerpt = :excerpt,
                     detected_by = :detected_by,
                     due_at = COALESCE(due_at, :due_at),
                     last_detected_at = :last_detected_at
                 WHERE id = :id AND tenant_id = :tenant_id'
            );
            $statement->execute([
                'lead_id' => $leadId && $leadId > 0 ? $leadId : null,
                'incoming_message_id' => $incomingMessageId && $incomingMessageId > 0 ? $incomingMessageId : null,
                'task_id' => $taskId > 0 ? $taskId : null,
                'assigned_user_id' => !empty($settings['default_assignee_user_id']) ? (int) $settings['default_assignee_user_id'] : null,
                'confidence' => number_format((float) $detection['confidence'], 3, '.', ''),
                'reason' => $this->preview((string) $detection['reason'], 490),
                'excerpt' => $this->preview($incomingContent, 680),
                'detected_by' => (string) $detection['detected_by'],
                'due_at' => $dueAt,
                'last_detected_at' => Clock::nowUtc(),
                'id' => $requestId,
                'tenant_id' => $tenantId,
            ]);
        } else {
            $taskId = !empty($settings['create_task'])
                ? $this->createTask($pdo, $settings, $tenantId, $contactId, $leadId, $contact, $dueAt, $detection, $conversationId)
                : 0;
            $statement = $pdo->prepare(
                'INSERT INTO crm_commercial_requests
                    (tenant_id, conversation_id, contact_id, lead_id, incoming_message_id, task_id,
                     assigned_user_id, request_type, status, confidence, reason, excerpt, detected_by,
                     due_at, detected_at, last_detected_at)
                 VALUES
                    (:tenant_id, :conversation_id, :contact_id, :lead_id, :incoming_message_id, :task_id,
                     :assigned_user_id, "quote", "pending", :confidence, :reason, :excerpt, :detected_by,
                     :due_at, :detected_at, :last_detected_at)'
            );
            $now = Clock::nowUtc();
            $statement->execute([
                'tenant_id' => $tenantId,
                'conversation_id' => $conversationId,
                'contact_id' => $contactId,
                'lead_id' => $leadId && $leadId > 0 ? $leadId : null,
                'incoming_message_id' => $incomingMessageId && $incomingMessageId > 0 ? $incomingMessageId : null,
                'task_id' => $taskId > 0 ? $taskId : null,
                'assigned_user_id' => !empty($settings['default_assignee_user_id']) ? (int) $settings['default_assignee_user_id'] : null,
                'confidence' => number_format((float) $detection['confidence'], 3, '.', ''),
                'reason' => $this->preview((string) $detection['reason'], 490),
                'excerpt' => $this->preview($incomingContent, 680),
                'detected_by' => (string) $detection['detected_by'],
                'due_at' => $dueAt,
                'detected_at' => $now,
                'last_detected_at' => $now,
            ]);
            $requestId = (int) $pdo->lastInsertId();
        }

        $this->mergeContactTag($pdo, $tenantId, $contactId, 'orçamento-pendente', true);
        if (!empty($settings['notify_team'])) {
            (new NotificationService())->createIfEnabled(
                $tenantId,
                'system',
                'Orçamento pendente',
                'O cliente ' . $this->contactLabel($contact) . ' solicitou um orçamento e precisa de retorno comercial.',
                'warning',
                '/conversations?conversation_id=' . $conversationId,
                'commercial_request',
                'commercial.quote_pending',
                'crm_commercial_request',
                $requestId,
                ['conversation_id' => $conversationId, 'lead_id' => $leadId, 'task_id' => $taskId ?? null],
                60
            );
        }

        $stageAction = ['handled' => false, 'reason' => 'not_requested'];
        if ($leadId && $leadId > 0 && (string) ($settings['move_stage_mode'] ?? 'none') !== 'none') {
            $stageAction = (new CommercialAutomationService())->handleQuoteRequest(
                $pdo,
                $instance,
                $leadId,
                $conversationId,
                $incomingContent,
                $incomingMessageId,
                (string) $settings['move_stage_mode'],
                !empty($settings['target_stage_id']) ? (int) $settings['target_stage_id'] : null,
                (float) $detection['confidence'],
                (string) $detection['reason']
            );
        }

        return [
            'handled' => true,
            'request_id' => $requestId,
            'task_id' => $taskId ?? 0,
            'due_at' => $dueAt,
            'detection' => $detection,
            'stage_action' => $stageAction,
        ];
    }

    public function activeForConversation(int $tenantId, int $conversationId): ?array
    {
        if ($tenantId < 1 || $conversationId < 1) {
            return null;
        }
        try {
            $pdo = Database::connection();
            if (!$this->hasTable($pdo, 'crm_commercial_requests')) {
                return null;
            }
            $statement = $pdo->prepare(
                'SELECT r.*, tk.status AS task_status, tk.title AS task_title,
                        u.name AS assigned_name, l.title AS lead_title
                 FROM crm_commercial_requests r
                 LEFT JOIN crm_tasks tk ON tk.id = r.task_id
                 LEFT JOIN users u ON u.id = r.assigned_user_id
                 LEFT JOIN crm_leads l ON l.id = r.lead_id
                 WHERE r.tenant_id = :tenant_id AND r.conversation_id = :conversation_id AND r.status = "pending"
                 ORDER BY r.id DESC LIMIT 1'
            );
            $statement->execute(['tenant_id' => $tenantId, 'conversation_id' => $conversationId]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable) {
            return null;
        }
    }

    public function pendingCount(int $tenantId): int
    {
        if ($tenantId < 1) {
            return 0;
        }
        try {
            $pdo = Database::connection();
            if (!$this->hasTable($pdo, 'crm_commercial_requests')) {
                return 0;
            }
            $statement = $pdo->prepare('SELECT COUNT(*) FROM crm_commercial_requests WHERE tenant_id = :tenant_id AND status = "pending"');
            $statement->execute(['tenant_id' => $tenantId]);
            return (int) $statement->fetchColumn();
        } catch (Throwable) {
            return 0;
        }
    }

    public function pendingForLeads(int $tenantId, array $leadIds): array
    {
        $leadIds = array_values(array_unique(array_filter(array_map('intval', $leadIds), static fn (int $id): bool => $id > 0)));
        if ($tenantId < 1 || !$leadIds) {
            return [];
        }
        try {
            $pdo = Database::connection();
            if (!$this->hasTable($pdo, 'crm_commercial_requests')) {
                return [];
            }
            $placeholders = implode(',', array_fill(0, count($leadIds), '?'));
            $statement = $pdo->prepare(
                'SELECT r.* FROM crm_commercial_requests r
                 WHERE r.tenant_id = ? AND r.lead_id IN (' . $placeholders . ') AND r.status = "pending"
                 ORDER BY r.id DESC'
            );
            $statement->execute(array_merge([$tenantId], $leadIds));
            $result = [];
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $leadId = (int) $row['lead_id'];
                $result[$leadId] ??= $row;
            }
            return $result;
        } catch (Throwable) {
            return [];
        }
    }

    public function resolve(int $tenantId, int $requestId, int $userId, string $decision = 'resolved', int $conversationId = 0): array
    {
        if (!in_array($decision, ['resolved', 'dismissed'], true)) {
            throw new RuntimeException('Ação inválida.');
        }
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $conversationCondition = $conversationId > 0 ? ' AND conversation_id = :conversation_id' : '';
            $statement = $pdo->prepare(
                'SELECT * FROM crm_commercial_requests
                 WHERE id = :id AND tenant_id = :tenant_id AND status = "pending"' . $conversationCondition . '
                 LIMIT 1 FOR UPDATE'
            );
            $params = ['id' => $requestId, 'tenant_id' => $tenantId];
            if ($conversationId > 0) {
                $params['conversation_id'] = $conversationId;
            }
            $statement->execute($params);
            $request = $statement->fetch(PDO::FETCH_ASSOC);
            if (!$request) {
                throw new RuntimeException('Solicitação não encontrada ou já concluída.');
            }

            $pdo->prepare(
                'UPDATE crm_commercial_requests
                 SET status = :status, resolved_at = :resolved_at, resolved_by_user_id = :resolved_by
                 WHERE id = :id AND tenant_id = :tenant_id'
            )->execute([
                'status' => $decision,
                'resolved_at' => Clock::nowUtc(),
                'resolved_by' => $userId > 0 ? $userId : null,
                'id' => $requestId,
                'tenant_id' => $tenantId,
            ]);

            $taskId = (int) ($request['task_id'] ?? 0);
            if ($taskId > 0) {
                $taskStatus = $decision === 'resolved' ? 'completed' : 'cancelled';
                $pdo->prepare(
                    'UPDATE crm_tasks
                     SET status = :status, completed_at = IF(:completed_status = "completed", :completed_at, NULL)
                     WHERE id = :id AND tenant_id = :tenant_id AND status = "pending"'
                )->execute([
                    'status' => $taskStatus,
                    'completed_status' => $taskStatus,
                    'completed_at' => Clock::nowUtc(),
                    'id' => $taskId,
                    'tenant_id' => $tenantId,
                ]);
            }
            $pdo->commit();
            $this->removePendingTagIfUnused($pdo, $tenantId, (int) $request['contact_id']);
            return ['conversation_id' => (int) $request['conversation_id'], 'lead_id' => (int) ($request['lead_id'] ?? 0), 'status' => $decision];
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function syncFromTaskStatus(int $tenantId, int $taskId, string $taskStatus, ?int $userId): void
    {
        if ($tenantId < 1 || $taskId < 1 || !in_array($taskStatus, ['completed', 'cancelled'], true)) {
            return;
        }
        try {
            $pdo = Database::connection();
            if (!$this->hasTable($pdo, 'crm_commercial_requests')) {
                return;
            }
            $contactsStatement = $pdo->prepare(
                'SELECT DISTINCT contact_id
                 FROM crm_commercial_requests
                 WHERE tenant_id = :tenant_id AND task_id = :task_id AND status = "pending"'
            );
            $contactsStatement->execute(['tenant_id' => $tenantId, 'task_id' => $taskId]);
            $contactIds = array_values(array_filter(array_map('intval', $contactsStatement->fetchAll(PDO::FETCH_COLUMN) ?: [])));

            $requestStatus = $taskStatus === 'completed' ? 'resolved' : 'dismissed';
            $statement = $pdo->prepare(
                'UPDATE crm_commercial_requests
                 SET status = :status, resolved_at = :resolved_at, resolved_by_user_id = :resolved_by
                 WHERE tenant_id = :tenant_id AND task_id = :task_id AND status = "pending"'
            );
            $statement->execute([
                'status' => $requestStatus,
                'resolved_at' => Clock::nowUtc(),
                'resolved_by' => $userId && $userId > 0 ? $userId : null,
                'tenant_id' => $tenantId,
                'task_id' => $taskId,
            ]);
            foreach ($contactIds as $contactId) {
                $this->removePendingTagIfUnused($pdo, $tenantId, $contactId);
            }
        } catch (Throwable) {
        }
    }

    private function settingsFromPdo(PDO $pdo, int $tenantId): array
    {
        $statement = $pdo->prepare('SELECT * FROM tenant_commercial_request_settings WHERE tenant_id = :tenant_id LIMIT 1');
        $statement->execute(['tenant_id' => $tenantId]);
        return $this->normalizeSettings($statement->fetch(PDO::FETCH_ASSOC) ?: [], true);
    }

    private function normalizeSettings(array $row, bool $ready): array
    {
        return [
            'ready' => $ready,
            'enabled' => (int) ($row['enabled'] ?? 0) === 1,
            'detect_quote_requests' => !array_key_exists('detect_quote_requests', $row) || (int) $row['detect_quote_requests'] === 1,
            'create_task' => !array_key_exists('create_task', $row) || (int) $row['create_task'] === 1,
            'show_conversation_alert' => !array_key_exists('show_conversation_alert', $row) || (int) $row['show_conversation_alert'] === 1,
            'notify_team' => !array_key_exists('notify_team', $row) || (int) $row['notify_team'] === 1,
            'move_stage_mode' => in_array((string) ($row['move_stage_mode'] ?? ''), ['none', 'follow_crm', 'suggest', 'automatic'], true)
                ? (string) $row['move_stage_mode']
                : 'follow_crm',
            'target_stage_id' => !empty($row['target_stage_id']) ? (int) $row['target_stage_id'] : null,
            'default_assignee_user_id' => !empty($row['default_assignee_user_id']) ? (int) $row['default_assignee_user_id'] : null,
            'response_sla_minutes' => max(5, min(1440, (int) ($row['response_sla_minutes'] ?? 30))),
        ];
    }

    private function detectQuoteRequest(string $incomingContent, array $messages): ?array
    {
        $incoming = $this->normalize($incomingContent);
        $directPhrases = [
            'quero um orcamento', 'gostaria de um orcamento', 'preciso de um orcamento', 'fazer um orcamento',
            'me envia um orcamento', 'envie o orcamento', 'manda o orcamento', 'quero uma proposta',
            'me envia uma proposta', 'envie uma proposta', 'manda uma proposta', 'quanto custa',
            'qual o valor', 'qual o preco', 'valores dos planos', 'preco do plano', 'quero contratar'
        ];
        foreach ($directPhrases as $phrase) {
            if (str_contains($incoming, $phrase)) {
                return [
                    'confidence' => in_array($phrase, ['quanto custa', 'qual o valor', 'qual o preco'], true) ? 0.91 : 0.97,
                    'reason' => 'O cliente solicitou explicitamente orçamento, proposta ou informação de preço.',
                    'detected_by' => 'direct_rule',
                ];
            }
        }

        $affirmative = preg_match('/^(sim|sim por favor|pode|pode sim|claro|quero|por favor|ok|com certeza|isso)$/u', $incoming) === 1;
        if (!$affirmative) {
            return null;
        }

        $recentOutgoing = [];
        foreach (array_reverse($messages) as $message) {
            if (($message['direction'] ?? '') === 'outgoing') {
                $recentOutgoing[] = $this->normalize((string) ($message['content'] ?? ''));
                if (count($recentOutgoing) >= 4) {
                    break;
                }
            }
        }
        $context = implode(' ', $recentOutgoing);
        $prompts = [
            'encaminhar sua solicitacao de orcamento', 'encaminhar o orcamento', 'preparar um orcamento',
            'fazer um orcamento para voce', 'enviar uma proposta', 'receber uma proposta',
            'solicitacao de orcamento', 'gostaria que eu encaminhasse', 'posso encaminhar',
            'quer que eu encaminhe', 'quer receber um orcamento'
        ];
        foreach ($prompts as $prompt) {
            if (str_contains($context, $prompt)) {
                return [
                    'confidence' => 0.98,
                    'reason' => 'O cliente confirmou, em resposta à pergunta anterior, que deseja receber orçamento ou proposta.',
                    'detected_by' => 'context_rule',
                ];
            }
        }

        return null;
    }

    private function createTask(PDO $pdo, array $settings, int $tenantId, int $contactId, ?int $leadId, array $contact, string $dueAt, array $detection, int $conversationId): int
    {
        if (!$this->hasTable($pdo, 'crm_tasks')) {
            return 0;
        }
        $title = 'Preparar orçamento — ' . $this->contactLabel($contact);
        $description = 'Solicitação identificada automaticamente na conversa do WhatsApp.'
            . "\n\nMotivo: " . (string) $detection['reason']
            . "\nConversa: #" . $conversationId
            . "\nPrazo calculado conforme a configuração comercial.";
        $statement = $pdo->prepare(
            'INSERT INTO crm_tasks
                (tenant_id, contact_id, lead_id, assigned_user_id, created_by_user_id,
                 task_type, title, description, priority, status, due_at)
             VALUES
                (:tenant_id, :contact_id, :lead_id, :assigned_user_id, NULL,
                 "follow_up", :title, :description, "high", "pending", :due_at)'
        );
        $statement->execute([
            'tenant_id' => $tenantId,
            'contact_id' => $contactId,
            'lead_id' => $leadId && $leadId > 0 ? $leadId : null,
            'assigned_user_id' => !empty($settings['default_assignee_user_id']) ? (int) $settings['default_assignee_user_id'] : null,
            'title' => $this->preview($title, 180),
            'description' => $description,
            'due_at' => $dueAt,
        ]);
        return (int) $pdo->lastInsertId();
    }

    private function refreshTask(PDO $pdo, int $taskId, string $dueAt, array $detection): void
    {
        if ($taskId < 1) {
            return;
        }
        $statement = $pdo->prepare(
            'UPDATE crm_tasks
             SET due_at = COALESCE(due_at, :due_at),
                 description = CONCAT(COALESCE(description, ""), :append_text)
             WHERE id = :id AND status = "pending"'
        );
        $statement->execute([
            'due_at' => $dueAt,
            'append_text' => "\n\nNova confirmação em " . date('d/m/Y H:i') . ': ' . (string) $detection['reason'],
            'id' => $taskId,
        ]);
    }

    private function calculateDueAt(PDO $pdo, array $instance, int $conversationId, string $content, int $slaMinutes): string
    {
        $base = new DateTimeImmutable('now', new DateTimeZone(Clock::STORAGE_TIMEZONE));
        try {
            $agent = (new AgentRoutingService())->resolveForAutomation($pdo, $instance, $conversationId, $content, false);
            if (is_array($agent)) {
                $policy = (new AgentOperatingPolicyService())->status($agent);
                if (!empty($policy['enforced']) && empty($policy['inside'])) {
                    $opening = (new AgentOperatingPolicyService())->nextOpeningAt($agent);
                    if ($opening !== null) {
                        $base = $opening->setTimezone(new DateTimeZone(Clock::STORAGE_TIMEZONE));
                    }
                }
            }
        } catch (Throwable) {
        }
        return $base->modify('+' . max(5, min(1440, $slaMinutes)) . ' minutes')->format('Y-m-d H:i:s');
    }

    private function activeRequest(PDO $pdo, int $tenantId, int $conversationId): ?array
    {
        $statement = $pdo->prepare(
            'SELECT * FROM crm_commercial_requests
             WHERE tenant_id = :tenant_id AND conversation_id = :conversation_id AND request_type = "quote" AND status = "pending"
             ORDER BY id DESC LIMIT 1'
        );
        $statement->execute(['tenant_id' => $tenantId, 'conversation_id' => $conversationId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function conversationMessages(PDO $pdo, int $tenantId, int $conversationId, int $limit): array
    {
        $limit = max(4, min(30, $limit));
        $statement = $pdo->prepare(
            'SELECT direction, sender_type, content, sent_at
             FROM conversation_messages
             WHERE tenant_id = :tenant_id AND conversation_id = :conversation_id
               AND content IS NOT NULL AND TRIM(content) <> ""
             ORDER BY sent_at DESC, id DESC LIMIT ' . $limit
        );
        $statement->execute(['tenant_id' => $tenantId, 'conversation_id' => $conversationId]);
        return array_reverse($statement->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    private function contact(PDO $pdo, int $tenantId, int $contactId): array
    {
        $statement = $pdo->prepare('SELECT id, name, phone, company, tags_json FROM contacts WHERE tenant_id = :tenant_id AND id = :id LIMIT 1');
        $statement->execute(['tenant_id' => $tenantId, 'id' => $contactId]);
        return $statement->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    private function contactLabel(array $contact): string
    {
        $name = trim((string) ($contact['name'] ?? ''));
        return $name !== '' ? $name : (trim((string) ($contact['phone'] ?? '')) ?: 'Contato');
    }

    private function mergeContactTag(PDO $pdo, int $tenantId, int $contactId, string $tag, bool $add): void
    {
        try {
            $contact = $this->contact($pdo, $tenantId, $contactId);
            $tags = json_decode((string) ($contact['tags_json'] ?? ''), true);
            $tags = is_array($tags) ? array_values(array_filter(array_map('strval', $tags))) : [];
            if ($add) {
                $tags[] = $tag;
                $tags = array_values(array_unique($tags));
            } else {
                $tags = array_values(array_filter($tags, static fn (string $item): bool => $item !== $tag));
            }
            $pdo->prepare('UPDATE contacts SET tags_json = :tags WHERE tenant_id = :tenant_id AND id = :id')
                ->execute([
                    'tags' => json_encode($tags, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'tenant_id' => $tenantId,
                    'id' => $contactId,
                ]);
        } catch (Throwable) {
        }
    }


    private function removePendingTagIfUnused(PDO $pdo, int $tenantId, int $contactId): void
    {
        if ($contactId < 1) {
            return;
        }
        try {
            $statement = $pdo->prepare(
                'SELECT COUNT(*) FROM crm_commercial_requests
                 WHERE tenant_id = :tenant_id AND contact_id = :contact_id AND status = "pending"'
            );
            $statement->execute(['tenant_id' => $tenantId, 'contact_id' => $contactId]);
            if ((int) $statement->fetchColumn() === 0) {
                $this->mergeContactTag($pdo, $tenantId, $contactId, 'orçamento-pendente', false);
            }
        } catch (Throwable) {
        }
    }

    private function stageBelongsToTenant(PDO $pdo, int $tenantId, int $stageId): bool
    {
        $statement = $pdo->prepare('SELECT COUNT(*) FROM crm_stages WHERE tenant_id = :tenant_id AND id = :id');
        $statement->execute(['tenant_id' => $tenantId, 'id' => $stageId]);
        return (int) $statement->fetchColumn() > 0;
    }

    private function userBelongsToTenant(PDO $pdo, int $tenantId, int $userId): bool
    {
        $statement = $pdo->prepare('SELECT COUNT(*) FROM users WHERE tenant_id = :tenant_id AND id = :id AND status = "active"');
        $statement->execute(['tenant_id' => $tenantId, 'id' => $userId]);
        return (int) $statement->fetchColumn() > 0;
    }

    private function hasTable(PDO $pdo, string $table): bool
    {
        $statement = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table');
        $statement->execute(['table' => $table]);
        return (int) $statement->fetchColumn() > 0;
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        $value = is_string($converted) ? $converted : $value;
        $value = preg_replace('/[^a-z0-9\s]/', ' ', $value) ?? $value;
        return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
    }

    private function preview(string $text, int $limit): string
    {
        $text = trim($text);
        return mb_strlen($text) > $limit ? mb_substr($text, 0, max(1, $limit - 3)) . '...' : $text;
    }
}
