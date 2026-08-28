<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Core\Database;
use PDO;
use RuntimeException;
use Throwable;

final class CommercialAutomationService
{
    private const DEFAULTS = [
        'ready' => false,
        'enabled' => false,
        'mode' => 'suggest',
        'classifier_engine' => 'smart_rules',
        'confidence_threshold' => 0.850,
        'allow_backward_movement' => false,
        'notify_on_action' => true,
        'pipeline_id' => null,
    ];

    public function settings(int $tenantId): array
    {
        $defaults = self::DEFAULTS;
        if ($tenantId < 1) {
            return $defaults;
        }

        try {
            $pdo = Database::connection();
            if (!$this->hasTable($pdo, 'tenant_crm_automation_settings')) {
                return $defaults;
            }
            $defaults['ready'] = true;
            $statement = $pdo->prepare('SELECT * FROM tenant_crm_automation_settings WHERE tenant_id = :tenant_id LIMIT 1');
            $statement->execute(['tenant_id' => $tenantId]);
            $row = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
            if (!$row) {
                return $defaults;
            }

            return [
                'ready' => true,
                'enabled' => (int) ($row['enabled'] ?? 0) === 1,
                'mode' => in_array((string) ($row['mode'] ?? ''), ['suggest', 'automatic'], true) ? (string) $row['mode'] : 'suggest',
                'classifier_engine' => in_array((string) ($row['classifier_engine'] ?? ''), ['smart_rules', 'ai_context'], true) ? (string) $row['classifier_engine'] : 'smart_rules',
                'confidence_threshold' => max(0.60, min(0.99, (float) ($row['confidence_threshold'] ?? 0.850))),
                'allow_backward_movement' => (int) ($row['allow_backward_movement'] ?? 0) === 1,
                'notify_on_action' => (int) ($row['notify_on_action'] ?? 1) === 1,
                'pipeline_id' => !empty($row['pipeline_id']) ? (int) $row['pipeline_id'] : null,
            ];
        } catch (Throwable) {
            return $defaults;
        }
    }

    public function saveSettings(int $tenantId, array $data, ?int $userId): void
    {
        $pdo = Database::connection();
        if (!$this->hasTable($pdo, 'tenant_crm_automation_settings')) {
            throw new RuntimeException('Execute a migration 090 para ativar a automação comercial.');
        }

        $mode = in_array((string) ($data['mode'] ?? ''), ['suggest', 'automatic'], true) ? (string) $data['mode'] : 'suggest';
        $engine = in_array((string) ($data['classifier_engine'] ?? ''), ['smart_rules', 'ai_context'], true)
            ? (string) $data['classifier_engine']
            : 'smart_rules';
        $threshold = max(0.60, min(0.99, ((float) ($data['confidence_threshold'] ?? 85)) / 100));
        $pipelineId = (int) ($data['pipeline_id'] ?? 0);
        if ($pipelineId > 0 && !$this->pipelineBelongsToTenant($pdo, $pipelineId, $tenantId)) {
            throw new RuntimeException('O funil selecionado não pertence a esta empresa.');
        }

        $pdo->prepare(
            'INSERT INTO tenant_crm_automation_settings
                (tenant_id, enabled, mode, classifier_engine, confidence_threshold,
                 allow_backward_movement, notify_on_action, pipeline_id, updated_by_user_id)
             VALUES
                (:tenant_id, :enabled, :mode, :classifier_engine, :confidence_threshold,
                 :allow_backward_movement, :notify_on_action, :pipeline_id, :updated_by_user_id)
             ON DUPLICATE KEY UPDATE
                enabled = VALUES(enabled), mode = VALUES(mode), classifier_engine = VALUES(classifier_engine),
                confidence_threshold = VALUES(confidence_threshold),
                allow_backward_movement = VALUES(allow_backward_movement),
                notify_on_action = VALUES(notify_on_action), pipeline_id = VALUES(pipeline_id),
                updated_by_user_id = VALUES(updated_by_user_id), updated_at = CURRENT_TIMESTAMP'
        )->execute([
            'tenant_id' => $tenantId,
            'enabled' => !empty($data['enabled']) ? 1 : 0,
            'mode' => $mode,
            'classifier_engine' => $engine,
            'confidence_threshold' => number_format($threshold, 3, '.', ''),
            'allow_backward_movement' => !empty($data['allow_backward_movement']) ? 1 : 0,
            'notify_on_action' => !empty($data['notify_on_action']) ? 1 : 0,
            'pipeline_id' => $pipelineId > 0 ? $pipelineId : null,
            'updated_by_user_id' => $userId && $userId > 0 ? $userId : null,
        ]);
    }

    public function processIncoming(
        PDO $pdo,
        array $instance,
        int $leadId,
        int $conversationId,
        string $incomingContent,
        ?int $incomingMessageId = null
    ): array {
        $tenantId = (int) ($instance['tenant_id'] ?? 0);
        if ($tenantId < 1 || $leadId < 1 || $conversationId < 1 || trim($incomingContent) === '') {
            return ['handled' => false, 'reason' => 'invalid_context'];
        }
        if (!$this->hasTable($pdo, 'tenant_crm_automation_settings') || !$this->hasTable($pdo, 'crm_automation_events')) {
            return ['handled' => false, 'reason' => 'migration_pending'];
        }

        $settings = $this->settingsFromPdo($pdo, $tenantId);
        if (empty($settings['enabled'])) {
            return ['handled' => false, 'reason' => 'disabled'];
        }

        $lead = $this->lead($pdo, $tenantId, $leadId);
        if (!$lead) {
            return ['handled' => false, 'reason' => 'lead_missing'];
        }
        if ((int) ($lead['automation_locked'] ?? 0) === 1) {
            return ['handled' => false, 'reason' => 'lead_locked'];
        }
        if (!empty($lead['automation_snoozed_until']) && strtotime((string) $lead['automation_snoozed_until']) > time()) {
            return ['handled' => false, 'reason' => 'lead_snoozed'];
        }
        if (!empty($settings['pipeline_id']) && (int) $settings['pipeline_id'] !== (int) $lead['pipeline_id']) {
            return ['handled' => false, 'reason' => 'pipeline_not_selected'];
        }

        $stages = $this->stages($pdo, $tenantId, (int) $lead['pipeline_id']);
        if (!$stages) {
            return ['handled' => false, 'reason' => 'stages_missing'];
        }

        $messages = $this->conversationMessages($pdo, $tenantId, $conversationId);
        $classification = $this->classifyByRules($incomingContent, $messages);
        $engineUsed = 'smart_rules';

        if (($settings['classifier_engine'] ?? 'smart_rules') === 'ai_context') {
            $aiClassification = $this->classifyWithAi($pdo, $tenantId, $conversationId, $lead, $stages, $messages);
            if ($aiClassification !== null) {
                $classification = $aiClassification;
                $engineUsed = 'ai_context';
            }
        }

        $target = $this->resolveTargetStage($stages, (string) ($classification['stage_key'] ?? ''));
        if (!$target) {
            return ['handled' => false, 'reason' => 'no_target'];
        }

        $currentStageId = (int) $lead['stage_id'];
        if ((int) $target['id'] === $currentStageId) {
            return ['handled' => false, 'reason' => 'already_in_stage'];
        }

        $current = $this->stageById($stages, $currentStageId);
        $isBackward = $current && (int) $target['position'] < (int) $current['position'];
        $isFinal = in_array((string) ($target['stage_type'] ?? ''), ['won', 'lost'], true);
        if ($isBackward && !$isFinal && empty($settings['allow_backward_movement'])) {
            $this->insertEvent($pdo, [
                'tenant_id' => $tenantId,
                'lead_id' => $leadId,
                'conversation_id' => $conversationId,
                'incoming_message_id' => $incomingMessageId,
                'previous_stage_id' => $currentStageId,
                'target_stage_id' => (int) $target['id'],
                'action' => 'blocked',
                'confidence' => (float) ($classification['confidence'] ?? 0),
                'reason' => 'Movimentação para etapa anterior bloqueada pela configuração da empresa.',
                'excerpt' => $this->preview($incomingContent, 680),
                'classifier_engine' => $engineUsed,
            ]);
            return ['handled' => true, 'reason' => 'backward_blocked'];
        }

        $confidence = max(0.0, min(1.0, (float) ($classification['confidence'] ?? 0)));
        $minimumForSuggestion = min((float) $settings['confidence_threshold'], 0.68);
        if ($confidence < $minimumForSuggestion) {
            return ['handled' => false, 'reason' => 'low_confidence'];
        }

        $reason = trim((string) ($classification['reason'] ?? 'A conversa indicou evolução no processo comercial.'));
        $eventData = [
            'tenant_id' => $tenantId,
            'lead_id' => $leadId,
            'conversation_id' => $conversationId,
            'incoming_message_id' => $incomingMessageId,
            'previous_stage_id' => $currentStageId,
            'target_stage_id' => (int) $target['id'],
            'confidence' => $confidence,
            'reason' => $this->preview($reason, 490),
            'excerpt' => $this->preview($incomingContent, 680),
            'classifier_engine' => $engineUsed,
        ];

        $automatic = ($settings['mode'] ?? 'suggest') === 'automatic' && $confidence >= (float) $settings['confidence_threshold'];
        if (!$automatic) {
            if ($this->hasPendingSuggestion($pdo, $tenantId, $leadId, (int) $target['id'])) {
                return ['handled' => false, 'reason' => 'duplicate_suggestion'];
            }
            $eventData['action'] = 'suggested';
            $eventId = $this->insertEvent($pdo, $eventData);
            $this->notify($settings, $tenantId, $leadId, 'Sugestão no funil comercial',
                'A conversa sugere mover o negócio para “' . (string) $target['name'] . '” (' . (int) round($confidence * 100) . '% de confiança).',
                'crm.automation_suggested', $eventId);
            return ['handled' => true, 'action' => 'suggested', 'event_id' => $eventId, 'stage_id' => (int) $target['id']];
        }

        $this->moveLead($pdo, $tenantId, $leadId, $target);
        $eventData['action'] = 'moved';
        $eventId = $this->insertEvent($pdo, $eventData);
        $this->addSystemNote($pdo, $tenantId, (int) $lead['contact_id'], $leadId,
            'Automação comercial: negócio movido de “' . (string) ($current['name'] ?? 'etapa anterior') . '” para “' . (string) $target['name'] . '”. ' . $reason . ' Confiança: ' . (int) round($confidence * 100) . '%.');
        $this->notify($settings, $tenantId, $leadId, 'Negócio movido automaticamente',
            'O negócio foi movido para “' . (string) $target['name'] . '” com base na conversa do WhatsApp.',
            'crm.automation_moved', $eventId);

        return ['handled' => true, 'action' => 'moved', 'event_id' => $eventId, 'stage_id' => (int) $target['id']];
    }

    public function handleQuoteRequest(
        PDO $pdo,
        array $instance,
        int $leadId,
        int $conversationId,
        string $excerpt,
        ?int $incomingMessageId,
        string $requestedMode,
        ?int $targetStageId,
        float $confidence,
        string $reason
    ): array {
        $tenantId = (int) ($instance['tenant_id'] ?? 0);
        if ($tenantId < 1 || $leadId < 1 || $conversationId < 1 || $requestedMode === 'none') {
            return ['handled' => false, 'reason' => 'disabled'];
        }
        if (!$this->hasTable($pdo, 'tenant_crm_automation_settings') || !$this->hasTable($pdo, 'crm_automation_events')) {
            return ['handled' => false, 'reason' => 'migration_pending'];
        }

        $settings = $this->settingsFromPdo($pdo, $tenantId);
        if (empty($settings['enabled'])) {
            return ['handled' => false, 'reason' => 'crm_automation_disabled'];
        }
        $lead = $this->lead($pdo, $tenantId, $leadId);
        if (!$lead || (int) ($lead['automation_locked'] ?? 0) === 1) {
            return ['handled' => false, 'reason' => $lead ? 'lead_locked' : 'lead_missing'];
        }
        if (!empty($lead['automation_snoozed_until']) && strtotime((string) $lead['automation_snoozed_until']) > time()) {
            return ['handled' => false, 'reason' => 'lead_snoozed'];
        }
        if (!empty($settings['pipeline_id']) && (int) $settings['pipeline_id'] !== (int) $lead['pipeline_id']) {
            return ['handled' => false, 'reason' => 'pipeline_not_selected'];
        }

        $stages = $this->stages($pdo, $tenantId, (int) $lead['pipeline_id']);
        $target = null;
        if ($targetStageId && $targetStageId > 0) {
            $candidate = $this->stageById($stages, $targetStageId);
            if ($candidate) {
                $target = $candidate;
            }
        }
        $target ??= $this->resolveTargetStage($stages, 'proposal');
        if (!$target) {
            return ['handled' => false, 'reason' => 'proposal_stage_missing'];
        }

        $currentStageId = (int) $lead['stage_id'];
        if ((int) $target['id'] === $currentStageId) {
            return ['handled' => false, 'reason' => 'already_in_stage'];
        }
        $current = $this->stageById($stages, $currentStageId);
        if ($current && (int) $target['position'] < (int) $current['position'] && empty($settings['allow_backward_movement'])) {
            return ['handled' => false, 'reason' => 'backward_blocked'];
        }

        $mode = $requestedMode === 'follow_crm' ? (string) ($settings['mode'] ?? 'suggest') : $requestedMode;
        $mode = in_array($mode, ['suggest', 'automatic'], true) ? $mode : 'suggest';
        $confidence = max(0.0, min(1.0, $confidence));
        $automatic = $mode === 'automatic' && $confidence >= (float) ($settings['confidence_threshold'] ?? .85);
        $eventData = [
            'tenant_id' => $tenantId,
            'lead_id' => $leadId,
            'conversation_id' => $conversationId,
            'incoming_message_id' => $incomingMessageId,
            'previous_stage_id' => $currentStageId,
            'target_stage_id' => (int) $target['id'],
            'confidence' => $confidence,
            'reason' => $this->preview($reason, 490),
            'excerpt' => $this->preview($excerpt, 680),
            'classifier_engine' => 'quote_request',
            'metadata' => ['request_type' => 'quote'],
        ];

        if (!$automatic) {
            if ($this->hasPendingSuggestion($pdo, $tenantId, $leadId, (int) $target['id'])) {
                return ['handled' => false, 'reason' => 'duplicate_suggestion'];
            }
            $eventData['action'] = 'suggested';
            $eventId = $this->insertEvent($pdo, $eventData);
            $this->notify($settings, $tenantId, $leadId, 'Orçamento pendente no Comercial',
                'O cliente pediu um orçamento. Foi sugerido mover o negócio para “' . (string) $target['name'] . '”.',
                'crm.quote_stage_suggested', $eventId);
            return ['handled' => true, 'action' => 'suggested', 'event_id' => $eventId, 'stage_id' => (int) $target['id']];
        }

        $this->moveLead($pdo, $tenantId, $leadId, $target);
        $eventData['action'] = 'moved';
        $eventId = $this->insertEvent($pdo, $eventData);
        $this->addSystemNote($pdo, $tenantId, (int) $lead['contact_id'], $leadId,
            'Solicitação de orçamento detectada: negócio movido para “' . (string) $target['name'] . '”. ' . $reason);
        $this->notify($settings, $tenantId, $leadId, 'Orçamento pendente no Comercial',
            'O cliente pediu um orçamento e o negócio foi movido para “' . (string) $target['name'] . '”.',
            'crm.quote_stage_moved', $eventId);
        return ['handled' => true, 'action' => 'moved', 'event_id' => $eventId, 'stage_id' => (int) $target['id']];
    }

    public function pendingSuggestions(int $tenantId, array $leadIds): array
    {
        $leadIds = array_values(array_unique(array_filter(array_map('intval', $leadIds), static fn (int $id): bool => $id > 0)));
        if ($tenantId < 1 || !$leadIds) {
            return [];
        }
        try {
            $pdo = Database::connection();
            if (!$this->hasTable($pdo, 'crm_automation_events')) {
                return [];
            }
            $placeholders = implode(',', array_fill(0, count($leadIds), '?'));
            $statement = $pdo->prepare(
                'SELECT e.*, s.name AS target_stage_name
                 FROM crm_automation_events e
                 LEFT JOIN crm_stages s ON s.id = e.target_stage_id
                 WHERE e.tenant_id = ? AND e.lead_id IN (' . $placeholders . ')
                   AND e.action = "suggested" AND e.reviewed_at IS NULL
                 ORDER BY e.id DESC'
            );
            $statement->execute(array_merge([$tenantId], $leadIds));
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $result = [];
            foreach ($rows as $row) {
                $leadId = (int) $row['lead_id'];
                if (!isset($result[$leadId])) {
                    $result[$leadId] = $row;
                }
            }
            return $result;
        } catch (Throwable) {
            return [];
        }
    }

    public function historyForLead(int $tenantId, int $leadId, int $limit = 20): array
    {
        if ($tenantId < 1 || $leadId < 1) {
            return [];
        }
        try {
            $pdo = Database::connection();
            if (!$this->hasTable($pdo, 'crm_automation_events')) {
                return [];
            }
            $statement = $pdo->prepare(
                'SELECT e.*, previous_stage.name AS previous_stage_name, target_stage.name AS target_stage_name,
                        reviewer.name AS reviewer_name
                 FROM crm_automation_events e
                 LEFT JOIN crm_stages previous_stage ON previous_stage.id = e.previous_stage_id
                 LEFT JOIN crm_stages target_stage ON target_stage.id = e.target_stage_id
                 LEFT JOIN users reviewer ON reviewer.id = e.reviewed_by_user_id
                 WHERE e.tenant_id = :tenant_id AND e.lead_id = :lead_id
                 ORDER BY e.id DESC LIMIT :limit'
            );
            $statement->bindValue('tenant_id', $tenantId, PDO::PARAM_INT);
            $statement->bindValue('lead_id', $leadId, PDO::PARAM_INT);
            $statement->bindValue('limit', max(1, min(100, $limit)), PDO::PARAM_INT);
            $statement->execute();
            return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return [];
        }
    }

    public function leadState(int $tenantId, int $leadId): array
    {
        $default = ['locked' => false, 'snoozed_until' => null];
        if ($tenantId < 1 || $leadId < 1) {
            return $default;
        }
        try {
            $pdo = Database::connection();
            if (!$this->hasColumn($pdo, 'crm_leads', 'automation_locked')) {
                return $default;
            }
            $statement = $pdo->prepare('SELECT automation_locked, automation_snoozed_until FROM crm_leads WHERE tenant_id = :tenant_id AND id = :id LIMIT 1');
            $statement->execute(['tenant_id' => $tenantId, 'id' => $leadId]);
            $row = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
            return [
                'locked' => (int) ($row['automation_locked'] ?? 0) === 1,
                'snoozed_until' => $row['automation_snoozed_until'] ?? null,
            ];
        } catch (Throwable) {
            return $default;
        }
    }

    public function setLeadLock(int $tenantId, int $leadId, bool $locked): void
    {
        $pdo = Database::connection();
        if (!$this->hasColumn($pdo, 'crm_leads', 'automation_locked')) {
            throw new RuntimeException('Execute a migration 090 para controlar a automação por negócio.');
        }
        $statement = $pdo->prepare(
            'UPDATE crm_leads
             SET automation_locked = :locked, automation_snoozed_until = IF(:locked_for_until = 1, NULL, automation_snoozed_until)
             WHERE tenant_id = :tenant_id AND id = :id'
        );
        $statement->execute([
            'locked' => $locked ? 1 : 0,
            'locked_for_until' => $locked ? 1 : 0,
            'tenant_id' => $tenantId,
            'id' => $leadId,
        ]);
        if ($statement->rowCount() < 1 && !$this->lead($pdo, $tenantId, $leadId)) {
            throw new RuntimeException('Negócio não encontrado.');
        }
    }

    public function reviewSuggestion(int $tenantId, int $eventId, string $decision, int $userId): array
    {
        $pdo = Database::connection();
        if (!$this->hasTable($pdo, 'crm_automation_events')) {
            throw new RuntimeException('Execute a migration 090 para revisar sugestões.');
        }
        $statement = $pdo->prepare(
            'SELECT e.*, l.pipeline_id, l.stage_id AS current_stage_id, l.contact_id,
                    target.name AS target_stage_name, target.stage_type, target.position,
                    current.name AS current_stage_name
             FROM crm_automation_events e
             INNER JOIN crm_leads l ON l.id = e.lead_id AND l.tenant_id = e.tenant_id
             LEFT JOIN crm_stages target ON target.id = e.target_stage_id
             LEFT JOIN crm_stages current ON current.id = l.stage_id
             WHERE e.id = :id AND e.tenant_id = :tenant_id AND e.action = "suggested" AND e.reviewed_at IS NULL
             LIMIT 1 FOR UPDATE'
        );

        $pdo->beginTransaction();
        try {
            $statement->execute(['id' => $eventId, 'tenant_id' => $tenantId]);
            $event = $statement->fetch(PDO::FETCH_ASSOC);
            if (!$event) {
                throw new RuntimeException('Sugestão não encontrada ou já revisada.');
            }

            if ($decision === 'approve') {
                $target = [
                    'id' => (int) $event['target_stage_id'],
                    'name' => (string) ($event['target_stage_name'] ?? 'nova etapa'),
                    'stage_type' => (string) ($event['stage_type'] ?? 'open'),
                ];
                $this->moveLead($pdo, $tenantId, (int) $event['lead_id'], $target);
                $action = 'approved';
                $this->addSystemNote($pdo, $tenantId, (int) $event['contact_id'], (int) $event['lead_id'],
                    'Sugestão da automação aprovada: negócio movido de “' . (string) ($event['current_stage_name'] ?? 'etapa anterior') . '” para “' . (string) $target['name'] . '”.');
            } else {
                $action = 'rejected';
            }

            $pdo->prepare(
                'UPDATE crm_automation_events
                 SET action = :action, reviewed_by_user_id = :user_id, reviewed_at = :reviewed_at
                 WHERE id = :id AND tenant_id = :tenant_id'
            )->execute([
                'action' => $action,
                'user_id' => $userId > 0 ? $userId : null,
                'reviewed_at' => Clock::nowUtc(),
                'id' => $eventId,
                'tenant_id' => $tenantId,
            ]);
            $pdo->commit();
            return ['lead_id' => (int) $event['lead_id'], 'pipeline_id' => (int) $event['pipeline_id'], 'action' => $action];
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function snoozeAfterManualMove(int $tenantId, int $leadId, int $hours = 6): void
    {
        try {
            $pdo = Database::connection();
            if (!$this->hasColumn($pdo, 'crm_leads', 'automation_snoozed_until')) {
                return;
            }
            $until = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('+' . max(1, min(48, $hours)) . ' hours')->format('Y-m-d H:i:s');
            $pdo->prepare(
                'UPDATE crm_leads SET automation_snoozed_until = :until WHERE tenant_id = :tenant_id AND id = :id'
            )->execute(['until' => $until, 'tenant_id' => $tenantId, 'id' => $leadId]);
        } catch (Throwable) {
            // A movimentação manual nunca deve falhar por causa da automação opcional.
        }
    }

    private function settingsFromPdo(PDO $pdo, int $tenantId): array
    {
        $statement = $pdo->prepare('SELECT * FROM tenant_crm_automation_settings WHERE tenant_id = :tenant_id LIMIT 1');
        $statement->execute(['tenant_id' => $tenantId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'enabled' => (int) ($row['enabled'] ?? 0) === 1,
            'mode' => (string) ($row['mode'] ?? 'suggest'),
            'classifier_engine' => (string) ($row['classifier_engine'] ?? 'smart_rules'),
            'confidence_threshold' => max(0.60, min(0.99, (float) ($row['confidence_threshold'] ?? 0.850))),
            'allow_backward_movement' => (int) ($row['allow_backward_movement'] ?? 0) === 1,
            'notify_on_action' => (int) ($row['notify_on_action'] ?? 1) === 1,
            'pipeline_id' => !empty($row['pipeline_id']) ? (int) $row['pipeline_id'] : null,
        ];
    }

    private function classifyByRules(string $incomingContent, array $messages): array
    {
        $incoming = $this->normalize($incomingContent);
        $transcript = $this->normalize(implode("\n", array_map(static fn (array $row): string => (string) ($row['content'] ?? ''), $messages)));

        $rules = [
            'lost' => [0.98, ['nao tenho interesse', 'nao quero mais', 'desisti', 'nao vou contratar', 'ja contratei outro', 'pode cancelar', 'sem interesse']],
            'won' => [0.97, ['pode fechar', 'vamos fechar', 'quero contratar', 'aceito a proposta', 'proposta aceita', 'vamos comecar', 'pode emitir', 'fechado entao', 'quero o plano']],
            'negotiation' => [0.91, ['consegue desconto', 'tem desconto', 'melhorar o valor', 'condicao de pagamento', 'forma de pagamento', 'parcelar', 'contraproposta', 'negociar', 'prazo de contrato']],
            'proposal' => [0.87, ['envia a proposta', 'mande a proposta', 'quero uma proposta', 'fazer um orcamento', 'envie o orcamento', 'quanto custa', 'qual o valor', 'valores dos planos', 'preco do plano']],
        ];
        foreach ($rules as $stage => [$confidence, $phrases]) {
            foreach ($phrases as $phrase) {
                if (str_contains($incoming, $phrase)) {
                    return ['stage_key' => $stage, 'confidence' => $confidence, 'reason' => $this->reasonFor($stage, $phrase)];
                }
            }
        }

        if ((str_contains($transcript, 'enviei a proposta') || str_contains($transcript, 'segue a proposta') || str_contains($transcript, 'proposta comercial'))
            && preg_match('/\b(recebi|vou analisar|estou analisando|achei interessante|gostei)\b/u', $incoming)) {
            return ['stage_key' => 'proposal', 'confidence' => 0.92, 'reason' => 'A proposta já aparece na conversa e o lead confirmou o recebimento ou a análise.'];
        }

        $affirmative = preg_match('/^(sim|sim por favor|pode|pode sim|claro|quero|por favor|ok|com certeza|isso)$/u', $incoming) === 1;
        $quotePromptInContext = $this->containsAny($transcript, [
            'encaminhar sua solicitacao de orcamento', 'encaminhar o orcamento', 'preparar um orcamento',
            'enviar uma proposta', 'receber uma proposta', 'solicitacao de orcamento', 'gostaria de um orcamento'
        ]);
        if ($affirmative && $quotePromptInContext) {
            return ['stage_key' => 'proposal', 'confidence' => 0.97, 'reason' => 'O lead confirmou, no contexto da conversa, que deseja receber orçamento ou proposta.'];
        }

        $qualificationSignals = [
            preg_match('/\b\d+\s*(atendente|usuario|pessoa|agente|numero|canal|whatsapp)s?\b/u', $incoming) === 1,
            $this->containsAny($incoming, ['minha empresa', 'meu negocio', 'minha loja', 'minha clinica', 'minha equipe', 'atendimento']),
            $this->containsAny($incoming, ['preciso', 'quero automatizar', 'centralizar', 'organizar', 'integrar', 'diminuir tempo', 'melhorar atendimento']),
            $this->containsAny($incoming, ['whatsapp', 'instagram', 'canal', 'atendente', 'equipe', 'clientes']),
        ];
        $signalCount = count(array_filter($qualificationSignals));
        if ($signalCount >= 3) {
            return ['stage_key' => 'qualified', 'confidence' => 0.90, 'reason' => 'O lead informou contexto da empresa, necessidade e estrutura suficientes para qualificação.'];
        }
        if ($signalCount >= 2) {
            return ['stage_key' => 'qualified', 'confidence' => 0.82, 'reason' => 'A conversa já apresenta necessidade e contexto comercial relevantes.'];
        }
        if ($this->containsAny($incoming, ['tenho interesse', 'quero saber mais', 'como funciona', 'me explica', 'conhecer a plataforma'])) {
            return ['stage_key' => 'qualified', 'confidence' => 0.72, 'reason' => 'O lead demonstrou interesse, mas ainda precisa fornecer mais detalhes para uma qualificação completa.'];
        }

        return ['stage_key' => 'new', 'confidence' => 0.40, 'reason' => 'Ainda não há sinais suficientes para avançar a etapa comercial.'];
    }

    private function classifyWithAi(PDO $pdo, int $tenantId, int $conversationId, array $lead, array $stages, array $messages): ?array
    {
        try {
            $agent = $this->agentForConversation($pdo, $tenantId, $conversationId);
            if (!$agent) {
                return null;
            }
            $stageList = array_map(static fn (array $stage): string => sprintf('%s (%s)', (string) $stage['name'], (string) $stage['stage_type']), $stages);
            $transcript = implode("\n", array_map(static function (array $row): string {
                $speaker = ($row['direction'] ?? '') === 'incoming' ? 'Lead' : (($row['sender_type'] ?? '') === 'ai' ? 'Assistente' : 'Equipe');
                return $speaker . ': ' . trim((string) ($row['content'] ?? ''));
            }, $messages));
            $instructions = 'Classifique a etapa comercial usando somente o contexto fornecido. Responda JSON puro com stage_key, confidence entre 0 e 1 e reason curta. stage_key deve ser: new, qualified, proposal, negotiation, won ou lost. Não considere uma etapa concluída sem evidência explícita.';
            $input = "Etapa atual: " . (string) ($lead['stage_name'] ?? '')
                . "\nEtapas disponíveis: " . implode(', ', $stageList)
                . "\nConversa:\n" . $this->preview($transcript, 7000);
            $raw = (new AiModelService())->generateCompactTask($agent, $instructions, $input, 220);
            $raw = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', trim($raw)) ?? trim($raw);
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                return null;
            }
            $stageKey = (string) ($decoded['stage_key'] ?? '');
            if (!in_array($stageKey, ['new', 'qualified', 'proposal', 'negotiation', 'won', 'lost'], true)) {
                return null;
            }
            return [
                'stage_key' => $stageKey,
                'confidence' => max(0.0, min(1.0, (float) ($decoded['confidence'] ?? 0))),
                'reason' => $this->preview(trim((string) ($decoded['reason'] ?? 'Classificação contextual da conversa.')), 490),
            ];
        } catch (Throwable) {
            return null;
        }
    }

    private function agentForConversation(PDO $pdo, int $tenantId, int $conversationId): ?array
    {
        $hasAiAgent = $this->hasColumn($pdo, 'conversations', 'ai_agent_id');
        $sql = $hasAiAgent
            ? 'SELECT c.ai_agent_id, c.evolution_instance_id FROM conversations c WHERE c.id = :id AND c.tenant_id = :tenant_id LIMIT 1'
            : 'SELECT NULL AS ai_agent_id, c.evolution_instance_id FROM conversations c WHERE c.id = :id AND c.tenant_id = :tenant_id LIMIT 1';
        $statement = $pdo->prepare($sql);
        $statement->execute(['id' => $conversationId, 'tenant_id' => $tenantId]);
        $conversation = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
        if (!$conversation) {
            return null;
        }

        if (!empty($conversation['ai_agent_id'])) {
            $agentStatement = $pdo->prepare('SELECT * FROM ai_agents WHERE id = :id AND tenant_id = :tenant_id AND status = "active" LIMIT 1');
            $agentStatement->execute(['id' => (int) $conversation['ai_agent_id'], 'tenant_id' => $tenantId]);
            $agent = $agentStatement->fetch(PDO::FETCH_ASSOC);
            if ($agent) {
                return $agent;
            }
        }

        $agentStatement = $pdo->prepare(
            'SELECT * FROM ai_agents
             WHERE tenant_id = :tenant_id AND status = "active"
               AND (instance_id = :instance_id OR instance_id IS NULL)
             ORDER BY (instance_id = :instance_id_order) DESC, is_default DESC, id ASC LIMIT 1'
        );
        $instanceId = (int) ($conversation['evolution_instance_id'] ?? 0);
        $agentStatement->execute(['tenant_id' => $tenantId, 'instance_id' => $instanceId, 'instance_id_order' => $instanceId]);
        $agent = $agentStatement->fetch(PDO::FETCH_ASSOC);
        return $agent ?: null;
    }

    private function resolveTargetStage(array $stages, string $key): ?array
    {
        $aliases = [
            'new' => ['novo', 'entrada', 'lead'],
            'qualified' => ['qualificacao', 'qualificado', 'diagnostico'],
            'proposal' => ['proposta', 'orcamento'],
            'negotiation' => ['negociacao', 'negociando'],
            'won' => ['ganho', 'fechado', 'vendido', 'cliente'],
            'lost' => ['perdido', 'perda', 'descartado'],
        ];
        foreach ($stages as $stage) {
            $name = $this->normalize((string) ($stage['name'] ?? ''));
            if (in_array($name, $aliases[$key] ?? [], true)) {
                return $stage;
            }
            foreach ($aliases[$key] ?? [] as $alias) {
                if (str_contains($name, $alias)) {
                    return $stage;
                }
            }
        }
        $byType = array_values(array_filter($stages, static fn (array $stage): bool => (string) ($stage['stage_type'] ?? '') === ($key === 'won' ? 'won' : ($key === 'lost' ? 'lost' : ''))));
        if ($byType) {
            return $byType[0];
        }
        if ($key === 'new') {
            return $stages[0] ?? null;
        }
        return null;
    }

    private function conversationMessages(PDO $pdo, int $tenantId, int $conversationId): array
    {
        $statement = $pdo->prepare(
            'SELECT direction, sender_type, content, sent_at
             FROM conversation_messages
             WHERE tenant_id = :tenant_id AND conversation_id = :conversation_id
               AND content IS NOT NULL AND TRIM(content) <> ""
             ORDER BY sent_at DESC, id DESC LIMIT 18'
        );
        $statement->execute(['tenant_id' => $tenantId, 'conversation_id' => $conversationId]);
        return array_reverse($statement->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    private function lead(PDO $pdo, int $tenantId, int $leadId): ?array
    {
        $locked = $this->hasColumn($pdo, 'crm_leads', 'automation_locked') ? 'l.automation_locked' : '0 AS automation_locked';
        $snoozed = $this->hasColumn($pdo, 'crm_leads', 'automation_snoozed_until') ? 'l.automation_snoozed_until' : 'NULL AS automation_snoozed_until';
        $statement = $pdo->prepare(
            'SELECT l.*, s.name AS stage_name, s.position AS stage_position, s.stage_type, ' . $locked . ', ' . $snoozed . '
             FROM crm_leads l INNER JOIN crm_stages s ON s.id = l.stage_id
             WHERE l.tenant_id = :tenant_id AND l.id = :id LIMIT 1'
        );
        $statement->execute(['tenant_id' => $tenantId, 'id' => $leadId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function stages(PDO $pdo, int $tenantId, int $pipelineId): array
    {
        $statement = $pdo->prepare('SELECT * FROM crm_stages WHERE tenant_id = :tenant_id AND pipeline_id = :pipeline_id ORDER BY position');
        $statement->execute(['tenant_id' => $tenantId, 'pipeline_id' => $pipelineId]);
        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function stageById(array $stages, int $stageId): ?array
    {
        foreach ($stages as $stage) {
            if ((int) ($stage['id'] ?? 0) === $stageId) {
                return $stage;
            }
        }
        return null;
    }

    private function moveLead(PDO $pdo, int $tenantId, int $leadId, array $target): void
    {
        $status = in_array((string) ($target['stage_type'] ?? ''), ['won', 'lost'], true) ? (string) $target['stage_type'] : 'open';
        $pdo->prepare(
            'UPDATE crm_leads
             SET stage_id = :stage_id, status = :status, closed_at = :closed_at,
                 lost_reason = IF(:reason_status = "lost", lost_reason, NULL), automation_snoozed_until = NULL
             WHERE tenant_id = :tenant_id AND id = :id'
        )->execute([
            'stage_id' => (int) $target['id'],
            'status' => $status,
            'closed_at' => $status === 'open' ? null : Clock::nowUtc(),
            'reason_status' => $status,
            'tenant_id' => $tenantId,
            'id' => $leadId,
        ]);
    }

    private function insertEvent(PDO $pdo, array $data): int
    {
        $statement = $pdo->prepare(
            'INSERT INTO crm_automation_events
                (tenant_id, lead_id, conversation_id, incoming_message_id, previous_stage_id,
                 target_stage_id, action, confidence, reason, excerpt, classifier_engine, metadata_json)
             VALUES
                (:tenant_id, :lead_id, :conversation_id, :incoming_message_id, :previous_stage_id,
                 :target_stage_id, :action, :confidence, :reason, :excerpt, :classifier_engine, :metadata_json)'
        );
        $statement->execute([
            'tenant_id' => (int) $data['tenant_id'],
            'lead_id' => (int) $data['lead_id'],
            'conversation_id' => !empty($data['conversation_id']) ? (int) $data['conversation_id'] : null,
            'incoming_message_id' => !empty($data['incoming_message_id']) ? (int) $data['incoming_message_id'] : null,
            'previous_stage_id' => !empty($data['previous_stage_id']) ? (int) $data['previous_stage_id'] : null,
            'target_stage_id' => !empty($data['target_stage_id']) ? (int) $data['target_stage_id'] : null,
            'action' => (string) $data['action'],
            'confidence' => isset($data['confidence']) ? number_format((float) $data['confidence'], 3, '.', '') : null,
            'reason' => (string) $data['reason'],
            'excerpt' => $data['excerpt'] ?? null,
            'classifier_engine' => (string) ($data['classifier_engine'] ?? 'smart_rules'),
            'metadata_json' => isset($data['metadata']) ? json_encode($data['metadata'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        ]);
        return (int) $pdo->lastInsertId();
    }

    private function hasPendingSuggestion(PDO $pdo, int $tenantId, int $leadId, int $targetStageId): bool
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM crm_automation_events
             WHERE tenant_id = :tenant_id AND lead_id = :lead_id AND target_stage_id = :target_stage_id
               AND action = "suggested" AND reviewed_at IS NULL'
        );
        $statement->execute(['tenant_id' => $tenantId, 'lead_id' => $leadId, 'target_stage_id' => $targetStageId]);
        return (int) $statement->fetchColumn() > 0;
    }

    private function addSystemNote(PDO $pdo, int $tenantId, int $contactId, int $leadId, string $note): void
    {
        if (!$this->hasTable($pdo, 'crm_notes')) {
            return;
        }
        $pdo->prepare('INSERT INTO crm_notes (tenant_id, contact_id, lead_id, user_id, note) VALUES (:tenant_id, :contact_id, :lead_id, NULL, :note)')
            ->execute(['tenant_id' => $tenantId, 'contact_id' => $contactId, 'lead_id' => $leadId, 'note' => $note]);
    }

    private function notify(array $settings, int $tenantId, int $leadId, string $title, string $message, string $sourceEvent, int $eventId): void
    {
        if (empty($settings['notify_on_action'])) {
            return;
        }
        (new NotificationService())->createIfEnabled(
            $tenantId,
            'system',
            $title,
            $message,
            'info',
            '/crm?lead_id=' . $leadId,
            'crm_automation',
            $sourceEvent,
            'crm_automation_event',
            $eventId,
            ['lead_id' => $leadId],
            60
        );
    }

    private function reasonFor(string $stage, string $phrase): string
    {
        return match ($stage) {
            'won' => 'O lead confirmou intenção explícita de contratar ou fechar o negócio.',
            'lost' => 'O lead informou explicitamente que não deseja continuar a negociação.',
            'negotiation' => 'O lead iniciou negociação de preço, prazo ou condição comercial.',
            'proposal' => 'O lead solicitou preço, orçamento ou proposta comercial.',
            default => 'A conversa contém o sinal comercial “' . $phrase . '”.',
        };
    }

    private function containsAny(string $text, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($text, $needle)) {
                return true;
            }
        }
        return false;
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

    private function pipelineBelongsToTenant(PDO $pdo, int $pipelineId, int $tenantId): bool
    {
        $statement = $pdo->prepare('SELECT COUNT(*) FROM crm_pipelines WHERE id = :id AND tenant_id = :tenant_id');
        $statement->execute(['id' => $pipelineId, 'tenant_id' => $tenantId]);
        return (int) $statement->fetchColumn() > 0;
    }

    private function hasTable(PDO $pdo, string $table): bool
    {
        $statement = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table');
        $statement->execute(['table' => $table]);
        return (int) $statement->fetchColumn() > 0;
    }

    private function hasColumn(PDO $pdo, string $table, string $column): bool
    {
        $statement = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column');
        $statement->execute(['table' => $table, 'column' => $column]);
        return (int) $statement->fetchColumn() > 0;
    }
}
