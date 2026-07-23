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
            'pre_schedule' => $this->preScheduleSettings($tenantId),
            'privacy' => $this->privacyStatus($tenantId, $userId),
            'events' => $this->events($tenantId),
            'quick_links' => $this->quickLinks(),
        ];
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
        $agentId = (int) ($data['agent_id'] ?? 0);
        if ($agentId <= 0) {
            $agent = $this->defaultAgent($tenantId);
            $agentId = (int) ($agent['id'] ?? 0);
        }
        if ($agentId <= 0) {
            throw new \RuntimeException('Crie um agente IA antes de configurar o atendimento.');
        }

        $start = trim((string) ($data['start_time'] ?? '08:00')) ?: '08:00';
        $end = trim((string) ($data['end_time'] ?? '18:00')) ?: '18:00';
        $days = $data['days'] ?? ['mon', 'tue', 'wed', 'thu', 'fri'];
        if (!is_array($days) || !$days) {
            $days = ['mon', 'tue', 'wed', 'thu', 'fri'];
        }
        $allowedDays = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
        $days = array_values(array_intersect($allowedDays, array_map('strval', $days)));
        if (!$days) {
            $days = ['mon', 'tue', 'wed', 'thu', 'fri'];
        }

        $hoursJson = json_encode([
            'days' => $days,
            'start' => $start,
            'end' => $end,
        ], JSON_UNESCAPED_UNICODE);

        $afterHours = mb_substr(trim((string) ($data['after_hours_message'] ?? 'No momento estamos fora do horário de atendimento. Assim que possível, nossa equipe retorna o contato.')), 0, 500);
        $handoff = mb_substr(trim((string) ($data['human_handoff_message'] ?? 'Vou encaminhar sua solicitação para uma pessoa da equipe continuar o atendimento.')), 0, 500);
        $cooldown = max(0, min(3600, (int) ($data['cooldown_seconds'] ?? 10)));

        $statement = $this->pdo->prepare(
            'UPDATE ai_agents
             SET business_hours_enabled = 1,
                 business_timezone = "America/Sao_Paulo",
                 business_hours_json = :hours_json,
                 after_hours_message = :after_hours_message,
                 human_handoff_message = :human_handoff_message,
                 handoff_action = "pause_ai",
                 cooldown_seconds = :cooldown_seconds
             WHERE id = :id AND tenant_id = :tenant_id'
        );
        $statement->execute([
            'hours_json' => $hoursJson,
            'after_hours_message' => $afterHours !== '' ? $afterHours : null,
            'human_handoff_message' => $handoff !== '' ? $handoff : null,
            'cooldown_seconds' => $cooldown,
            'id' => $agentId,
            'tenant_id' => $tenantId,
        ]);

        $this->saveStep($tenantId, 'attendance_rules', 'complete', 'Horários, mensagem fora de horário e encaminhamento humano configurados.', $userId);
        $this->recordEvent($tenantId, $userId, 'onboarding.attendance_saved', 'Regras de atendimento configuradas.', ['agent_id' => $agentId]);
    }

    /** @param array<string, mixed> $data */
    public function saveAgenda(int $tenantId, array $data, ?int $userId): void
    {
        $enabled = (string) ($data['enabled'] ?? '') === '1' ? 1 : 0;
        $humanApproval = (string) ($data['require_human_approval'] ?? '') === '1' ? 1 : 0;
        $suggest = (string) ($data['ai_can_suggest_slots'] ?? '') === '1' ? 1 : 0;
        $confirm = (string) ($data['ai_can_confirm'] ?? '') === '1' ? 1 : 0;
        $duration = max(15, min(240, (int) ($data['default_duration_minutes'] ?? 50)));
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

        $this->saveStep($tenantId, 'agenda_setup', $enabled ? 'complete' : 'skipped', $enabled ? 'Agenda e pré-agendamento configurados.' : 'Agenda dispensada para esta operação.', $userId);
        $this->recordEvent($tenantId, $userId, 'onboarding.agenda_saved', 'Configuração de agenda revisada.', ['enabled' => $enabled]);
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
            [
                'key' => 'company_profile',
                'title' => 'Dados da empresa',
                'short' => 'Empresa',
                'subtitle' => 'Identidade, contato e segmento.',
                'description' => 'Confira os dados cadastrais preparados pela equipe RS e complete nome de exibição, e-mail, telefone e site quando necessário.',
                'action_label' => 'Revisar dados',
                'action_url' => '/company-settings',
                'icon' => 'company',
            ],
            [
                'key' => 'whatsapp_connection',
                'title' => 'Conectar WhatsApp',
                'short' => 'WhatsApp',
                'subtitle' => 'Instância Evolution e envio de teste.',
                'description' => 'Cadastre ou valide a instância Evolution, conecte o WhatsApp e confirme que o envio funciona.',
                'action_label' => 'Abrir instâncias',
                'action_url' => '/instances',
                'icon' => 'whatsapp',
            ],
            [
                'key' => 'ai_agent',
                'title' => 'Agente IA',
                'short' => 'IA',
                'subtitle' => 'Assistente, prompt e credencial.',
                'description' => 'Crie ou revise o agente IA da empresa, prompt, modelo e status de resposta automática.',
                'action_label' => 'Abrir agentes',
                'action_url' => '/agents',
                'icon' => 'ai',
            ],
            [
                'key' => 'attendance_rules',
                'title' => 'Atendimento',
                'short' => 'Atendimento',
                'subtitle' => 'Horários, mensagens e passagem para humano.',
                'description' => 'Defina horário de atendimento, mensagem fora de horário, ação de transferência para humano e pausa da IA.',
                'action_label' => 'Configurar atendimento',
                'action_url' => '#attendance-rules',
                'icon' => 'support',
            ],
            [
                'key' => 'agenda_setup',
                'title' => 'Agenda',
                'short' => 'Agenda',
                'subtitle' => 'Agenda e pré-agendamento opcional.',
                'description' => 'Ative ou dispense a agenda. Quando ativo, configure pré-agendamento com aprovação humana.',
                'action_label' => 'Configurar agenda',
                'action_url' => '#agenda-setup',
                'icon' => 'calendar',
            ],
            [
                'key' => 'lgpd_acceptance',
                'title' => 'LGPD e termos',
                'short' => 'LGPD',
                'subtitle' => 'Política, termo e aceite.',
                'description' => 'Revise a política da empresa e registre o aceite obrigatório antes da operação.',
                'action_label' => 'Abrir LGPD',
                'action_url' => '/privacy',
                'icon' => 'privacy',
            ],
            [
                'key' => 'final_test',
                'title' => 'Teste final',
                'short' => 'Teste',
                'subtitle' => 'Validação comercial antes de operar.',
                'description' => 'Confirme envio/recebimento, IA, agenda quando aplicável e pendências principais.',
                'action_label' => 'Abrir conversas',
                'action_url' => '/conversations',
                'icon' => 'check',
            ],
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
            'whatsapp_connection' => ['company_profile'],
            'ai_agent' => ['company_profile', 'whatsapp_connection'],
            'attendance_rules' => ['ai_agent'],
            'agenda_setup' => ['ai_agent'],
            'lgpd_acceptance' => ['company_profile'],
            'final_test' => ['whatsapp_connection', 'ai_agent', 'lgpd_acceptance'],
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
            return ['status' => 'complete', 'message' => 'Dados principais preenchidos.'];
        }
        return ['status' => 'pending', 'message' => 'Falta preencher: ' . implode(', ', $missing) . '.'];
    }

    /** @return array<string, mixed> */
    private function whatsappStatus(int $tenantId): array
    {
        $instances = $this->instances($tenantId);
        if (!$instances) {
            return ['status' => 'pending', 'message' => 'Nenhuma instância WhatsApp cadastrada.'];
        }
        foreach ($instances as $instance) {
            if (($instance['status'] ?? '') === 'connected') {
                return ['status' => 'complete', 'message' => 'Instância conectada: ' . ($instance['name'] ?? $instance['instance_name'] ?? 'WhatsApp') . '.'];
            }
        }
        return ['status' => 'attention', 'message' => 'Instância criada, mas ainda não está conectada.'];
    }

    /** @return array<string, mixed> */
    private function aiStatus(int $tenantId): array
    {
        $agents = $this->agents($tenantId);
        if (!$agents) {
            return ['status' => 'pending', 'message' => 'Nenhum agente IA criado.'];
        }
        $active = array_filter($agents, static fn (array $agent): bool => ($agent['status'] ?? '') === 'active');
        if (!$active) {
            return ['status' => 'attention', 'message' => 'Agente existe, mas não está ativo.'];
        }
        $credentialOk = $this->aiCredentialOk($tenantId);
        if (!$credentialOk) {
            return ['status' => 'attention', 'message' => 'Agente ativo, mas revise a credencial de IA.'];
        }
        return ['status' => 'complete', 'message' => 'Agente ativo e credencial de IA encontrada.'];
    }

    /** @return array<string, mixed> */
    private function attendanceStatus(int $tenantId): array
    {
        $agent = $this->defaultAgent($tenantId);
        if (!$agent) {
            return ['status' => 'pending', 'message' => 'Crie o agente IA antes de configurar atendimento.'];
        }
        if ((int) ($agent['business_hours_enabled'] ?? 0) === 1 || trim((string) ($agent['human_handoff_message'] ?? '')) !== '') {
            return ['status' => 'complete', 'message' => 'Regras de horário e encaminhamento revisadas.'];
        }
        return ['status' => 'pending', 'message' => 'Defina horário, mensagem fora de horário e passagem para humano.'];
    }

    /** @return array<string, mixed> */
    private function agendaStatus(int $tenantId): array
    {
        if (!$this->moduleEnabled($tenantId, 'calendar')) {
            return ['status' => 'skipped', 'message' => 'Agenda desativada para esta empresa.'];
        }
        $settings = $this->preScheduleSettings($tenantId);
        if ($settings && (int) ($settings['enabled'] ?? 0) === 1) {
            return ['status' => 'complete', 'message' => 'Agenda ativa com pré-agendamento configurado.'];
        }
        return ['status' => 'pending', 'message' => 'Agenda disponível. Configure pré-agendamento ou dispense a etapa.'];
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
            ['label' => 'Conexões WhatsApp', 'url' => '/instances'],
            ['label' => 'Agentes IA', 'url' => '/agents'],
            ['label' => 'Agenda', 'url' => '/calendar'],
            ['label' => 'Privacidade/LGPD', 'url' => '/privacy'],
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
