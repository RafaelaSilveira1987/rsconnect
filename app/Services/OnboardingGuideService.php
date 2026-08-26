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
                $status = $manualStatus;
                if ($notes !== '') {
                    $message = $notes;
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
            'calendar_access' => $this->calendarAccessSettings($tenantId),
            'calendar_availability' => (new CalendarAvailabilityService())->settings($tenantId),
            'pre_schedule' => $this->preScheduleSettings($tenantId),
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
        return match ($step) {
            'lgpd_acceptance' => in_array($path, ['/privacy/accept'], true),
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
                    handoff_action = "pause_ai",
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
        $confirm = (string) ($data['ai_can_confirm'] ?? '') === '1' ? 1 : 0;
        $duration = max(15, min(240, (int) ($data['default_duration_minutes'] ?? 60)));
        $collect = mb_substr(trim((string) ($data['collect_message'] ?? 'Certo. Me informe, por favor, o melhor dia e período ou horário para atendimento.')), 0, 800);
        $registered = mb_substr(trim((string) ($data['default_message'] ?? 'Vou registrar sua preferência e encaminhar para confirmação.')), 0, 500);

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

    public function finish(int $tenantId, string $notes, ?int $userId): void
    {
        $this->saveStep($tenantId, 'final_test', 'complete', $notes !== '' ? $notes : 'Teste final validado pelo cliente/RS.', $userId);
        $this->pdo->prepare('UPDATE tenants SET onboarding_step = 7, onboarding_completed_at = COALESCE(onboarding_completed_at, NOW()) WHERE id = :id')
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
        $statement->execute(['step' => min(7, $current), 'id' => $tenantId]);
    }

    /** @return array<int, array<string, mixed>> */
    public function definitions(): array
    {
        return [
            ['key' => 'company_profile', 'title' => 'Cadastro da empresa', 'short' => 'Cadastro', 'subtitle' => 'Etapa 1', 'description' => 'Confira a identificação da empresa e complete os dados operacionais de contato.', 'action_label' => 'Revisar dados', 'action_url' => '/onboarding', 'icon' => 'company'],
            ['key' => 'lgpd_acceptance', 'title' => 'Privacidade e termos', 'short' => 'Privacidade', 'subtitle' => 'Etapa 2', 'description' => 'Leia os termos e registre o aceite vinculado ao usuário e à versão vigente da política.', 'action_label' => 'Ler e aceitar', 'action_url' => '/privacy/accept', 'icon' => 'privacy'],
            ['key' => 'attendance_rules', 'title' => 'Como será o atendimento', 'short' => 'Atendimento', 'subtitle' => 'Etapa 3', 'description' => 'Defina horários, tempo de espera, mensagem fora do expediente e transferência para humano antes de criar o agente.', 'action_label' => 'Configurar atendimento', 'action_url' => '#attendance-rules', 'icon' => 'support'],
            ['key' => 'agenda_setup', 'title' => 'Agenda', 'short' => 'Agenda', 'subtitle' => 'Etapa 4', 'description' => 'Escolha se a empresa usará agenda interna, integração configurada ou se esta etapa não se aplica.', 'action_label' => 'Configurar agenda', 'action_url' => '#agenda-setup', 'icon' => 'calendar'],
            ['key' => 'whatsapp_connection', 'title' => 'Conectar o WhatsApp', 'short' => 'WhatsApp', 'subtitle' => 'Etapa 5', 'description' => 'Conecte o número de atendimento e confirme que ele está pronto antes de criar o assistente.', 'action_label' => 'Abrir conexões', 'action_url' => '/instances', 'icon' => 'whatsapp'],
            ['key' => 'ai_agent', 'title' => 'Criar o assistente virtual', 'short' => 'Assistente', 'subtitle' => 'Etapa 6', 'description' => 'Escreva instruções claras, escolha em qual número o assistente atuará e use as regras definidas nas etapas anteriores.', 'action_label' => 'Abrir assistentes', 'action_url' => '/agents', 'icon' => 'ai'],
            ['key' => 'final_test', 'title' => 'Teste final', 'short' => 'Teste', 'subtitle' => 'Etapa 7', 'description' => 'Valide uma conversa real, pausa humana, horário e agenda quando aplicável antes de liberar o painel completo.', 'action_label' => 'Executar teste', 'action_url' => '/conversations', 'icon' => 'check'],
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
            'final_test' => ['ai_agent'],
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
        foreach (['name' => 'nome', 'segment' => 'segmento'] as $field => $label) {
            if (trim((string) ($tenant[$field] ?? '')) === '') {
                $missing[] = $label;
            }
        }
        if (trim((string) ($tenant['email'] ?? '')) === '' && trim((string) ($tenant['phone'] ?? '')) === '') {
            $missing[] = 'e-mail ou telefone';
        }
        if (!$missing) {
            if ((int) ($tenant['onboarding_step'] ?? 1) <= 1) {
                return ['status' => 'pending', 'message' => 'Confira os dados preparados pela RS e clique em Salvar empresa para confirmar o cadastro.'];
            }
            return ['status' => 'complete', 'message' => 'Dados principais revisados e confirmados.'];
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

    private function ensureOnboardingSettingsTable(): void
    {
        if (!$this->tableExists('tenant_onboarding_settings')) {
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
            $this->pdo->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $definition);
        }
    }
}
