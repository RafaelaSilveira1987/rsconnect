<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use Throwable;

final class OnboardingGuideService
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::connection();
    }

    /** @return array<string, mixed> */
    public function dashboard(int $tenantId, ?int $userId = null): array
    {
        $tenant = $this->tenant($tenantId);
        $manual = $this->manualProgress($tenantId);
        $definitions = $this->definitions();
        $steps = [];
        $done = 0;
        $attention = 0;
        $pending = 0;
        $blocked = 0;

        foreach ($definitions as $index => $definition) {
            $auto = $this->autoStatus($tenantId, $definition['key']);
            $manualRow = $manual[$definition['key']] ?? null;
            $status = $auto['status'];
            $message = $auto['message'];
            $notes = $manualRow['notes'] ?? '';
            $manualStatus = (string) ($manualRow['status'] ?? 'auto');

            if ($manualRow && $manualStatus !== 'auto') {
                // Etapas cadastrais críticas não podem ser marcadas como concluídas apenas
                // por um registro manual antigo quando a validação estrutural atual falha.
                // Isso evita que tenants sem segmento/modelo atravessem o onboarding.
                $strictAuto = in_array((string) $definition['key'], ['company_profile'], true);
                $manualCanOverride = !$strictAuto
                    || in_array($auto['status'], ['complete', 'skipped'], true)
                    || !in_array($manualStatus, ['complete', 'skipped'], true);
                if ($manualCanOverride) {
                    $status = $manualStatus;
                    if ($notes !== '') {
                        $message = $notes;
                    }
                }
            }

            $blockedBy = $this->blockedBy($definition['key'], $steps);
            if ($blockedBy !== null && !in_array($status, ['complete', 'skipped'], true)) {
                $status = 'blocked';
                $message = 'Conclua primeiro: ' . $blockedBy . '.';
            }

            if (in_array($status, ['complete', 'skipped'], true)) {
                $done++;
            } elseif ($status === 'attention') {
                $attention++;
            } elseif ($status === 'blocked') {
                $blocked++;
            } else {
                $pending++;
            }

            $steps[] = $definition + [
                'index' => $index + 1,
                'status' => $status,
                'status_label' => $this->statusLabel($status),
                'status_badge' => $this->statusBadge($status),
                'message' => $message,
                'manual_status' => $manualStatus,
                'notes' => $notes,
                'completed_at' => $manualRow['completed_at'] ?? null,
            ];
        }

        $total = count($steps);
        $percent = $total > 0 ? (int) round(($done / $total) * 100) : 0;
        $next = null;
        foreach ($steps as $step) {
            if (!in_array($step['status'], ['complete', 'skipped'], true)) {
                $next = $step;
                break;
            }
        }

        return [
            'tenant' => $tenant,
            'steps' => $steps,
            'summary' => [
                'total' => $total,
                'done' => $done,
                'pending' => $pending,
                'attention' => $attention,
                'blocked' => $blocked,
                'percent' => $percent,
                'is_complete' => $done >= $total && $total > 0,
            ],
            'next' => $next,
            'instances' => $this->instances($tenantId),
            'agents' => $this->agents($tenantId),
            'default_agent' => $this->defaultAgent($tenantId),
            'attendance_settings' => $this->onboardingSettings($tenantId),
            'sla_settings' => (new SlaPolicyService($this->pdo))->settings($tenantId),
            'calendar_access' => $this->calendarAccessSettings($tenantId),
            'calendar_availability' => (new CalendarAvailabilityService())->settings($tenantId),
            'pre_schedule' => $this->preScheduleSettings($tenantId),
            'agent_limits' => $this->agentLimitsSettings($tenantId),
            'business_niches' => (new AgentBlueprintService())->niches(),
            'agent_blueprints' => (new AgentBlueprintService())->blueprints(),
            'agent_blueprint_profile' => (new AgentBlueprintService())->profileForTenant($tenantId, false),
            'privacy' => $this->privacyStatus($tenantId, $userId),
            'events' => $this->events($tenantId),
            'quick_links' => $this->quickLinks(),
        ];
    }

    public function requiresGuidedAccess(int $tenantId): bool
    {
        $tenant = $this->tenant($tenantId);
        return $tenantId > 0 && empty($tenant['onboarding_completed_at']);
    }

    public function currentStepKey(int $tenantId, ?int $userId = null): ?string
    {
        $next = $this->dashboard($tenantId, $userId)['next'] ?? null;
        return is_array($next) ? (string) ($next['key'] ?? '') : null;
    }

    public function pathAllowedDuringOnboarding(int $tenantId, string $path, ?int $userId = null): bool
    {
        $path = '/' . trim($path, '/');
        $always = ['/onboarding', '/primeiros-passos', '/logout', '/subscription', '/access-restricted'];
        if (in_array($path, $always, true) || str_starts_with($path, '/onboarding/')) {
            return true;
        }
        $step = $this->currentStepKey($tenantId, $userId);
        // Wizard express fica acessível após a etapa de aceite de privacidade,
        // sem liberar essa rota como atalho para LGPD/primeiro cadastro.
        if (($path === '/api/agents/express' || str_starts_with($path, '/api/v1/agents/')
            || str_starts_with($path, '/api/v1/agent/')) && in_array($step, [
            'attendance_rules', 'agenda_setup', 'agent_limits',
            'whatsapp_connection', 'ai_agent', 'final_test',
        ], true)) {
            return true;
        }
        return match ($step) {
            'lgpd_acceptance' => in_array($path, ['/privacy/accept'], true),
            'attendance_rules', 'agenda_setup', 'agent_limits' => str_starts_with($path, '/agents') || str_starts_with($path, '/calendar'),
            'whatsapp_connection' => str_starts_with($path, '/instances'),
            'ai_agent' => str_starts_with($path, '/agents') || str_starts_with($path, '/prompt-studio'),
            'final_test' => str_starts_with($path, '/conversations') || str_starts_with($path, '/calendar'),
            default => false,
        };
    }

    public function saveStep(int $tenantId, string $stepKey, string $status, string $notes, ?int $userId): void
    {
        $allowed = ['auto', 'pending', 'complete', 'skipped', 'attention'];
        if (!in_array($status, $allowed, true)) {
            $status = 'auto';
        }
        if (!$this->definition($stepKey)) {
            throw new \RuntimeException('Etapa de onboarding inválida.');
        }

        $notes = mb_substr(trim($notes), 0, 1200);
        $completedAt = in_array($status, ['complete', 'skipped'], true) ? 'NOW()' : 'NULL';
        $this->ensureTables();
        $statement = $this->pdo->prepare(
            'INSERT INTO tenant_onboarding_progress
                (tenant_id, step_key, status, notes, completed_at, updated_by, updated_at)
             VALUES
                (:tenant_id, :step_key, :status, :notes, ' . $completedAt . ', :updated_by, NOW())
             ON DUPLICATE KEY UPDATE
                status = VALUES(status),
                notes = VALUES(notes),
                completed_at = ' . $completedAt . ',
                updated_by = VALUES(updated_by),
                updated_at = NOW()'
        );
        $statement->execute([
            'tenant_id' => $tenantId,
            'step_key' => $stepKey,
            'status' => $status,
            'notes' => $notes !== '' ? $notes : null,
            'updated_by' => $userId,
        ]);

        $this->recordEvent($tenantId, $userId, 'onboarding.step_updated', 'Etapa atualizada: ' . $stepKey . ' → ' . $status, [
            'step_key' => $stepKey,
            'status' => $status,
            'notes' => $notes,
        ]);

        $this->syncImplementation($tenantId, $stepKey, $status, $notes, $userId);
        $this->refreshTenantProgress($tenantId);
    }

    /**
     * Salva a configuração essencial do atendimento em uma única transação.
     * A tela fala a linguagem do negócio; este método traduz as respostas para
     * triagem, workflow, capabilities, agenda e políticas estruturadas.
     *
     * @param array<string,mixed> $data
     */
    public function saveBusinessOperation(int $tenantId, array $data, ?int $userId): void
    {
        if ($tenantId < 1) {
            throw new \RuntimeException('Empresa inválida.');
        }

        $agendaEnabled = (string) ($data['calendar_enabled'] ?? '0') === '1';
        $approvalMode = strtolower(trim((string) ($data['approval_mode'] ?? 'human')));
        $humanApproval = $approvalMode !== 'automatic';
        $capacity = max(1, min(100, (int) ($data['slot_capacity'] ?? 1)));
        $selectedFields = array_values(array_unique(array_filter(array_map(
            static fn (mixed $value): string => mb_substr(trim((string) $value), 0, 100),
            is_array($data['triage_fields'] ?? null) ? $data['triage_fields'] : []
        ))));

        if ($agendaEnabled && $selectedFields === []) {
            throw new \RuntimeException('Selecione pelo menos uma informação que o assistente deve coletar antes de agendar.');
        }

        $days = is_array($data['days'] ?? null) ? $data['days'] : [];
        $dayMap = ['sun' => 0, 'mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6];
        $internalDays = [];
        foreach ($days as $dayCode) {
            $dayCode = strtolower(trim((string) $dayCode));
            if (array_key_exists($dayCode, $dayMap)) {
                $internalDays[] = $dayMap[$dayCode];
            }
        }
        $internalDays = array_values(array_unique($internalDays));

        $currentAccess = $this->calendarAccessSettings($tenantId);
        $calendarMode = 'none';
        if ($agendaEnabled) {
            $calendarMode = ($currentAccess['calendar_mode'] ?? 'none') === 'smart'
                && ($currentAccess['smart_calendar_status'] ?? 'locked') === 'ready'
                ? 'smart'
                : 'internal';
        }

        $currentCalendar = (new CalendarAvailabilityService())->settings($tenantId);
        $currentPreSchedule = $this->preScheduleSettings($tenantId);
        $currentLimits = $this->agentLimitsSettings($tenantId);

        $start = trim((string) ($data['start_time'] ?? '08:00')) ?: '08:00';
        $end = trim((string) ($data['end_time'] ?? '18:00')) ?: '18:00';
        $internalStart = [];
        $internalEnd = [];
        foreach ($internalDays as $dayNumber) {
            $internalStart[$dayNumber] = $start;
            $internalEnd[$dayNumber] = $end;
        }

        $discountPolicy = strtolower(trim((string) ($data['discount_policy'] ?? 'off')));
        $refundPolicy = strtolower(trim((string) ($data['refund_policy'] ?? 'off')));
        $exceptionPolicy = strtolower(trim((string) ($data['exception_policy'] ?? 'off')));
        $ageRestricted = (string) ($data['age_restricted'] ?? '0') === '1';
        $customTerms = trim((string) ($data['custom_case_terms'] ?? ''));

        $limitsPayload = [
            'discount_rule_enabled' => $discountPolicy !== 'off' ? 1 : 0,
            'discount_action' => $discountPolicy === 'deny' ? 'warn' : 'handoff',
            'discount_message' => (string) ($currentLimits['discount_message'] ?? 'Posso encaminhar sua solicitação para o responsável avaliar uma condição possível. O assistente não autoriza descontos por conta própria.'),
            'refund_rule_enabled' => $refundPolicy !== 'off' ? 1 : 0,
            'refund_action' => $refundPolicy === 'deny' ? 'warn' : 'handoff',
            'refund_message' => (string) ($currentLimits['refund_message'] ?? 'Essa decisão precisa ser avaliada pela equipe responsável. Vou encaminhar sua solicitação.'),
            'exception_rule_enabled' => $exceptionPolicy !== 'off' ? 1 : 0,
            'exception_action' => $exceptionPolicy === 'deny' ? 'warn' : 'handoff',
            'exception_message' => (string) ($currentLimits['exception_message'] ?? 'Essa exceção precisa ser analisada pela equipe. Posso encaminhar seu pedido para avaliação.'),
            'minimum_age' => $ageRestricted ? max(0, min(130, (int) ($data['minimum_age'] ?? 0))) : 0,
            'maximum_age' => $ageRestricted ? max(0, min(130, (int) ($data['maximum_age'] ?? 0))) : 0,
            'age_action' => (string) ($data['age_action'] ?? ($currentLimits['age_action'] ?? 'block_schedule')),
            'minimum_age_message' => (string) ($currentLimits['minimum_age_message'] ?? ''),
            'maximum_age_message' => (string) ($currentLimits['maximum_age_message'] ?? ''),
            'custom_case_enabled' => $customTerms !== '' ? 1 : 0,
            'custom_case_terms' => $customTerms,
            'custom_case_action' => 'handoff',
            'custom_case_message' => (string) ($currentLimits['custom_case_message'] ?? 'Este caso precisa ser analisado pela equipe responsável. Vou encaminhar sua solicitação.'),
            'emergency_rule_enabled' => !empty($data['emergency_rule_enabled']) ? 1 : 0,
            'emergency_action' => 'handoff',
            'emergency_message' => trim((string) ($data['emergency_message'] ?? ($currentLimits['emergency_message'] ?? 'Essa situação precisa de atenção humana. Vou encaminhar sua mensagem para a equipe responsável.'))),
        ];

        $attendancePayload = [
            'start_time' => $start,
            'end_time' => $end,
            'days' => $days,
            'business_timezone' => (string) ($data['business_timezone'] ?? 'America/Sao_Paulo'),
            'after_hours_message' => (string) ($data['after_hours_message'] ?? ''),
            'human_handoff_message' => (string) ($data['human_handoff_message'] ?? ''),
            'cooldown_seconds' => (int) ($data['cooldown_seconds'] ?? 60),
            'sla_enabled' => !empty($data['sla_enabled']) ? 1 : 0,
            'sla_target_minutes' => (int) ($data['sla_target_minutes'] ?? 30),
            'sla_warning_percent' => (int) ($data['sla_warning_percent'] ?? 80),
            'sla_count_outside_hours' => !empty($data['sla_count_outside_hours']) ? 1 : 0,
        ];

        $agendaPayload = [
            'calendar_mode' => $calendarMode,
            'require_human_approval' => $humanApproval ? 1 : 0,
            'ai_can_suggest_slots' => $agendaEnabled ? 1 : 0,
            'ai_can_confirm' => $agendaEnabled && !$humanApproval ? 1 : 0,
            'default_duration_minutes' => max(15, min(240, (int) ($data['default_duration_minutes'] ?? 60))),
            'min_notice_hours' => max(0, min(720, (int) ($data['min_notice_hours'] ?? 2))),
            'booking_capacity_mode' => $capacity > 1 ? 'capacity' : 'single',
            'default_slot_capacity' => $capacity,
            'internal_days' => $internalDays,
            'internal_start' => $internalStart,
            'internal_end' => $internalEnd,
            'internal_availability_strategy' => (string) ($currentCalendar['internal_availability_strategy'] ?? 'calculated'),
            'slot_interval_minutes' => (int) ($currentCalendar['slot_interval_minutes'] ?? 30),
            'buffer_minutes' => (int) ($currentCalendar['buffer_minutes'] ?? 0),
            'search_days_ahead' => (int) ($currentCalendar['search_days_ahead'] ?? 30),
            'max_suggestions' => (int) ($currentCalendar['max_suggestions'] ?? 5),
            'published_slots_respect_min_notice' => !empty($currentCalendar['published_slots_respect_min_notice']) ? 1 : 0,
            'default_message' => (string) ($currentPreSchedule['default_message'] ?? 'A consulta de disponibilidade para {{dia_preferido}} às {{horario_preferido}} está em andamento.'),
            'collect_message' => (string) ($currentPreSchedule['collect_message'] ?? 'Certo. Me informe, por favor, o melhor dia e período ou horário para atendimento.'),
        ];

        // MySQL faz COMMIT implicito em CREATE TABLE / ALTER TABLE, inclusive com
        // IF NOT EXISTS. Preparar o schema antes da transacao impede um salvamento
        // parcial seguido de "There is no active transaction" no commit().
        $this->prepareBusinessOperationStorage();

        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $this->syncAssistedTriageAndWorkflow($tenantId, $selectedFields, $agendaEnabled);
            $this->saveAttendance($tenantId, $attendancePayload, $userId);
            $this->saveAgenda($tenantId, $agendaPayload, $userId);
            $this->saveAgentLimits($tenantId, $limitsPayload, $userId);
            $this->syncAssistedCapabilities($tenantId, $agendaEnabled, $humanApproval, $selectedFields !== [], $ageRestricted);

            if ($this->tableExists('tenant_agent_profiles')) {
                $this->pdo->prepare('UPDATE tenant_agent_profiles SET customized = 1, updated_at = CURRENT_TIMESTAMP WHERE tenant_id = :tenant_id')
                    ->execute(['tenant_id' => $tenantId]);
            }

            $this->recordEvent($tenantId, $userId, 'onboarding.business_operation_saved', 'Configuração essencial do atendimento salva e validada em uma única operação.', [
                'triage_fields' => $selectedFields,
                'calendar_enabled' => $agendaEnabled,
                'calendar_mode' => $calendarMode,
                'slot_capacity' => $capacity,
                'human_approval' => $humanApproval,
                'discount_policy' => $discountPolicy,
                'refund_policy' => $refundPolicy,
                'exception_policy' => $exceptionPolicy,
                'age_restricted' => $ageRestricted,
            ]);

            if ($ownsTransaction) {
                // Falhar de forma explicita caso um colaborador futuro execute DDL
                // indevidamente durante a operacao transacional.
                if (!$this->pdo->inTransaction()) {
                    throw new \RuntimeException('A transação do atendimento foi encerrada inesperadamente. Revise as migrations e os registros antes de tentar novamente.');
                }
                $this->pdo->commit();
            }
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        (new AgentConversationBehaviorService())->clearCache($tenantId);
    }

    /** @param array<string, mixed> $data */
    public function saveAttendance(int $tenantId, array $data, ?int $userId): void
    {
        $start = trim((string) ($data['start_time'] ?? '08:00')) ?: '08:00';
        $end = trim((string) ($data['end_time'] ?? '18:00')) ?: '18:00';
        $days = $data['days'] ?? ['mon', 'tue', 'wed', 'thu', 'fri'];
        if (!is_array($days) || !$days) {
            $days = ['mon', 'tue', 'wed', 'thu', 'fri'];
        }
        $allowedDays = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
        $days = array_values(array_intersect($allowedDays, array_map('strval', $days)));
        if (!$days) {
            throw new \RuntimeException('Selecione pelo menos um dia de atendimento.');
        }
        if ($end <= $start) {
            throw new \RuntimeException('O fim do atendimento deve ser posterior ao início.');
        }

        $hoursJson = json_encode(['days' => $days, 'start' => $start, 'end' => $end], JSON_UNESCAPED_UNICODE);
        $timezone = trim((string) ($data['business_timezone'] ?? 'America/Sao_Paulo')) ?: 'America/Sao_Paulo';
        $afterHours = mb_substr(trim((string) ($data['after_hours_message'] ?? 'No momento estamos fora do horário de atendimento. Assim que possível, nossa equipe retorna o contato.')), 0, 500);
        $handoff = mb_substr(trim((string) ($data['human_handoff_message'] ?? 'Vou encaminhar sua solicitação para uma pessoa da equipe continuar o atendimento.')), 0, 500);
        $cooldown = max(0, min(3600, (int) ($data['cooldown_seconds'] ?? 60)));

        $this->ensureOnboardingSettingsTable();
        $statement = $this->pdo->prepare(
            'INSERT INTO tenant_onboarding_settings
                (tenant_id, business_timezone, business_hours_json, after_hours_message, human_handoff_message, cooldown_seconds)
             VALUES
                (:tenant_id, :business_timezone, :business_hours_json, :after_hours_message, :human_handoff_message, :cooldown_seconds)
             ON DUPLICATE KEY UPDATE
                business_timezone = VALUES(business_timezone),
                business_hours_json = VALUES(business_hours_json),
                after_hours_message = VALUES(after_hours_message),
                human_handoff_message = VALUES(human_handoff_message),
                cooldown_seconds = VALUES(cooldown_seconds),
                updated_at = NOW()'
        );
        $statement->execute([
            'tenant_id' => $tenantId,
            'business_timezone' => $timezone,
            'business_hours_json' => $hoursJson,
            'after_hours_message' => $afterHours !== '' ? $afterHours : null,
            'human_handoff_message' => $handoff !== '' ? $handoff : null,
            'cooldown_seconds' => $cooldown,
        ]);

        $slaPayload = $data + [
            'business_timezone' => $timezone,
            'business_hours_json' => $hoursJson,
        ];
        (new SlaPolicyService($this->pdo))->save($tenantId, $slaPayload, $userId);

        $this->applyStoredAttendanceToAgent($tenantId, null);
        $this->saveStep($tenantId, 'attendance_rules', 'complete', 'Horários, mensagem fora de horário e encaminhamento humano definidos para a operação.', $userId);
        $this->recordEvent($tenantId, $userId, 'onboarding.attendance_saved', 'Regras de atendimento preparadas para os agentes da empresa.', []);
    }

    public function applyStoredAttendanceToAgent(int $tenantId, ?int $agentId = null): void
    {
        $settings = $this->onboardingSettings($tenantId);
        if (!$settings || empty($settings['business_hours_json']) || !$this->tableExists('ai_agents')) {
            return;
        }
        $sql = 'UPDATE ai_agents
                SET business_hours_enabled = 1,
                    business_timezone = :business_timezone,
                    business_hours_json = :business_hours_json,
                    after_hours_message = :after_hours_message,
                    human_handoff_message = :human_handoff_message,
                    handoff_action = "paused",
                    cooldown_seconds = :cooldown_seconds
                WHERE tenant_id = :tenant_id';
        $params = [
            'business_timezone' => (string) ($settings['business_timezone'] ?? 'America/Sao_Paulo'),
            'business_hours_json' => (string) $settings['business_hours_json'],
            'after_hours_message' => $settings['after_hours_message'] ?? null,
            'human_handoff_message' => $settings['human_handoff_message'] ?? null,
            'cooldown_seconds' => (int) ($settings['cooldown_seconds'] ?? 60),
            'tenant_id' => $tenantId,
        ];
        if ($agentId !== null && $agentId > 0) {
            $sql .= ' AND id = :agent_id';
            $params['agent_id'] = $agentId;
        }
        $this->pdo->prepare($sql)->execute($params);
    }

    /** @param array<string, mixed> $data */
    public function saveAgenda(int $tenantId, array $data, ?int $userId): void
    {
        $mode = strtolower(trim((string) ($data['calendar_mode'] ?? 'none')));
        if (!in_array($mode, ['none', 'internal', 'smart'], true)) {
            throw new \RuntimeException('Selecione uma modalidade de agenda válida.');
        }

        $access = $this->calendarAccessSettings($tenantId);
        if ($mode === 'smart' && (string) ($access['smart_calendar_status'] ?? 'locked') !== 'ready') {
            throw new \RuntimeException('A Agenda inteligente ainda não foi liberada e homologada pela equipe RS Connect.');
        }

        $enabled = $mode === 'none' ? 0 : 1;
        $humanApproval = (string) ($data['require_human_approval'] ?? '') === '1' ? 1 : 0;
        $suggest = (string) ($data['ai_can_suggest_slots'] ?? '') === '1' ? 1 : 0;
        $confirm = $humanApproval === 1 ? 0 : ((string) ($data['ai_can_confirm'] ?? '') === '1' ? 1 : 0);
        $duration = max(15, min(240, (int) ($data['default_duration_minutes'] ?? 60)));
        $collect = mb_substr(trim((string) ($data['collect_message'] ?? 'Certo. Me informe, por favor, o melhor dia e período ou horário para atendimento.')), 0, 800);
        $registered = mb_substr(trim((string) ($data['default_message'] ?? 'A consulta de disponibilidade para {{dia_preferido}} às {{horario_preferido}} está em andamento.')), 0, 500);

        $this->ensurePreScheduleTable();
        $statement = $this->pdo->prepare(
            'INSERT INTO tenant_pre_schedule_settings
                (tenant_id, enabled, require_human_approval, ai_can_suggest_slots, ai_can_confirm, default_duration_minutes, default_message, collect_message, updated_at)
             VALUES
                (:tenant_id, :enabled, :require_human_approval, :ai_can_suggest_slots, :ai_can_confirm, :duration, :default_message, :collect_message, NOW())
             ON DUPLICATE KEY UPDATE
                enabled = VALUES(enabled),
                require_human_approval = VALUES(require_human_approval),
                ai_can_suggest_slots = VALUES(ai_can_suggest_slots),
                ai_can_confirm = VALUES(ai_can_confirm),
                default_duration_minutes = VALUES(default_duration_minutes),
                default_message = VALUES(default_message),
                collect_message = VALUES(collect_message),
                updated_at = NOW()'
        );
        $statement->execute([
            'tenant_id' => $tenantId,
            'enabled' => $enabled,
            'require_human_approval' => $humanApproval,
            'ai_can_suggest_slots' => $suggest,
            'ai_can_confirm' => $confirm,
            'duration' => $duration,
            'default_message' => $registered !== '' ? $registered : null,
            'collect_message' => $collect !== '' ? $collect : null,
        ]);

        $this->ensureOnboardingSettingsTable();
        $this->pdo->prepare(
            'INSERT INTO tenant_onboarding_settings (tenant_id, calendar_mode)
             VALUES (:tenant_id, :calendar_mode)
             ON DUPLICATE KEY UPDATE calendar_mode = VALUES(calendar_mode), updated_at = NOW()'
        )->execute(['tenant_id' => $tenantId, 'calendar_mode' => $mode]);

        $calendar = new CalendarAvailabilityService();
        if ($mode === 'internal') {
            $calendar->configureInternalMode($tenantId, $data + ['default_duration_minutes' => $duration]);
        } elseif ($mode === 'smart') {
            $calendar->configureSmartMode($tenantId);
        } else {
            $calendar->disableAvailability($tenantId);
        }

        $status = $mode === 'none' ? 'skipped' : 'complete';
        $message = match ($mode) {
            'internal' => 'Agenda interna do RS Connect configurada, sem n8n ou Google Calendar.',
            'smart' => 'Agenda inteligente liberada pela RS Connect e selecionada para esta empresa.',
            default => 'Agenda dispensada para esta operação.',
        };
        $this->saveStep($tenantId, 'agenda_setup', $status, $message, $userId);
        $this->recordEvent($tenantId, $userId, 'onboarding.agenda_saved', $message, ['calendar_mode' => $mode, 'enabled' => $enabled]);
    }

    /** @return array<string, mixed> */
    public function calendarAccessSettings(int $tenantId): array
    {
        $settings = $this->onboardingSettings($tenantId) ?: [];
        return [
            'calendar_mode' => (string) ($settings['calendar_mode'] ?? 'none'),
            'smart_calendar_status' => (string) ($settings['smart_calendar_status'] ?? 'locked'),
            'smart_calendar_released_by' => $settings['smart_calendar_released_by'] ?? null,
            'smart_calendar_released_at' => $settings['smart_calendar_released_at'] ?? null,
        ];
    }

    public function saveSmartCalendarAccess(int $tenantId, string $status, ?int $userId): void
    {
        if (!in_array($status, ['locked', 'configuring', 'ready'], true)) {
            throw new \RuntimeException('Situação da Agenda inteligente inválida.');
        }
        $this->ensureOnboardingSettingsTable();
        $releasedAt = $status === 'ready' ? \App\Core\Clock::nowUtc() : null;
        $this->pdo->prepare(
            'INSERT INTO tenant_onboarding_settings
                (tenant_id, smart_calendar_status, smart_calendar_released_by, smart_calendar_released_at)
             VALUES
                (:tenant_id, :status, :released_by, :released_at)
             ON DUPLICATE KEY UPDATE
                smart_calendar_status = VALUES(smart_calendar_status),
                smart_calendar_released_by = VALUES(smart_calendar_released_by),
                smart_calendar_released_at = VALUES(smart_calendar_released_at),
                updated_at = NOW()'
        )->execute([
            'tenant_id' => $tenantId,
            'status' => $status,
            'released_by' => $status === 'ready' ? $userId : null,
            'released_at' => $releasedAt,
        ]);

        if ($status !== 'ready') {
            $current = $this->calendarAccessSettings($tenantId);
            if (($current['calendar_mode'] ?? 'none') === 'smart') {
                $fallback = ((int) (($this->preScheduleSettings($tenantId)['enabled'] ?? 0)) === 1) ? 'internal' : 'none';
                $this->pdo->prepare('UPDATE tenant_onboarding_settings SET calendar_mode = :mode WHERE tenant_id = :tenant_id')
                    ->execute(['mode' => $fallback, 'tenant_id' => $tenantId]);
                if ($fallback === 'internal') {
                    (new CalendarAvailabilityService())->configureInternalMode($tenantId, []);
                } else {
                    (new CalendarAvailabilityService())->disableAvailability($tenantId);
                }
            }
        }

        $this->recordEvent($tenantId, $userId, 'calendar.smart_access_updated', 'Agenda inteligente: ' . $status . '.', ['status' => $status]);
    }

    /**
     * Salva limites e condutas como políticas estruturadas do Policy Engine.
     * Nenhuma decisão comercial/eligibilidade fica presa ao prompt do modelo.
     *
     * @param array<string,mixed> $data
     */
    public function saveAgentLimits(int $tenantId, array $data, ?int $userId): void
    {
        if (!$this->tableExists('tenant_agent_policies')) {
            throw new \RuntimeException('A estrutura de políticas do assistente ainda não foi aplicada.');
        }

        $discountEnabled = !empty($data['discount_rule_enabled']);
        $discountAction = $this->normalizeLimitAction((string) ($data['discount_action'] ?? 'handoff'), ['handoff', 'warn']);
        $discountMessage = mb_substr(trim((string) ($data['discount_message'] ?? 'Posso encaminhar sua solicitação para o responsável avaliar uma condição possível. O assistente não autoriza descontos por conta própria.')), 0, 1200);
        $this->upsertAssistedPolicy(
            $tenantId,
            'assisted.discount_request',
            'json',
            [
                'condition' => ['field' => '_turn_intent', 'operator' => 'eq', 'value' => 'discount_request'],
                'applies_to' => ['conversation', 'calendar.*'],
            ],
            $discountAction,
            $discountMessage,
            $discountEnabled,
            20
        );

        $refundEnabled = !empty($data['refund_rule_enabled']);
        $refundAction = $this->normalizeLimitAction((string) ($data['refund_action'] ?? 'handoff'), ['handoff', 'warn']);
        $refundMessage = mb_substr(trim((string) ($data['refund_message'] ?? 'Essa decisão precisa ser avaliada pela equipe responsável. Vou encaminhar sua solicitação.')), 0, 1200);
        $this->upsertAssistedPolicy(
            $tenantId,
            'assisted.refund_request',
            'json',
            [
                'condition' => ['field' => '_turn_intent', 'operator' => 'eq', 'value' => 'refund_request'],
                'applies_to' => ['conversation', 'calendar.*'],
            ],
            $refundAction,
            $refundMessage,
            $refundEnabled,
            30
        );

        $exceptionEnabled = !empty($data['exception_rule_enabled']);
        $exceptionAction = $this->normalizeLimitAction((string) ($data['exception_action'] ?? 'handoff'), ['handoff', 'warn']);
        $exceptionMessage = mb_substr(trim((string) ($data['exception_message'] ?? 'Essa exceção precisa ser analisada pela equipe. Posso encaminhar seu pedido para avaliação.')), 0, 1200);
        $this->upsertAssistedPolicy(
            $tenantId,
            'assisted.exception_request',
            'json',
            [
                'condition' => ['field' => '_turn_intent', 'operator' => 'eq', 'value' => 'exception_request'],
                'applies_to' => ['conversation', 'calendar.*'],
            ],
            $exceptionAction,
            $exceptionMessage,
            $exceptionEnabled,
            40
        );

        $minimumAge = max(0, min(130, (int) ($data['minimum_age'] ?? 0)));
        $maximumAge = max(0, min(130, (int) ($data['maximum_age'] ?? 0)));
        if ($minimumAge > 0 && $maximumAge > 0 && $maximumAge < $minimumAge) {
            throw new \RuntimeException('A idade máxima não pode ser menor que a idade mínima.');
        }
        $ageAction = $this->normalizeLimitAction((string) ($data['age_action'] ?? 'block_schedule'), ['block_schedule', 'handoff', 'block']);
        $minimumMessage = mb_substr(trim((string) ($data['minimum_age_message'] ?? 'Atualmente os atendimentos são realizados a partir de {{minimum_age}} anos. Por isso, não consigo seguir com o agendamento para a idade informada.')), 0, 1200);
        $maximumMessage = mb_substr(trim((string) ($data['maximum_age_message'] ?? ($maximumAge > 0 ? 'Atualmente os atendimentos são realizados até ' . $maximumAge . ' anos. Por isso, não consigo seguir com o agendamento para a idade informada.' : ''))), 0, 1200);

        // Mantém a chave histórica minimum_age para compatibilidade com blueprints e
        // com o tratamento especial que injeta {{minimum_age}} na resposta.
        $this->upsertAssistedPolicy(
            $tenantId,
            'minimum_age',
            'number',
            $minimumAge,
            $ageAction,
            $minimumMessage,
            $minimumAge > 0,
            10
        );
        $this->upsertAssistedPolicy(
            $tenantId,
            'assisted.maximum_age',
            'json',
            [
                'condition' => ['field' => 'patient_age', 'operator' => 'gt', 'value' => $maximumAge],
                'applies_to' => ['conversation', 'calendar.*'],
            ],
            $ageAction,
            $maximumMessage,
            $maximumAge > 0,
            11
        );

        // Casos extras configuráveis sem adicionar ifs de negócio no código.
        // Cada expressão vira uma política JSON independente, avaliada pelo texto do
        // turno atual. A lista é curta de propósito para manter regras auditáveis.
        $customEnabled = !empty($data['custom_case_enabled']);
        $customAction = $this->normalizeLimitAction((string) ($data['custom_case_action'] ?? 'handoff'), ['handoff', 'warn', 'block']);
        $customMessage = mb_substr(trim((string) ($data['custom_case_message'] ?? 'Este caso precisa ser analisado pela equipe responsável. Vou encaminhar sua solicitação.')), 0, 1200);
        $customRaw = trim((string) ($data['custom_case_terms'] ?? ''));
        $customTerms = [];
        foreach (preg_split('/\\R+/', $customRaw) ?: [] as $line) {
            $display = trim((string) $line);
            $normalized = $this->normalizeLimitTerm($display);
            if ($display === '' || $normalized === '') continue;
            $customTerms[$normalized] = mb_substr($display, 0, 120);
            if (count($customTerms) >= 10) break;
        }
        $this->pdo->prepare('DELETE FROM tenant_agent_policies WHERE tenant_id = :tenant_id AND policy_key LIKE "assisted.custom_case.%"')
            ->execute(['tenant_id' => $tenantId]);
        if ($customEnabled) {
            $position = 60;
            foreach ($customTerms as $normalized => $display) {
                $this->upsertAssistedPolicy(
                    $tenantId,
                    'assisted.custom_case.' . $position,
                    'json',
                    [
                        'condition' => ['field' => '_turn_text', 'operator' => 'contains', 'value' => $normalized],
                        'applies_to' => ['conversation', 'calendar.*'],
                        'display_value' => $display,
                    ],
                    $customAction,
                    $customMessage,
                    true,
                    $position
                );
                $position++;
            }
        }

        $emergencyEnabled = !empty($data['emergency_rule_enabled']);
        $emergencyAction = $this->normalizeLimitAction((string) ($data['emergency_action'] ?? 'handoff'), ['handoff', 'block', 'warn']);
        $emergencyMessage = mb_substr(trim((string) ($data['emergency_message'] ?? 'Essa situação precisa de atenção humana. Vou interromper o fluxo automático e encaminhar sua mensagem para a equipe responsável.')), 0, 1200);
        $this->upsertAssistedPolicy(
            $tenantId,
            'assisted.emergency_request',
            'json',
            [
                'condition' => ['field' => '_turn_intent', 'operator' => 'eq', 'value' => 'emergency_request'],
                'applies_to' => ['conversation', 'calendar.*'],
            ],
            $emergencyAction,
            $emergencyMessage,
            $emergencyEnabled,
            5
        );

        $this->saveStep(
            $tenantId,
            'agent_limits',
            'complete',
            'Limites de autoridade, elegibilidade e encaminhamento revisados. O Policy Engine executa as decisões antes da IA.',
            $userId
        );
        $this->recordEvent($tenantId, $userId, 'onboarding.agent_limits_saved', 'Limites e condutas do assistente atualizados.', [
            'discount_rule_enabled' => $discountEnabled,
            'refund_rule_enabled' => $refundEnabled,
            'exception_rule_enabled' => $exceptionEnabled,
            'minimum_age' => $minimumAge,
            'maximum_age' => $maximumAge,
            'emergency_rule_enabled' => $emergencyEnabled,
            'custom_case_count' => count($customTerms),
        ]);
    }

    /** @return array<string,mixed> */
    public function agentLimitsSettings(int $tenantId): array
    {
        $defaults = [
            'discount_rule_enabled' => 1,
            'discount_action' => 'handoff',
            'discount_message' => 'Posso encaminhar sua solicitação para o responsável avaliar uma condição possível. O assistente não autoriza descontos por conta própria.',
            'refund_rule_enabled' => 1,
            'refund_action' => 'handoff',
            'refund_message' => 'Essa decisão precisa ser avaliada pela equipe responsável. Vou encaminhar sua solicitação.',
            'exception_rule_enabled' => 1,
            'exception_action' => 'handoff',
            'exception_message' => 'Essa exceção precisa ser analisada pela equipe. Posso encaminhar seu pedido para avaliação.',
            'minimum_age' => 0,
            'maximum_age' => 0,
            'age_action' => 'block_schedule',
            'minimum_age_message' => 'Atualmente os atendimentos são realizados a partir de {{minimum_age}} anos. Por isso, não consigo seguir com o agendamento para a idade informada.',
            'maximum_age_message' => '',
            'custom_case_enabled' => 0,
            'custom_case_terms' => '',
            'custom_case_action' => 'handoff',
            'custom_case_message' => 'Este caso precisa ser analisado pela equipe responsável. Vou encaminhar sua solicitação.',
            'emergency_rule_enabled' => 0,
            'emergency_action' => 'handoff',
            'emergency_message' => 'Essa situação precisa de atenção humana. Vou interromper o fluxo automático e encaminhar sua mensagem para a equipe responsável.',
            'configured' => false,
        ];
        if (!$this->tableExists('tenant_agent_policies')) {
            return $defaults;
        }

        $stmt = $this->pdo->prepare(
            'SELECT policy_key, policy_type, value_json, action_key, customer_message, enabled
             FROM tenant_agent_policies
             WHERE tenant_id = :tenant_id
               AND (
                    policy_key IN (
                        "minimum_age", "assisted.maximum_age", "assisted.discount_request",
                        "assisted.refund_request", "assisted.exception_request", "assisted.emergency_request"
                    )
                    OR policy_key LIKE "assisted.custom_case.%"
               )'
        );
        $stmt->execute(['tenant_id' => $tenantId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($rows === []) {
            return $defaults;
        }
        $map = [];
        foreach ($rows as $row) {
            $map[(string) $row['policy_key']] = $row + ['value' => $this->decodeJsonValue($row['value_json'] ?? null)];
        }
        $out = $defaults;
        $out['configured'] = false;
        foreach (array_keys($map) as $policyKey) {
            if (str_starts_with((string) $policyKey, 'assisted.')) {
                $out['configured'] = true;
                break;
            }
        }
        foreach ([
            'discount' => 'assisted.discount_request',
            'refund' => 'assisted.refund_request',
            'exception' => 'assisted.exception_request',
            'emergency' => 'assisted.emergency_request',
        ] as $prefix => $key) {
            if (!isset($map[$key])) continue;
            $out[$prefix . '_rule_enabled'] = (int) ($map[$key]['enabled'] ?? 0);
            $out[$prefix . '_action'] = (string) ($map[$key]['action_key'] ?? $out[$prefix . '_action']);
            $out[$prefix . '_message'] = (string) ($map[$key]['customer_message'] ?? $out[$prefix . '_message']);
        }
        if (isset($map['minimum_age'])) {
            $out['minimum_age'] = (int) ($map['minimum_age']['value'] ?? 0);
            $out['age_action'] = (string) ($map['minimum_age']['action_key'] ?? $out['age_action']);
            $out['minimum_age_message'] = (string) ($map['minimum_age']['customer_message'] ?? $out['minimum_age_message']);
        }
        if (isset($map['assisted.maximum_age'])) {
            $rule = is_array($map['assisted.maximum_age']['value'] ?? null) ? $map['assisted.maximum_age']['value'] : [];
            $condition = is_array($rule['condition'] ?? null) ? $rule['condition'] : [];
            $out['maximum_age'] = (int) ($condition['value'] ?? 0);
            $out['age_action'] = (string) ($map['assisted.maximum_age']['action_key'] ?? $out['age_action']);
            $out['maximum_age_message'] = (string) ($map['assisted.maximum_age']['customer_message'] ?? $out['maximum_age_message']);
        }
        $customRows = [];
        foreach ($map as $key => $row) {
            if (!str_starts_with((string) $key, 'assisted.custom_case.')) continue;
            $rule = is_array($row['value'] ?? null) ? $row['value'] : [];
            $condition = is_array($rule['condition'] ?? null) ? $rule['condition'] : [];
            $display = trim((string) ($rule['display_value'] ?? $condition['value'] ?? ''));
            if ($display !== '') $customRows[] = $display;
            $out['custom_case_action'] = (string) ($row['action_key'] ?? $out['custom_case_action']);
            $out['custom_case_message'] = (string) ($row['customer_message'] ?? $out['custom_case_message']);
        }
        if ($customRows !== []) {
            $out['custom_case_enabled'] = 1;
            $out['custom_case_terms'] = implode("\n", array_values(array_unique($customRows)));
        }
        return $out;
    }

    /** @return array{status:string,message:string} */
    private function agentLimitsStatus(int $tenantId): array
    {
        $settings = $this->agentLimitsSettings($tenantId);
        if (empty($settings['configured'])) {
            return ['status' => 'pending', 'message' => 'Revise o que o assistente pode ou não decidir sozinho e configure os casos que exigem resposta controlada ou encaminhamento.'];
        }
        return ['status' => 'complete', 'message' => 'Limites de autoridade e elegibilidade estão configurados no Policy Engine.'];
    }

    /** @param list<string> $selectedFields */
    private function syncAssistedTriageAndWorkflow(int $tenantId, array $selectedFields, bool $agendaEnabled): void
    {
        if (!$this->tableExists('tenant_triage_fields')) {
            if ($selectedFields !== []) {
                throw new \RuntimeException('A estrutura de perguntas do assistente ainda não foi preparada para esta empresa.');
            }
            return;
        }

        $stmt = $this->pdo->prepare('SELECT field_key FROM tenant_triage_fields WHERE tenant_id = :tenant_id ORDER BY position, id');
        $stmt->execute(['tenant_id' => $tenantId]);
        $known = array_values(array_filter(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [])));
        $selectedFields = array_values(array_intersect($known, $selectedFields));

        $update = $this->pdo->prepare(
            'UPDATE tenant_triage_fields
             SET active = :active,
                 required_before_schedule = :required_before_schedule,
                 required_for_completion = :required_for_completion,
                 source = CASE WHEN source = "blueprint" THEN "tenant" ELSE source END
             WHERE tenant_id = :tenant_id AND field_key = :field_key'
        );
        foreach ($known as $fieldKey) {
            $active = in_array($fieldKey, $selectedFields, true);
            $update->execute([
                'active' => $active ? 1 : 0,
                'required_before_schedule' => $active && $agendaEnabled ? 1 : 0,
                'required_for_completion' => $active ? 1 : 0,
                'tenant_id' => $tenantId,
                'field_key' => $fieldKey,
            ]);
        }

        if (!$this->tableExists('tenant_agent_workflow_steps')) {
            return;
        }

        $workflowStmt = $this->pdo->prepare(
            'SELECT id, step_key, step_type, config_json, position
             FROM tenant_agent_workflow_steps
             WHERE tenant_id = :tenant_id
             ORDER BY position, id'
        );
        $workflowStmt->execute(['tenant_id' => $tenantId]);
        $workflow = $workflowStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $collectRows = array_values(array_filter($workflow, static fn (array $row): bool => ($row['step_type'] ?? '') === 'collect'));

        if ($collectRows === [] && $selectedFields !== []) {
            $firstActionPosition = 20;
            foreach ($workflow as $row) {
                if (($row['step_type'] ?? '') === 'action') {
                    $firstActionPosition = max(20, (int) ($row['position'] ?? 20));
                    break;
                }
            }
            $this->pdo->prepare(
                'INSERT INTO tenant_agent_workflow_steps
                    (tenant_id, step_key, label, step_type, config_json, active, position, source)
                 VALUES
                    (:tenant_id, "assisted_intake", "Informações iniciais", "collect", :config_json, 1, :position, "tenant")'
            )->execute([
                'tenant_id' => $tenantId,
                'config_json' => json_encode(['field_keys' => $selectedFields], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'position' => max(1, $firstActionPosition - 10),
            ]);
        } elseif ($collectRows !== []) {
            foreach ($collectRows as $index => $row) {
                $config = json_decode((string) ($row['config_json'] ?? ''), true);
                $config = is_array($config) ? $config : [];
                $config['field_keys'] = $index === 0 ? $selectedFields : [];
                unset($config['field_key']);
                $this->pdo->prepare(
                    'UPDATE tenant_agent_workflow_steps
                     SET config_json = :config_json, active = :active, source = "tenant"
                     WHERE id = :id AND tenant_id = :tenant_id'
                )->execute([
                    'config_json' => json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'active' => $index === 0 && $selectedFields !== [] ? 1 : 0,
                    'id' => (int) $row['id'],
                    'tenant_id' => $tenantId,
                ]);
            }
        }

        // A ação de agenda continua cadastrada, mas só participa do fluxo quando a
        // empresa realmente habilitou agendamento. Isso evita etapas fantasmas.
        foreach ($workflow as $row) {
            if (($row['step_type'] ?? '') !== 'action') continue;
            $config = json_decode((string) ($row['config_json'] ?? ''), true);
            $actionKey = is_array($config) ? trim((string) ($config['action_key'] ?? '')) : '';
            if ($actionKey === 'calendar.pre_schedule' || str_starts_with((string) ($row['step_key'] ?? ''), 'calendar')) {
                $this->pdo->prepare('UPDATE tenant_agent_workflow_steps SET active = :active WHERE id = :id AND tenant_id = :tenant_id')
                    ->execute(['active' => $agendaEnabled ? 1 : 0, 'id' => (int) $row['id'], 'tenant_id' => $tenantId]);
            }
        }
    }

    /** @param list<string> $selectedFields */
    private function syncAssistedCapabilities(int $tenantId, bool $agendaEnabled, bool $humanApproval, bool $triageEnabled, bool $ageRestricted): void
    {
        if (!$this->tableExists('tenant_agent_capabilities')) {
            return;
        }
        $capabilities = [
            'triage.enabled' => $triageEnabled,
            'eligibility.enabled' => $ageRestricted,
            'policy.fail_closed' => true,
            'calendar.read' => $agendaEnabled,
            'calendar.pre_schedule' => $agendaEnabled,
            'calendar.confirm' => $agendaEnabled && !$humanApproval,
            'calendar.human_approval' => $agendaEnabled && $humanApproval,
        ];
        foreach ($capabilities as $key => $enabled) {
            $this->pdo->prepare(
                'DELETE FROM tenant_agent_capabilities
                 WHERE tenant_id = :tenant_id AND agent_id IS NULL AND capability_key = :capability_key'
            )->execute(['tenant_id' => $tenantId, 'capability_key' => $key]);
            $this->pdo->prepare(
                'INSERT INTO tenant_agent_capabilities
                    (tenant_id, agent_id, capability_key, enabled, source)
                 VALUES (:tenant_id, NULL, :capability_key, :enabled, "tenant")'
            )->execute([
                'tenant_id' => $tenantId,
                'capability_key' => $key,
                'enabled' => $enabled ? 1 : 0,
            ]);
        }
    }

    private function normalizeLimitAction(string $action, array $allowed): string
    {
        $action = strtolower(trim($action));
        return in_array($action, $allowed, true) ? $action : (string) ($allowed[0] ?? 'block');
    }

    private function normalizeLimitTerm(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = strtr($value, [
            'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a',
            'é' => 'e', 'ê' => 'e', 'í' => 'i',
            'ó' => 'o', 'ô' => 'o', 'õ' => 'o',
            'ú' => 'u', 'ü' => 'u', 'ç' => 'c',
        ]);
        return trim((string) preg_replace('/[^a-z0-9]+/u', ' ', $value));
    }

    private function upsertAssistedPolicy(
        int $tenantId,
        string $key,
        string $type,
        mixed $value,
        string $action,
        string $message,
        bool $enabled,
        int $priority
    ): void {
        $this->pdo->prepare(
            'INSERT INTO tenant_agent_policies
                (tenant_id, policy_key, policy_type, value_json, action_key, customer_message, enabled, priority, source)
             VALUES
                (:tenant_id, :policy_key, :policy_type, :value_json, :action_key, :customer_message, :enabled, :priority, "tenant")
             ON DUPLICATE KEY UPDATE
                policy_type = VALUES(policy_type),
                value_json = VALUES(value_json),
                action_key = VALUES(action_key),
                customer_message = VALUES(customer_message),
                enabled = VALUES(enabled),
                priority = VALUES(priority),
                source = "tenant",
                updated_at = NOW()'
        )->execute([
            'tenant_id' => $tenantId,
            'policy_key' => mb_substr($key, 0, 120),
            'policy_type' => $type,
            'value_json' => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'action_key' => mb_substr($action, 0, 120),
            'customer_message' => $message !== '' ? $message : null,
            'enabled' => $enabled ? 1 : 0,
            'priority' => max(1, $priority),
        ]);
    }

    private function decodeJsonValue(mixed $raw): mixed
    {
        if (is_array($raw) || is_bool($raw) || is_int($raw) || is_float($raw)) {
            return $raw;
        }
        $decoded = json_decode((string) $raw, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
    }

    public function finish(int $tenantId, string $notes, ?int $userId): void
    {
        $this->saveStep($tenantId, 'final_test', 'complete', $notes !== '' ? $notes : 'Teste final validado pelo cliente/RS.', $userId);
        $this->pdo->prepare('UPDATE tenants SET onboarding_step = 8, onboarding_completed_at = COALESCE(onboarding_completed_at, NOW()) WHERE id = :id')
            ->execute(['id' => $tenantId]);
        $this->recordEvent($tenantId, $userId, 'onboarding.completed', 'Onboarding guiado concluído.', ['notes' => $notes]);
    }

    private function refreshTenantProgress(int $tenantId): void
    {
        $dashboard = $this->dashboard($tenantId);
        $steps = $dashboard['steps'];
        $summary = $dashboard['summary'];
        $current = 1;
        foreach ($steps as $step) {
            if (in_array($step['status'], ['complete', 'skipped'], true)) {
                $current = max($current, (int) $step['index'] + 1);
            } else {
                break;
            }
        }
        $completeSql = ((bool) ($summary['is_complete'] ?? false)) ? ', onboarding_completed_at = COALESCE(onboarding_completed_at, NOW())' : '';
        $statement = $this->pdo->prepare('UPDATE tenants SET onboarding_step = :step' . $completeSql . ' WHERE id = :id');
        $statement->execute(['step' => min(8, $current), 'id' => $tenantId]);
    }

    /** @return array<int, array<string, mixed>> */
    public function definitions(): array
    {
        // Compatibilidade histórica de linguagem dos Primeiros passos: "Criar o assistente virtual".
        return [
            ['key' => 'company_profile', 'title' => 'Cadastro da empresa', 'short' => 'Cadastro', 'subtitle' => 'Etapa 1', 'description' => 'Confira a identificação da empresa e complete os dados operacionais de contato.', 'action_label' => 'Revisar dados', 'action_url' => '/onboarding', 'icon' => 'company'],
            ['key' => 'lgpd_acceptance', 'title' => 'Privacidade e termos', 'short' => 'Privacidade', 'subtitle' => 'Etapa 2', 'description' => 'Leia os termos e registre o aceite vinculado ao usuário e à versão vigente da política.', 'action_label' => 'Ler e aceitar', 'action_url' => '/privacy/accept', 'icon' => 'privacy'],
            ['key' => 'attendance_rules', 'title' => 'Como será o atendimento', 'short' => 'Atendimento', 'subtitle' => 'Etapa 3', 'description' => 'Defina horários, tempo de espera, mensagem fora do expediente e transferência para humano antes de criar o agente.', 'action_label' => 'Configurar atendimento', 'action_url' => '#attendance-rules', 'icon' => 'support'],
            ['key' => 'agenda_setup', 'title' => 'Agenda', 'short' => 'Agenda', 'subtitle' => 'Etapa 4', 'description' => 'Escolha se a empresa usará agenda interna, integração configurada ou se esta etapa não se aplica.', 'action_label' => 'Configurar agenda', 'action_url' => '#agenda-setup', 'icon' => 'calendar'],
            ['key' => 'whatsapp_connection', 'title' => 'Conectar o WhatsApp', 'short' => 'WhatsApp', 'subtitle' => 'Etapa 5', 'description' => 'Conecte o número de atendimento e confirme que ele está pronto antes de criar o assistente.', 'action_label' => 'Abrir conexões', 'action_url' => '/instances', 'icon' => 'whatsapp'],
            ['key' => 'ai_agent', 'title' => 'Preparar o assistente virtual', 'short' => 'Assistente', 'subtitle' => 'Etapa 6', 'description' => 'Confirme o nome, o tom da conversa e qual WhatsApp será usado. As regras e o fluxo já vêm das etapas anteriores.', 'action_label' => 'Preparar assistente', 'action_url' => '/onboarding?step=ai_agent', 'icon' => 'ai'],
            ['key' => 'agent_limits', 'title' => 'Limites e condutas do assistente', 'short' => 'Limites', 'subtitle' => 'Etapa 7', 'description' => 'Defina o que o assistente não pode decidir sozinho, quando deve interromper uma ação e como responder ou encaminhar casos especiais.', 'action_label' => 'Definir limites', 'action_url' => '#agent-limits', 'icon' => 'shield'],
            ['key' => 'final_test', 'title' => 'Teste final', 'short' => 'Teste', 'subtitle' => 'Etapa 8', 'description' => 'Valide uma conversa real, os limites configurados, pausa humana, horário e agenda quando aplicável antes de liberar o painel completo.', 'action_label' => 'Executar teste', 'action_url' => '/conversations', 'icon' => 'check'],
        ];
    }

    /** @return array<string, mixed>|null */
    private function definition(string $key): ?array
    {
        foreach ($this->definitions() as $definition) {
            if ($definition['key'] === $key) {
                return $definition;
            }
        }
        return null;
    }

    /** @return array<string, mixed> */
    private function autoStatus(int $tenantId, string $key): array
    {
        return match ($key) {
            'company_profile' => $this->companyStatus($tenantId),
            'whatsapp_connection' => $this->whatsappStatus($tenantId),
            'ai_agent' => $this->aiStatus($tenantId),
            'agent_limits' => $this->agentLimitsStatus($tenantId),
            'attendance_rules' => $this->attendanceStatus($tenantId),
            'agenda_setup' => $this->agendaStatus($tenantId),
            'lgpd_acceptance' => $this->lgpdStatus($tenantId),
            'final_test' => $this->finalTestStatus($tenantId),
            default => ['status' => 'pending', 'message' => 'Aguardando configuração.'],
        };
    }

    /** @param array<int, array<string, mixed>> $previousSteps */
    private function blockedBy(string $key, array $previousSteps): ?string
    {
        $requirements = [
            'lgpd_acceptance' => ['company_profile'],
            'attendance_rules' => ['lgpd_acceptance'],
            'agenda_setup' => ['attendance_rules'],
            'whatsapp_connection' => ['agenda_setup'],
            'ai_agent' => ['whatsapp_connection'],
            'agent_limits' => ['ai_agent'],
            'final_test' => ['agent_limits'],
        ];
        foreach ($requirements[$key] ?? [] as $requiredKey) {
            foreach ($previousSteps as $step) {
                if ($step['key'] === $requiredKey && !in_array($step['status'], ['complete', 'skipped'], true)) {
                    return (string) $step['title'];
                }
            }
        }
        return null;
    }

    /** @return array<string, mixed> */
    private function companyStatus(int $tenantId): array
    {
        $tenant = $this->tenant($tenantId);
        $missing = [];
        if (trim((string) ($tenant['name'] ?? '')) === '') {
            $missing[] = 'nome';
        }
        if ((int) ($tenant['business_niche_id'] ?? 0) < 1) {
            $missing[] = 'segmento';
        }
        if ((int) ($tenant['agent_blueprint_id'] ?? 0) < 1) {
            $missing[] = 'modelo inicial do segmento';
        }
        if (trim((string) ($tenant['email'] ?? '')) === '' && trim((string) ($tenant['phone'] ?? '')) === '') {
            $missing[] = 'e-mail ou telefone';
        }
        if (!$missing) {
            if ((int) ($tenant['onboarding_step'] ?? 1) <= 1) {
                return ['status' => 'pending', 'message' => 'Confira os dados oficiais, escolha o segmento e salve para preparar o atendimento.'];
            }
            return ['status' => 'complete', 'message' => 'Cadastro confirmado, segmento definido e modelo inicial preparado.'];
        }
        return ['status' => 'pending', 'message' => 'Falta preencher: ' . implode(', ', $missing) . '.'];
    }

    /** @return array<string, mixed> */
    private function whatsappStatus(int $tenantId): array
    {
        $instances = $this->instances($tenantId);
        if (!$instances) {
            return ['status' => 'pending', 'message' => 'Nenhuma conexão do WhatsApp foi criada.'];
        }
        foreach ($instances as $instance) {
            if (($instance['status'] ?? '') === 'connected') {
                return ['status' => 'complete', 'message' => 'WhatsApp conectado: ' . ($instance['name'] ?? $instance['instance_name'] ?? 'WhatsApp') . '.'];
            }
        }
        return ['status' => 'attention', 'message' => 'A conexão foi criada, mas o WhatsApp ainda precisa ler o QR Code.'];
    }

    /** @return array<string, mixed> */
    private function aiStatus(int $tenantId): array
    {
        $agents = $this->agents($tenantId);
        if (!$agents) {
            return ['status' => 'pending', 'message' => 'Nenhum assistente virtual foi criado.'];
        }
        $active = array_filter($agents, static fn (array $agent): bool => ($agent['status'] ?? '') === 'active');
        if (!$active) {
            return ['status' => 'attention', 'message' => 'O assistente foi criado, mas ainda está desativado.'];
        }
        $credentialOk = $this->aiCredentialOk($tenantId);
        if (!$credentialOk) {
            return ['status' => 'attention', 'message' => 'O assistente está ativo, mas falta revisar a chave de acesso da IA.'];
        }
        return ['status' => 'complete', 'message' => 'O assistente está ativo e a chave de acesso foi encontrada.'];
    }

    /** @return array<string, mixed> */
    private function attendanceStatus(int $tenantId): array
    {
        $settings = $this->onboardingSettings($tenantId);
        if ($settings && !empty($settings['business_hours_json'])) {
            return ['status' => 'complete', 'message' => 'Regras operacionais preparadas para o agente que será criado.'];
        }
        $agent = $this->defaultAgent($tenantId);
        if ($agent && ((int) ($agent['business_hours_enabled'] ?? 0) === 1 || trim((string) ($agent['human_handoff_message'] ?? '')) !== '')) {
            return ['status' => 'complete', 'message' => 'Regras de horário e encaminhamento revisadas.'];
        }
        return ['status' => 'pending', 'message' => 'Defina horário, mensagem fora do expediente e passagem para humano.'];
    }

    /** @return array<string, mixed> */
    private function agendaStatus(int $tenantId): array
    {
        if (!$this->moduleEnabled($tenantId, 'calendar')) {
            return ['status' => 'skipped', 'message' => 'Agenda desativada para esta empresa.'];
        }
        $access = $this->calendarAccessSettings($tenantId);
        $mode = (string) ($access['calendar_mode'] ?? 'none');
        $preSchedule = $this->preScheduleSettings($tenantId);
        $availability = (new CalendarAvailabilityService())->settings($tenantId);

        if ($mode === 'none') {
            if ($preSchedule && (int) ($preSchedule['enabled'] ?? 0) === 1) {
                return ['status' => 'pending', 'message' => 'Escolha Agenda interna, Agenda inteligente ou dispense a etapa.'];
            }
            return ['status' => 'pending', 'message' => 'Escolha como a empresa administrará os agendamentos.'];
        }
        if ($mode === 'internal') {
            $ready = !empty($availability['enabled']) && empty($availability['use_n8n']) && !empty($availability['use_internal_fallback']);
            return $ready
                ? ['status' => 'complete', 'message' => 'Agenda interna ativa. Nenhum fluxo n8n ou Google Calendar será usado.']
                : ['status' => 'attention', 'message' => 'A Agenda interna foi selecionada, mas a disponibilidade ainda precisa ser salva.'];
        }
        if ((string) ($access['smart_calendar_status'] ?? 'locked') !== 'ready') {
            return ['status' => 'attention', 'message' => 'Agenda inteligente selecionada, mas ainda não foi homologada pela equipe RS Connect.'];
        }
        $ready = !empty($availability['enabled']) && !empty($availability['use_n8n']);
        return $ready
            ? ['status' => 'complete', 'message' => 'Agenda inteligente homologada e liberada pela equipe RS Connect.']
            : ['status' => 'attention', 'message' => 'A liberação existe, mas a integração técnica da Agenda inteligente ainda está desativada.'];
    }

    /** @return array<string, mixed> */
    private function lgpdStatus(int $tenantId): array
    {
        $status = $this->privacyStatus($tenantId);
        if (!$status['settings_exists']) {
            return ['status' => 'pending', 'message' => 'Política LGPD ainda não configurada.'];
        }
        if ((int) ($status['require_acceptance'] ?? 0) === 1 && (int) ($status['acceptances'] ?? 0) <= 0) {
            return ['status' => 'pending', 'message' => 'Termos configurados, mas falta aceite do usuário da empresa.'];
        }
        return ['status' => 'complete', 'message' => 'Política/termos revisados e aceite compatível.'];
    }

    /** @return array<string, mixed> */
    private function finalTestStatus(int $tenantId): array
    {
        $messageCount = 0;
        if ($this->tableExists('conversation_messages')) {
            $statement = $this->pdo->prepare(
                'SELECT COUNT(*)
                 FROM conversation_messages cm
                 INNER JOIN conversations c ON c.id = cm.conversation_id
                 WHERE c.tenant_id = :tenant_id
                   AND cm.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)'
            );
            $statement->execute(['tenant_id' => $tenantId]);
            $messageCount = (int) $statement->fetchColumn();
        }
        if ($messageCount >= 2) {
            return ['status' => 'complete', 'message' => 'Conversas recentes encontradas para validação.'];
        }
        return ['status' => 'pending', 'message' => 'Faça um teste real de conversa antes de finalizar.'];
    }

    /** @return array<string, mixed> */
    private function tenant(int $tenantId): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM tenants WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $tenantId]);
        return $statement->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<int, array<string, mixed>> */
    private function instances(int $tenantId): array
    {
        if (!$this->tableExists('evolution_instances')) {
            return [];
        }
        $statement = $this->pdo->prepare('SELECT * FROM evolution_instances WHERE tenant_id = :tenant_id ORDER BY is_default DESC, created_at DESC');
        $statement->execute(['tenant_id' => $tenantId]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<int, array<string, mixed>> */
    private function agents(int $tenantId): array
    {
        if (!$this->tableExists('ai_agents')) {
            return [];
        }
        $statement = $this->pdo->prepare('SELECT * FROM ai_agents WHERE tenant_id = :tenant_id ORDER BY is_default DESC, created_at DESC');
        $statement->execute(['tenant_id' => $tenantId]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string, mixed>|null */
    private function defaultAgent(int $tenantId): ?array
    {
        $agents = $this->agents($tenantId);
        return $agents[0] ?? null;
    }

    /** @return array<string, mixed>|null */
    private function onboardingSettings(int $tenantId): ?array
    {
        $this->ensureOnboardingSettingsTable();
        $statement = $this->pdo->prepare('SELECT * FROM tenant_onboarding_settings WHERE tenant_id = :tenant_id LIMIT 1');
        $statement->execute(['tenant_id' => $tenantId]);
        return $statement->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** @return array<string, mixed>|null */
    private function preScheduleSettings(int $tenantId): ?array
    {
        if (!$this->tableExists('tenant_pre_schedule_settings')) {
            return null;
        }
        $statement = $this->pdo->prepare('SELECT * FROM tenant_pre_schedule_settings WHERE tenant_id = :tenant_id LIMIT 1');
        $statement->execute(['tenant_id' => $tenantId]);
        return $statement->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** @return array<string, mixed> */
    private function privacyStatus(int $tenantId, ?int $userId = null): array
    {
        $result = ['settings_exists' => false, 'require_acceptance' => 0, 'acceptances' => 0, 'user_accepted' => false];
        if (!$this->tableExists('tenant_privacy_settings')) {
            return $result;
        }
        $statement = $this->pdo->prepare('SELECT * FROM tenant_privacy_settings WHERE tenant_id = :tenant_id LIMIT 1');
        $statement->execute(['tenant_id' => $tenantId]);
        $settings = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$settings) {
            return $result;
        }
        $result['settings_exists'] = true;
        $result['require_acceptance'] = (int) ($settings['require_company_acceptance'] ?? 0);
        if ($this->tableExists('tenant_terms_acceptances')) {
            $count = $this->pdo->prepare('SELECT COUNT(*) FROM tenant_terms_acceptances WHERE tenant_id = :tenant_id');
            $count->execute(['tenant_id' => $tenantId]);
            $result['acceptances'] = (int) $count->fetchColumn();
            if ($userId !== null) {
                $user = $this->pdo->prepare('SELECT COUNT(*) FROM tenant_terms_acceptances WHERE tenant_id = :tenant_id AND user_id = :user_id');
                $user->execute(['tenant_id' => $tenantId, 'user_id' => $userId]);
                $result['user_accepted'] = (int) $user->fetchColumn() > 0;
            }
        }
        return $result;
    }

    /** @return array<string, array<string, mixed>> */
    private function manualProgress(int $tenantId): array
    {
        $this->ensureTables();
        $statement = $this->pdo->prepare('SELECT * FROM tenant_onboarding_progress WHERE tenant_id = :tenant_id');
        $statement->execute(['tenant_id' => $tenantId]);
        $rows = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[(string) $row['step_key']] = $row;
        }
        return $rows;
    }

    /** @return array<int, array<string, mixed>> */
    private function events(int $tenantId): array
    {
        $this->ensureTables();
        $statement = $this->pdo->prepare(
            'SELECT e.*, u.name AS user_name
             FROM tenant_onboarding_events e
             LEFT JOIN users u ON u.id = e.user_id
             WHERE e.tenant_id = :tenant_id
             ORDER BY e.created_at DESC, e.id DESC
             LIMIT 12'
        );
        $statement->execute(['tenant_id' => $tenantId]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<int, array<string, string>> */
    private function quickLinks(): array
    {
        return [
            ['label' => 'Conversas', 'url' => '/conversations'],
            ['label' => 'Conexões do WhatsApp', 'url' => '/instances'],
            ['label' => 'Assistentes virtuais', 'url' => '/agents'],
            ['label' => 'Agenda', 'url' => '/calendar'],
            ['label' => 'Privacidade e termos', 'url' => '/privacy'],
            ['label' => 'Minha assinatura', 'url' => '/subscription'],
        ];
    }

    private function aiCredentialOk(int $tenantId): bool
    {
        if ($this->tableExists('ai_provider_credentials')) {
            $statement = $this->pdo->prepare('SELECT COUNT(*) FROM ai_provider_credentials WHERE status = "active" AND (tenant_id = :tenant_id OR tenant_id IS NULL)');
            $statement->execute(['tenant_id' => $tenantId]);
            if ((int) $statement->fetchColumn() > 0) {
                return true;
            }
        }
        return trim((string) ($_ENV['OPENAI_API_KEY'] ?? getenv('OPENAI_API_KEY') ?: '')) !== ''
            || trim((string) ($_ENV['GEMINI_API_KEY'] ?? getenv('GEMINI_API_KEY') ?: '')) !== '';
    }

    private function moduleEnabled(int $tenantId, string $module): bool
    {
        if (!$this->tableExists('tenant_module_settings')) {
            return true;
        }
        $statement = $this->pdo->prepare('SELECT is_enabled FROM tenant_module_settings WHERE tenant_id = :tenant_id AND module_key = :module LIMIT 1');
        $statement->execute(['tenant_id' => $tenantId, 'module' => $module]);
        $value = $statement->fetchColumn();
        return $value === false ? true : ((int) $value === 1);
    }

    private function syncImplementation(int $tenantId, string $stepKey, string $status, string $notes, ?int $userId): void
    {
        $map = [
            'company_profile' => 'company_profile',
            'whatsapp_connection' => 'whatsapp_instance',
            'ai_agent' => 'agent_created',
            'attendance_rules' => 'menus_configured',
            'agenda_setup' => 'pre_schedule',
            'lgpd_acceptance' => 'lgpd_settings',
            'final_test' => 'evolution_test',
        ];
        $implKey = $map[$stepKey] ?? null;
        if ($implKey === null) {
            return;
        }
        $implStatus = in_array($status, ['complete', 'skipped'], true) ? $status : ($status === 'attention' ? 'attention' : 'pending');
        try {
            (new ImplementationChecklistService())->updateItem($tenantId, $implKey, $implStatus, $notes, $userId);
        } catch (Throwable) {
            // A sincronização com implantação é complementar. Não deve bloquear o onboarding do cliente.
        }
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'complete' => 'Concluído',
            'skipped' => 'Dispensado',
            'attention' => 'Atenção',
            'blocked' => 'Bloqueado',
            default => 'Pendente',
        };
    }

    private function statusBadge(string $status): string
    {
        return match ($status) {
            'complete' => 'badge-success',
            'skipped' => 'badge-info',
            'attention', 'blocked' => 'badge-danger',
            default => 'badge-warning',
        };
    }

    /** @param array<string, mixed> $context */
    private function recordEvent(int $tenantId, ?int $userId, string $event, string $message, array $context = []): void
    {
        $this->ensureTables();
        $statement = $this->pdo->prepare(
            'INSERT INTO tenant_onboarding_events (tenant_id, user_id, event, message, context_json)
             VALUES (:tenant_id, :user_id, :event, :message, :context_json)'
        );
        $statement->execute([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'event' => $event,
            'message' => $message,
            'context_json' => $context ? json_encode($context, JSON_UNESCAPED_UNICODE) : null,
        ]);
    }

    private function ensureTables(): void
    {
        // DDL dentro de uma transacao MySQL faz COMMIT implicito mesmo quando
        // a tabela ja existe. Nao executar CREATE TABLE desnecessariamente.
        if (!$this->tableExists('tenant_onboarding_progress')) {
            $this->assertSchemaChangeAllowed('tenant_onboarding_progress');
            $this->pdo->exec(
                'CREATE TABLE IF NOT EXISTS tenant_onboarding_progress (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                tenant_id BIGINT UNSIGNED NOT NULL,
                step_key VARCHAR(80) COLLATE utf8mb4_unicode_ci NOT NULL,
                status ENUM("auto","pending","complete","skipped","attention") COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT "auto",
                notes TEXT COLLATE utf8mb4_unicode_ci NULL,
                completed_at DATETIME NULL,
                updated_by BIGINT UNSIGNED NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_tenant_onboarding_step (tenant_id, step_key),
                KEY idx_tenant_onboarding_status (tenant_id, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        }
        if (!$this->tableExists('tenant_onboarding_events')) {
            $this->assertSchemaChangeAllowed('tenant_onboarding_events');
            $this->pdo->exec(
                'CREATE TABLE IF NOT EXISTS tenant_onboarding_events (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                tenant_id BIGINT UNSIGNED NOT NULL,
                user_id BIGINT UNSIGNED NULL,
                event VARCHAR(120) COLLATE utf8mb4_unicode_ci NOT NULL,
                message VARCHAR(500) COLLATE utf8mb4_unicode_ci NOT NULL,
                context_json LONGTEXT COLLATE utf8mb4_unicode_ci NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_tenant_onboarding_events (tenant_id, created_at),
                KEY idx_tenant_onboarding_event (event)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        }
    }

    private function ensureOnboardingSettingsTable(): void
    {
        if (!$this->tableExists('tenant_onboarding_settings')) {
            $this->assertSchemaChangeAllowed('tenant_onboarding_settings');
            $this->pdo->exec(
                'CREATE TABLE IF NOT EXISTS tenant_onboarding_settings (
                    tenant_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
                    business_timezone VARCHAR(80) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT "America/Sao_Paulo",
                    business_hours_json JSON NULL,
                    after_hours_message VARCHAR(500) COLLATE utf8mb4_unicode_ci NULL,
                    human_handoff_message VARCHAR(500) COLLATE utf8mb4_unicode_ci NULL,
                    cooldown_seconds SMALLINT UNSIGNED NOT NULL DEFAULT 60,
                    calendar_mode ENUM("none","internal","smart") NOT NULL DEFAULT "none",
                    smart_calendar_status ENUM("locked","configuring","ready") NOT NULL DEFAULT "locked",
                    smart_calendar_released_by BIGINT UNSIGNED NULL,
                    smart_calendar_released_at DATETIME NULL,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        }
        $this->addColumnIfMissing('tenant_onboarding_settings', 'calendar_mode', 'ENUM("none","internal","smart") NOT NULL DEFAULT "none"');
        $this->addColumnIfMissing('tenant_onboarding_settings', 'smart_calendar_status', 'ENUM("locked","configuring","ready") NOT NULL DEFAULT "locked"');
        $this->addColumnIfMissing('tenant_onboarding_settings', 'smart_calendar_released_by', 'BIGINT UNSIGNED NULL');
        $this->addColumnIfMissing('tenant_onboarding_settings', 'smart_calendar_released_at', 'DATETIME NULL');
    }

    private function ensurePreScheduleTable(): void
    {
        if (!$this->tableExists('tenant_pre_schedule_settings')) {
            $this->assertSchemaChangeAllowed('tenant_pre_schedule_settings');
            $this->pdo->exec(
                'CREATE TABLE IF NOT EXISTS tenant_pre_schedule_settings (
                    tenant_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
                    enabled TINYINT(1) NOT NULL DEFAULT 0,
                    require_human_approval TINYINT(1) NOT NULL DEFAULT 1,
                    ai_can_suggest_slots TINYINT(1) NOT NULL DEFAULT 1,
                    ai_can_confirm TINYINT(1) NOT NULL DEFAULT 0,
                    default_duration_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 50,
                    default_message VARCHAR(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
                    collect_message VARCHAR(800) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        }
        $this->addColumnIfMissing('tenant_pre_schedule_settings', 'collect_message', 'VARCHAR(800) COLLATE utf8mb4_unicode_ci DEFAULT NULL');
    }

    private function tableExists(string $table): bool
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table');
        $statement->execute(['table' => $table]);
        return (int) $statement->fetchColumn() > 0;
    }

    private function columnExists(string $table, string $column): bool
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column');
        $statement->execute(['table' => $table, 'column' => $column]);
        return (int) $statement->fetchColumn() > 0;
    }

    private function addColumnIfMissing(string $table, string $column, string $definition): void
    {
        if ($this->tableExists($table) && !$this->columnExists($table, $column)) {
            $this->assertSchemaChangeAllowed($table . '.' . $column);
            $this->pdo->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $definition);
        }
    }

    /**
     * Preflight do salvamento express: DDL so pode ocorrer antes de BEGIN.
     * O checklist de implantacao e complementar e nao deve impedir o atendimento.
     */
    private function prepareBusinessOperationStorage(): void
    {
        $this->ensureTables();
        $this->ensureOnboardingSettingsTable();
        $this->ensurePreScheduleTable();

        try {
            (new ImplementationChecklistService())->prepareStorageForWrite();
        } catch (Throwable $exception) {
            // A etapa de implantacao e opcional, mas jamais pode executar DDL
            // dentro da transacao principal; syncImplementation ja trata falhas.
            error_log('[onboarding] Nao foi possivel preparar checklist: ' . $exception->getMessage());
        }
    }

    private function assertSchemaChangeAllowed(string $object): void
    {
        if ($this->pdo->inTransaction()) {
            throw new \RuntimeException(
                'Estrutura de banco pendente (' . $object . '). Aplique as migrations antes de salvar a configuração do atendimento.'
            );
        }
    }
}
