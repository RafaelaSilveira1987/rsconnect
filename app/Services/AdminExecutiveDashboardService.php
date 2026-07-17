<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use Throwable;

final class AdminExecutiveDashboardService
{
    private const VERSION = '32.1.3-view-binding';

    private PDO $pdo;
    /** @var array<int,string> */
    private array $warnings = [];

    public function __construct()
    {
        $this->pdo = Database::connection();
    }

    /** @return array<string,mixed> */
    public function dashboard(): array
    {
        $companies = $this->loadCompanies();

        return [
            'metrics' => $this->loadMetrics(),
            'health_checks' => $this->loadHealthChecks(),
            'attention_companies' => array_slice(array_values(array_filter(
                $companies,
                static fn (array $company): bool => in_array((string) ($company['health'] ?? ''), ['critical', 'attention', 'implantation'], true)
            )), 0, 7),
            'recent_companies' => array_slice($companies, 0, 6),
            'recent_activity' => $this->loadRecentActivity(12),
            'company_summary' => $this->companySummary($companies),
            'data_warnings' => array_values(array_unique($this->warnings)),
            'diagnostic' => [
                'service_version' => self::VERSION,
                'database' => (string) $this->value('SELECT DATABASE()', [], ''),
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function loadMetrics(): array
    {
        $company = $this->row(
            "SELECT COUNT(*) AS total,
                    COALESCE(SUM(status = 'active'), 0) AS active_count,
                    COALESCE(SUM(status = 'inactive'), 0) AS inactive_count,
                    COALESCE(SUM(status = 'suspended'), 0) AS suspended_count
             FROM tenants",
            [],
            'Não foi possível consultar as empresas.'
        );

        $messages24 = (int) $this->value(
            'SELECT COUNT(*) FROM conversation_messages WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)',
            [],
            0,
            'Não foi possível consultar as mensagens das últimas 24 horas.'
        );

        $connected = (int) $this->value(
            "SELECT COUNT(*) FROM evolution_instances WHERE status IN ('connected','open','active','online')",
            [],
            0,
            'Não foi possível consultar as conexões WhatsApp.'
        );

        $unread = (int) $this->value(
            'SELECT COALESCE(SUM(unread_count), 0) FROM conversations',
            [],
            0,
            'Não foi possível consultar as conversas não lidas.'
        );

        $subscription = $this->row(
            "SELECT COUNT(*) AS active_count,
                    COALESCE(SUM(
                        CASE ts.billing_cycle
                            WHEN 'quarterly' THEN ts.amount / 3
                            WHEN 'semiannual' THEN ts.amount / 6
                            WHEN 'annual' THEN ts.amount / 12
                            ELSE ts.amount
                        END
                    ), 0) AS mrr
             FROM tenant_subscriptions ts
             INNER JOIN (
                SELECT tenant_id, MAX(id) AS max_id
                FROM tenant_subscriptions
                GROUP BY tenant_id
             ) latest ON latest.max_id = ts.id
             WHERE ts.billing_status IN ('active','trialing','overdue')",
            [],
            'Não foi possível consultar as assinaturas.'
        );

        $onboarding = (int) $this->value(
            "SELECT COUNT(*)
             FROM tenants
             WHERE status = 'active'
               AND onboarding_completed_at IS NULL",
            [],
            0,
            'Não foi possível consultar as empresas em implantação.'
        );

        $critical = (int) $this->value(
            "SELECT COUNT(*)
             FROM system_incidents
             WHERE resolved_at IS NULL
               AND severity IN ('error','critical')",
            [],
            0
        );

        return [
            'active_companies' => (int) ($company['active_count'] ?? 0),
            'total_companies' => (int) ($company['total'] ?? 0),
            'inactive_companies' => (int) ($company['inactive_count'] ?? 0),
            'suspended_companies' => (int) ($company['suspended_count'] ?? 0),
            'onboarding' => $onboarding,
            'active_subscriptions' => (int) ($subscription['active_count'] ?? 0),
            'mrr' => (float) ($subscription['mrr'] ?? 0),
            'messages_24h' => $messages24,
            'connected_instances' => $connected,
            'critical_incidents' => $critical,
            'unread' => $unread,
            'refreshed_at' => date('Y-m-d H:i:s'),
            'source_version' => self::VERSION,
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private function loadCompanies(): array
    {
        $tenants = $this->all(
            'SELECT id, name, legal_name, email, phone, segment, plan, status, onboarding_completed_at, created_at, updated_at
             FROM tenants
             ORDER BY id DESC',
            [],
            'Não foi possível carregar a lista de empresas.'
        );

        if ($tenants === []) {
            return [];
        }

        $instances = $this->mapByTenant($this->all(
            "SELECT tenant_id,
                    COUNT(*) AS total,
                    COALESCE(SUM(status IN ('connected','open','active','online')), 0) AS connected_count,
                    MAX(updated_at) AS last_update_at
             FROM evolution_instances
             GROUP BY tenant_id"
        ));

        $agents = $this->mapByTenant($this->all(
            "SELECT tenant_id,
                    COUNT(*) AS total,
                    COALESCE(SUM(status = 'active'), 0) AS active_count,
                    MAX(updated_at) AS last_update_at
             FROM ai_agents
             GROUP BY tenant_id"
        ));

        $conversations = $this->mapByTenant($this->all(
            "SELECT tenant_id,
                    COUNT(*) AS total,
                    COALESCE(SUM(unread_count), 0) AS unread_count,
                    MAX(updated_at) AS last_update_at
             FROM conversations
             GROUP BY tenant_id"
        ));

        $messages = $this->mapByTenant($this->all(
            "SELECT tenant_id,
                    COUNT(*) AS total,
                    MAX(created_at) AS last_message_at
             FROM conversation_messages
             GROUP BY tenant_id"
        ));

        $tracking = $this->mapByTenant($this->all(
            'SELECT tenant_id, tracking_status, priority, note, acknowledged_at, resolved_at
             FROM tenant_admin_tracking'
        ));

        $tenantHealth = $this->mapByTenant($this->all(
            'SELECT hs.tenant_id, hs.overall_status, hs.score, hs.warning_count, hs.critical_count, hs.checked_at
             FROM tenant_health_snapshots hs
             INNER JOIN (
                SELECT tenant_id, MAX(id) AS max_id
                FROM tenant_health_snapshots
                GROUP BY tenant_id
             ) latest ON latest.max_id = hs.id'
        ));

        $tenantHealthIncidents = $this->mapByTenant($this->all(
            'SELECT tenant_id,
                    SUM(status <> "resolved") AS open_count,
                    SUM(status <> "resolved" AND severity = "critical") AS critical_count,
                    MAX(CASE WHEN status <> "resolved" THEN summary END) AS latest_summary
             FROM tenant_health_incidents
             GROUP BY tenant_id'
        ));

        $subscriptions = $this->mapByTenant($this->all(
            "SELECT ts.tenant_id, ts.billing_status, ts.amount, ts.billing_cycle, ts.updated_at
             FROM tenant_subscriptions ts
             INNER JOIN (
                SELECT tenant_id, MAX(id) AS max_id
                FROM tenant_subscriptions
                GROUP BY tenant_id
             ) latest ON latest.max_id = ts.id"
        ));

        $result = [];
        foreach ($tenants as $tenant) {
            $tenantId = (int) ($tenant['id'] ?? 0);
            $instance = $instances[$tenantId] ?? [];
            $agent = $agents[$tenantId] ?? [];
            $conversation = $conversations[$tenantId] ?? [];
            $message = $messages[$tenantId] ?? [];
            $adminTracking = $tracking[$tenantId] ?? [];
            $subscription = $subscriptions[$tenantId] ?? [];
            $healthSnapshot = $tenantHealth[$tenantId] ?? [];
            $healthIncidents = $tenantHealthIncidents[$tenantId] ?? [];

            $reasons = [];
            $health = 'healthy';
            $label = 'Saudável';

            if ((string) ($tenant['status'] ?? '') === 'inactive') {
                $health = 'inactive';
                $label = 'Inativa';
                $reasons[] = 'Empresa inativada.';
            } elseif ((string) ($tenant['status'] ?? '') === 'suspended') {
                $health = 'critical';
                $label = 'Crítica';
                $reasons[] = 'Empresa suspensa.';
            } else {
                $trackingStatus = (string) ($adminTracking['tracking_status'] ?? 'automatic');
                $snapshotStatus = (string) ($healthSnapshot['overall_status'] ?? '');
                $hasNewCritical = (int) ($healthIncidents['critical_count'] ?? 0) > 0;
                $hasNewAttention = (int) ($healthIncidents['open_count'] ?? 0) > 0;
                if ($trackingStatus === 'attention') {
                    $health = 'attention';
                    $label = 'Atenção';
                    $reasons[] = trim((string) ($adminTracking['note'] ?? '')) ?: 'Acompanhamento manual solicitado.';
                } elseif ($trackingStatus === 'reviewed') {
                    $health = $hasNewCritical ? 'critical' : 'attention';
                    $label = $hasNewCritical ? 'Crítica em acompanhamento' : 'Em acompanhamento';
                    $reasons[] = trim((string) ($adminTracking['note'] ?? '')) ?: 'A equipe RS já está acompanhando.';
                } elseif ($hasNewCritical || in_array($snapshotStatus, ['critical','blocked'], true)) {
                    $health = 'critical';
                    $label = $snapshotStatus === 'blocked' ? 'Bloqueada' : 'Crítica';
                    $reasons[] = trim((string) ($healthIncidents['latest_summary'] ?? '')) ?: 'O diagnóstico encontrou um problema crítico.';
                } elseif ($hasNewAttention || $snapshotStatus === 'attention') {
                    $health = 'attention';
                    $label = 'Atenção';
                    $reasons[] = trim((string) ($healthIncidents['latest_summary'] ?? '')) ?: 'O diagnóstico encontrou um ponto de atenção.';
                } elseif ($trackingStatus === 'resolved') {
                    $health = 'healthy';
                    $label = 'Corrigida';
                } elseif (empty($tenant['onboarding_completed_at'])) {
                    $health = 'implantation';
                    $label = 'Em implantação';
                    $reasons[] = 'Configuração inicial ainda não concluída.';
                } elseif ((int) ($instance['connected_count'] ?? 0) < 1) {
                    $health = 'attention';
                    $label = 'Atenção';
                    $reasons[] = 'Nenhuma conexão WhatsApp operacional.';
                } elseif ((int) ($agent['active_count'] ?? 0) < 1) {
                    $health = 'attention';
                    $label = 'Atenção';
                    $reasons[] = 'Nenhum assistente virtual ativo.';
                } elseif (!in_array((string) ($subscription['billing_status'] ?? ''), ['active', 'trialing', 'overdue'], true)) {
                    $health = 'attention';
                    $label = 'Atenção';
                    $reasons[] = 'Assinatura precisa ser revisada.';
                }
            }

            $lastActivityAt = $this->latestDate([
                $message['last_message_at'] ?? null,
                $conversation['last_update_at'] ?? null,
                $instance['last_update_at'] ?? null,
                $agent['last_update_at'] ?? null,
                $tenant['updated_at'] ?? null,
            ]);

            $tenant['instances'] = $instance;
            $tenant['agents'] = $agent;
            $tenant['conversations'] = $conversation;
            $tenant['subscription'] = $subscription;
            $tenant['admin_tracking'] = $adminTracking;
            $tenant['health_snapshot'] = $healthSnapshot;
            $tenant['health_incidents'] = $healthIncidents;
            $tenant['health'] = $health;
            $tenant['health_label'] = $label;
            $tenant['attention_reasons'] = $reasons;
            $tenant['last_activity_at'] = $lastActivityAt;
            $result[] = $tenant;
        }

        usort($result, static fn (array $a, array $b): int => (int) ($b['id'] ?? 0) <=> (int) ($a['id'] ?? 0));
        return $result;
    }

    /** @return array<int,array<string,mixed>> */
    private function loadHealthChecks(): array
    {
        return $this->all(
            'SELECT h.*
             FROM system_health_checks h
             INNER JOIN (
                SELECT check_key, MAX(id) AS max_id
                FROM system_health_checks
                GROUP BY check_key
             ) latest ON latest.max_id = h.id
             ORDER BY FIELD(h.status, "down", "warning", "ok"), h.label'
        );
    }

    /** @return array<int,array<string,mixed>> */
    private function loadRecentActivity(int $limit): array
    {
        $limit = max(1, min(30, $limit));
        $rows = $this->all(
            'SELECT al.id, al.tenant_id, al.user_id, al.action, al.context_json, al.created_at,
                    t.name AS tenant_name, u.name AS user_name
             FROM audit_logs al
             LEFT JOIN tenants t ON t.id = al.tenant_id
             LEFT JOIN users u ON u.id = al.user_id
             ORDER BY al.id DESC
             LIMIT ' . $limit
        );

        foreach ($rows as &$row) {
            $row['label'] = $this->activityLabel((string) ($row['action'] ?? ''));
            $row['description'] = $this->activityDescription((string) ($row['action'] ?? ''), $this->decodeJson($row['context_json'] ?? null));
        }
        unset($row);

        return $rows;
    }

    /** @param array<int,array<string,mixed>> $companies @return array<string,int> */
    private function companySummary(array $companies): array
    {
        $summary = [
            'total' => count($companies),
            'active' => 0,
            'healthy' => 0,
            'attention' => 0,
            'critical' => 0,
            'implantation' => 0,
            'inactive' => 0,
        ];

        foreach ($companies as $company) {
            if (($company['status'] ?? '') === 'active') {
                $summary['active']++;
            }
            $health = (string) ($company['health'] ?? 'healthy');
            if (array_key_exists($health, $summary)) {
                $summary[$health]++;
            }
        }

        return $summary;
    }

    /** @param array<int,array<string,mixed>> $rows @return array<int,array<string,mixed>> */
    private function mapByTenant(array $rows): array
    {
        $map = [];
        foreach ($rows as $row) {
            $tenantId = (int) ($row['tenant_id'] ?? 0);
            if ($tenantId > 0) {
                $map[$tenantId] = $row;
            }
        }
        return $map;
    }

    /** @param array<int,mixed> $dates */
    private function latestDate(array $dates): ?string
    {
        $best = null;
        $bestTs = 0;
        foreach ($dates as $date) {
            $value = trim((string) ($date ?? ''));
            if ($value === '') {
                continue;
            }
            $ts = strtotime($value) ?: 0;
            if ($ts > $bestTs) {
                $bestTs = $ts;
                $best = $value;
            }
        }
        return $best;
    }

    private function activityLabel(string $action): string
    {
        $labels = [
            'company.created' => 'Empresa cadastrada',
            'company.updated' => 'Dados da empresa atualizados',
            'company.status_updated' => 'Plano ou status atualizado',
            'company.attention_marked' => 'Empresa marcada para atenção',
            'company.attention_reviewed' => 'Acompanhamento iniciado',
            'company.attention_resolved' => 'Pendência marcada como corrigida',
            'company.attention_reset' => 'Acompanhamento voltou ao automático',
            'user.created' => 'Usuário criado',
            'user.updated' => 'Usuário atualizado',
            'evolution.instance_created' => 'Conexão WhatsApp preparada',
            'evolution.instance_updated' => 'Conexão WhatsApp atualizada',
            'evolution.instance_deleted' => 'Conexão WhatsApp removida',
            'agent.created' => 'Assistente virtual criado',
            'agent.status_updated' => 'Status do assistente atualizado',
            'agent.technical_updated' => 'Vínculo técnico do assistente atualizado',
            'agent.prompt_updated' => 'Instruções do assistente atualizadas',
            'billing.subscription_created' => 'Assinatura criada ou atualizada',
            'billing.invoice_created' => 'Cobrança criada',
            'billing.invoice_updated' => 'Cobrança atualizada',
            'calendar.appointment_created' => 'Compromisso criado',
            'calendar.appointment_status_updated' => 'Situação do compromisso atualizada',
            'calendar.appointment_deleted' => 'Compromisso excluído',
            'conversation.bulk_marked_read' => 'Conversas marcadas como lidas',
            'conversation.bulk_deleted' => 'Conversas excluídas',
            'notifications.preferences_updated' => 'Preferências de notificação atualizadas',
            'privacy.settings_updated' => 'Configurações de privacidade atualizadas',
            'crm.lead_created' => 'Oportunidade criada no CRM',
            'crm.lead_moved' => 'Oportunidade movida no CRM',
            'crm.lead_updated' => 'Oportunidade atualizada no CRM',
        ];

        if (isset($labels[$action])) {
            return $labels[$action];
        }
        $label = trim(str_replace(['.', '_'], ' ', $action));
        return $label !== '' ? ucfirst($label) : 'Atividade registrada';
    }

    /** @param array<string,mixed> $context */
    private function activityDescription(string $action, array $context): string
    {
        $note = trim((string) ($context['note'] ?? ''));
        if ($note !== '') {
            return $note;
        }

        return match ($action) {
            'company.created' => !empty($context['owner_email']) ? 'Primeiro acesso: ' . (string) $context['owner_email'] : 'Novo cliente incluído na base.',
            'company.status_updated' => 'Status: ' . (string) ($context['status'] ?? 'atualizado') . ' · Plano: ' . (string) ($context['plan'] ?? 'mantido'),
            'company.attention_resolved' => 'A pendência foi revisada e corrigida.',
            'company.attention_reviewed' => 'A empresa está em acompanhamento pela equipe RS.',
            'company.attention_marked' => 'Novo ponto de atenção incluído.',
            'evolution.instance_updated' => 'A conexão WhatsApp foi atualizada.',
            'agent.prompt_updated' => 'As instruções usadas nas respostas foram alteradas.',
            default => '',
        };
    }

    /** @return array<string,mixed> */
    private function decodeJson(mixed $value): array
    {
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string,mixed> $params @return array<int,array<string,mixed>> */
    private function all(string $sql, array $params = [], ?string $friendlyWarning = null): array
    {
        try {
            $statement = $this->pdo->prepare($sql);
            $statement->execute($params);
            return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $exception) {
            $this->recordFailure($friendlyWarning, $exception);
            return [];
        }
    }

    /** @param array<string,mixed> $params @return array<string,mixed> */
    private function row(string $sql, array $params = [], ?string $friendlyWarning = null): array
    {
        $rows = $this->all($sql, $params, $friendlyWarning);
        return $rows[0] ?? [];
    }

    /** @param array<string,mixed> $params */
    private function value(string $sql, array $params = [], mixed $default = 0, ?string $friendlyWarning = null): mixed
    {
        try {
            $statement = $this->pdo->prepare($sql);
            $statement->execute($params);
            $value = $statement->fetchColumn();
            return $value === false ? $default : $value;
        } catch (Throwable $exception) {
            $this->recordFailure($friendlyWarning, $exception);
            return $default;
        }
    }

    private function recordFailure(?string $friendlyWarning, Throwable $exception): void
    {
        if ($friendlyWarning !== null && $friendlyWarning !== '') {
            $this->warnings[] = $friendlyWarning;
        }
        error_log('[AdminExecutiveDashboardService ' . self::VERSION . '] ' . $exception->getMessage());
    }
}
