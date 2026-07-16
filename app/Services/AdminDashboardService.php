<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use Throwable;

final class AdminDashboardService
{
    private PDO $pdo;
    /** @var array<string,bool> */
    private array $tableCache = [];
    /** @var array<string,bool> */
    private array $columnCache = [];
    /** @var array<int,string> */
    private array $queryErrors = [];

    public function __construct()
    {
        $this->pdo = Database::connection();
    }

    public function dashboard(): array
    {
        $collection = $this->companies([]);
        $companies = $collection['all_companies'];
        $metrics = $this->dashboardMetrics($companies);

        $attention = array_values(array_filter(
            $companies,
            static fn (array $company): bool => in_array((string) ($company['health'] ?? ''), ['critical', 'attention', 'implantation'], true)
        ));
        usort($attention, static function (array $left, array $right): int {
            $weight = ['critical' => 0, 'attention' => 1, 'implantation' => 2, 'inactive' => 3, 'healthy' => 4];
            $leftWeight = $weight[(string) ($left['health'] ?? 'healthy')] ?? 9;
            $rightWeight = $weight[(string) ($right['health'] ?? 'healthy')] ?? 9;
            if ($leftWeight !== $rightWeight) {
                return $leftWeight <=> $rightWeight;
            }
            return strcmp((string) ($right['last_activity_at'] ?? ''), (string) ($left['last_activity_at'] ?? ''));
        });

        $recentCompanies = $companies;
        usort($recentCompanies, static function (array $left, array $right): int {
            $leftId = (int) ($left['id'] ?? 0);
            $rightId = (int) ($right['id'] ?? 0);
            return $rightId <=> $leftId;
        });

        return [
            'metrics' => $metrics,
            'health_checks' => $this->latestHealthChecks(),
            'attention_companies' => array_slice($attention, 0, 7),
            'recent_companies' => array_slice($recentCompanies, 0, 6),
            'recent_activity' => $this->recentActivity(12),
            'company_summary' => $collection['summary'],
            'data_warnings' => array_values(array_unique($this->queryErrors)),
        ];
    }

    /**
     * @param array{q?:string,status?:string,plan?:string,health?:string,tracking?:string} $filters
     */
    public function companies(array $filters): array
    {
        $tenants = $this->fetchTenants();
        if ($tenants === []) {
            return [
                'companies' => [],
                'all_companies' => [],
                'summary' => $this->emptyCompanySummary(),
                'filters' => $this->normalizeFilters($filters),
                'data_warnings' => array_values(array_unique($this->queryErrors)),
            ];
        }

        $users = $this->aggregateMap(
            'users',
            "SELECT tenant_id, COUNT(*) AS total, SUM(status = 'active') AS active_count, MAX(last_login_at) AS last_login_at FROM users WHERE tenant_id IS NOT NULL GROUP BY tenant_id"
        );
        $instances = $this->aggregateMap(
            'evolution_instances',
            "SELECT tenant_id, COUNT(*) AS total,
                    SUM(status IN ('connected','open','active','online')) AS connected_count,
                    SUM(status = 'disconnected') AS disconnected_count,
                    SUM(status = 'pending') AS pending_count,
                    MAX(updated_at) AS last_update_at
             FROM evolution_instances GROUP BY tenant_id"
        );
        $agents = $this->aggregateMap(
            'ai_agents',
            "SELECT tenant_id, COUNT(*) AS total, SUM(status = 'active') AS active_count,
                    SUM(status = 'active' AND COALESCE(auto_reply_enabled, 0) = 1) AS auto_reply_count,
                    MAX(updated_at) AS last_update_at
             FROM ai_agents GROUP BY tenant_id"
        );
        $conversations = $this->aggregateMap(
            'conversations',
            "SELECT tenant_id, COUNT(*) AS total, SUM(status <> 'closed') AS open_count,
                    COALESCE(SUM(unread_count), 0) AS unread_count, MAX(updated_at) AS last_update_at
             FROM conversations GROUP BY tenant_id"
        );
        $messages = $this->aggregateMap(
            'conversation_messages',
            "SELECT tenant_id,
                    SUM(created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)) AS count_24h,
                    SUM(created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS count_30d,
                    MAX(created_at) AS last_message_at
             FROM conversation_messages GROUP BY tenant_id"
        );
        $subscriptions = $this->latestSubscriptions();
        $implementations = $this->aggregateMap('tenant_implementation_status', 'SELECT * FROM tenant_implementation_status');
        $invoices = $this->aggregateMap(
            'tenant_invoices',
            "SELECT tenant_id,
                    SUM(status = 'overdue' OR (status = 'open' AND due_date < CURDATE())) AS overdue_count,
                    SUM(status = 'open' AND due_date >= CURDATE()) AS open_count,
                    MIN(CASE WHEN status IN ('open','overdue') THEN due_date END) AS next_due_date
             FROM tenant_invoices GROUP BY tenant_id"
        );
        $aiErrors = $this->aggregateMap(
            'ai_automation_logs',
            "SELECT tenant_id, COUNT(*) AS error_count, MAX(created_at) AS last_error_at
             FROM ai_automation_logs
             WHERE status = 'error' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
             GROUP BY tenant_id"
        );
        $n8nErrors = $this->aggregateMap(
            'n8n_flow_logs',
            "SELECT tenant_id, COUNT(*) AS error_count, MAX(created_at) AS last_error_at
             FROM n8n_flow_logs
             WHERE status = 'error' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
             GROUP BY tenant_id"
        );
        $tracking = $this->aggregateMap('tenant_admin_tracking', 'SELECT * FROM tenant_admin_tracking');

        $enriched = [];
        foreach ($tenants as $tenant) {
            $tenantId = (int) ($tenant['id'] ?? 0);
            $company = $tenant;
            $company['users'] = $users[$tenantId] ?? [];
            $company['instances'] = $instances[$tenantId] ?? [];
            $company['agents'] = $agents[$tenantId] ?? [];
            $company['conversations'] = $conversations[$tenantId] ?? [];
            $company['messages'] = $messages[$tenantId] ?? [];
            $company['subscription'] = $subscriptions[$tenantId] ?? [];
            $company['implementation'] = $implementations[$tenantId] ?? [];
            $company['invoices'] = $invoices[$tenantId] ?? [];
            $company['ai_errors'] = $aiErrors[$tenantId] ?? [];
            $company['n8n_errors'] = $n8nErrors[$tenantId] ?? [];
            $company['admin_tracking'] = $tracking[$tenantId] ?? [
                'tracking_status' => 'automatic',
                'priority' => 'attention',
                'note' => null,
                'acknowledged_at' => null,
                'resolved_at' => null,
            ];
            $company['last_activity_at'] = $this->latestDate([
                $company['messages']['last_message_at'] ?? null,
                $company['conversations']['last_update_at'] ?? null,
                $company['instances']['last_update_at'] ?? null,
                $company['agents']['last_update_at'] ?? null,
                $company['updated_at'] ?? null,
            ]);

            $health = $this->companyHealth($company);
            $company['health'] = $health['key'];
            $company['health_label'] = $health['label'];
            $company['attention_reasons'] = $health['reasons'];
            $company['attention_count'] = count($health['reasons']);
            $company['is_reviewed'] = (bool) ($health['reviewed'] ?? false);
            $company['is_resolved'] = (bool) ($health['resolved'] ?? false);
            $enriched[] = $company;
        }

        usort($enriched, static fn (array $left, array $right): int => (int) ($right['id'] ?? 0) <=> (int) ($left['id'] ?? 0));

        $normalized = $this->normalizeFilters($filters);
        $filtered = array_values(array_filter($enriched, function (array $company) use ($normalized): bool {
            if ($normalized['status'] !== '' && (string) ($company['status'] ?? '') !== $normalized['status']) {
                return false;
            }
            if ($normalized['plan'] !== '' && (string) ($company['plan'] ?? '') !== $normalized['plan']) {
                return false;
            }
            if ($normalized['health'] !== '' && (string) ($company['health'] ?? '') !== $normalized['health']) {
                return false;
            }
            if ($normalized['tracking'] !== '' && (string) ($company['admin_tracking']['tracking_status'] ?? 'automatic') !== $normalized['tracking']) {
                return false;
            }
            if ($normalized['q'] !== '') {
                $haystack = mb_strtolower(implode(' ', [
                    (string) ($company['name'] ?? ''),
                    (string) ($company['legal_name'] ?? ''),
                    (string) ($company['email'] ?? ''),
                    (string) ($company['phone'] ?? ''),
                    (string) ($company['document'] ?? ''),
                    (string) ($company['segment'] ?? ''),
                ]));
                if (!str_contains($haystack, mb_strtolower($normalized['q']))) {
                    return false;
                }
            }
            return true;
        }));

        return [
            'companies' => $filtered,
            'all_companies' => $enriched,
            'summary' => $this->companySummary($enriched),
            'filters' => $normalized,
            'data_warnings' => array_values(array_unique($this->queryErrors)),
        ];
    }

    public function companyOverview(int $tenantId): ?array
    {
        if ($tenantId < 1) {
            return null;
        }

        $collection = $this->companies([]);
        $company = null;
        foreach ($collection['all_companies'] as $candidate) {
            if ((int) ($candidate['id'] ?? 0) === $tenantId) {
                $company = $candidate;
                break;
            }
        }
        if (!$company) {
            return null;
        }

        $company['recent_activity'] = $this->recentActivity(14, $tenantId);
        $company['recent_failures'] = $this->recentFailures($tenantId, 8);
        $company['latest_users'] = $this->tableExists('users')
            ? $this->fetchAll(
                'SELECT id, name, email, role, status, last_login_at, created_at FROM users WHERE tenant_id = :tenant_id ORDER BY id DESC LIMIT 6',
                ['tenant_id' => $tenantId]
            )
            : [];

        return $company;
    }

    /** @param array<int,array<string,mixed>> $companies */
    private function dashboardMetrics(array $companies): array
    {
        $fallbackTotal = count($companies);
        $fallbackActive = count(array_filter($companies, static fn (array $company): bool => ($company['status'] ?? '') === 'active'));

        $totalCompanies = $this->tableExists('tenants')
            ? (int) $this->fetchValue('SELECT COUNT(*) FROM tenants', [], $fallbackTotal)
            : $fallbackTotal;
        $activeCompanies = $this->tableExists('tenants')
            ? (int) $this->fetchValue("SELECT COUNT(*) FROM tenants WHERE status = 'active'", [], $fallbackActive)
            : $fallbackActive;

        if ($this->tableExists('tenant_implementation_status')) {
            $onboarding = (int) $this->fetchValue(
                "SELECT COUNT(*)
                 FROM tenants t
                 LEFT JOIN tenant_implementation_status tis ON tis.tenant_id = t.id
                 WHERE t.status = 'active'
                   AND (t.onboarding_completed_at IS NULL OR COALESCE(tis.status, 'pending') <> 'ready')",
                [],
                0
            );
        } else {
            $onboarding = $this->tableExists('tenants')
                ? (int) $this->fetchValue("SELECT COUNT(*) FROM tenants WHERE status = 'active' AND onboarding_completed_at IS NULL", [], 0)
                : 0;
        }

        $messages24 = $this->tableExists('conversation_messages')
            ? (int) $this->fetchValue('SELECT COUNT(*) FROM conversation_messages WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)', [], 0)
            : 0;
        $connectedInstances = $this->tableExists('evolution_instances')
            ? (int) $this->fetchValue("SELECT COUNT(*) FROM evolution_instances WHERE status IN ('connected','open','active','online')", [], 0)
            : 0;
        $unread = $this->tableExists('conversations')
            ? (int) $this->fetchValue('SELECT COALESCE(SUM(unread_count), 0) FROM conversations', [], 0)
            : 0;
        $criticalIncidents = $this->tableExists('system_incidents')
            ? (int) $this->fetchValue("SELECT COUNT(*) FROM system_incidents WHERE resolved_at IS NULL AND severity IN ('error','critical')", [], 0)
            : 0;

        $activeSubscriptions = 0;
        $mrr = 0.0;
        if ($this->tableExists('tenant_subscriptions')) {
            $row = $this->fetchOne(
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
                 WHERE ts.billing_status IN ('active','trialing','overdue')"
            );
            $activeSubscriptions = (int) ($row['active_count'] ?? 0);
            $mrr = (float) ($row['mrr'] ?? 0);
        }

        return [
            'active_companies' => $activeCompanies,
            'total_companies' => $totalCompanies,
            'onboarding' => $onboarding,
            'active_subscriptions' => $activeSubscriptions,
            'mrr' => $mrr,
            'messages_24h' => $messages24,
            'connected_instances' => $connectedInstances,
            'critical_incidents' => $criticalIncidents,
            'unread' => $unread,
        ];
    }

    /** @param array<string,mixed> $company */
    private function companyHealth(array $company): array
    {
        $status = (string) ($company['status'] ?? 'active');
        if ($status === 'inactive') {
            return ['key' => 'inactive', 'label' => 'Inativa', 'reasons' => ['Empresa marcada como inativa.'], 'reviewed' => false, 'resolved' => false];
        }

        $tracking = (array) ($company['admin_tracking'] ?? []);
        $trackingStatus = (string) ($tracking['tracking_status'] ?? 'automatic');
        $manualPriority = (string) ($tracking['priority'] ?? 'attention');
        $manualNote = trim((string) ($tracking['note'] ?? ''));
        $acknowledgedAt = strtotime((string) ($tracking['acknowledged_at'] ?? '')) ?: 0;

        $critical = [];
        $attention = [];
        $isOnboarding = empty($company['onboarding_completed_at']);
        $subscriptionStatus = (string) ($company['subscription']['billing_status'] ?? '');
        $instanceTotal = (int) ($company['instances']['total'] ?? 0);
        $connectedInstances = (int) ($company['instances']['connected_count'] ?? 0);
        $activeAgents = (int) ($company['agents']['active_count'] ?? 0);
        $implementationStatus = (string) ($company['implementation']['status'] ?? '');
        $implementationAttention = (int) ($company['implementation']['attention_count'] ?? 0);
        $overdueInvoices = (int) ($company['invoices']['overdue_count'] ?? 0);
        $aiErrors = (int) ($company['ai_errors']['error_count'] ?? 0);
        $n8nErrors = (int) ($company['n8n_errors']['error_count'] ?? 0);
        $aiLastError = strtotime((string) ($company['ai_errors']['last_error_at'] ?? '')) ?: 0;
        $n8nLastError = strtotime((string) ($company['n8n_errors']['last_error_at'] ?? '')) ?: 0;

        if ($status === 'suspended') {
            $critical[] = 'Conta suspensa no RS Connect.';
        }
        if (in_array($subscriptionStatus, ['overdue', 'suspended'], true) || $overdueInvoices > 0) {
            $critical[] = 'Cobrança ou assinatura precisa de atenção.';
        }
        if ($instanceTotal > 0 && $connectedInstances === 0) {
            $critical[] = 'Nenhuma conexão WhatsApp está ativa.';
        } elseif ($instanceTotal === 0) {
            $attention[] = 'Conexão WhatsApp ainda não preparada.';
        }
        if ($activeAgents === 0) {
            $attention[] = 'Nenhum assistente virtual ativo.';
        }
        if ($isOnboarding) {
            $attention[] = 'Configuração inicial ainda não foi concluída.';
        }
        if ($implementationStatus === 'attention' || $implementationAttention > 0) {
            $attention[] = 'Implantação possui itens pendentes.';
        }
        if ($aiErrors > 0 && ($acknowledgedAt === 0 || $aiLastError > $acknowledgedAt)) {
            $attention[] = $aiErrors . ' falha(s) de IA ainda não revisada(s).';
        }
        if ($n8nErrors > 0 && ($acknowledgedAt === 0 || $n8nLastError > $acknowledgedAt)) {
            $attention[] = $n8nErrors . ' falha(s) de integração ainda não revisada(s).';
        }

        $lastActivity = strtotime((string) ($company['last_activity_at'] ?? '')) ?: 0;
        $createdAt = strtotime((string) ($company['created_at'] ?? '')) ?: 0;
        if (!$isOnboarding && $lastActivity > 0 && $lastActivity < time() - (14 * 86400)) {
            $attention[] = 'Sem atividade recente há mais de 14 dias.';
        } elseif (!$isOnboarding && $lastActivity === 0 && $createdAt > 0 && $createdAt < time() - (7 * 86400)) {
            $attention[] = 'Ainda não há uso registrado da plataforma.';
        }

        $manualReason = $manualNote !== '' ? $manualNote : 'Acompanhamento manual solicitado pela equipe RS.';
        if (in_array($trackingStatus, ['attention', 'reviewed'], true)) {
            if ($manualPriority === 'critical') {
                array_unshift($critical, $manualReason);
            } else {
                array_unshift($attention, $manualReason);
            }
        }

        $reasons = array_values(array_unique(array_merge($critical, $attention)));
        if ($trackingStatus === 'reviewed') {
            $key = in_array($manualPriority, ['critical', 'implantation'], true) ? $manualPriority : 'attention';
            return ['key' => $key, 'label' => 'Em acompanhamento', 'reasons' => $reasons ?: [$manualReason], 'reviewed' => true, 'resolved' => false];
        }
        if ($trackingStatus === 'attention') {
            $key = in_array($manualPriority, ['critical', 'implantation'], true) ? $manualPriority : 'attention';
            $label = match ($key) {
                'critical' => 'Crítica',
                'implantation' => 'Em implantação',
                default => 'Atenção',
            };
            return ['key' => $key, 'label' => $label, 'reasons' => $reasons ?: [$manualReason], 'reviewed' => false, 'resolved' => false];
        }
        if ($critical !== []) {
            return ['key' => 'critical', 'label' => 'Crítica', 'reasons' => $reasons, 'reviewed' => false, 'resolved' => false];
        }
        if ($isOnboarding && $attention !== []) {
            return ['key' => 'implantation', 'label' => 'Em implantação', 'reasons' => $reasons, 'reviewed' => false, 'resolved' => false];
        }
        if ($attention !== []) {
            return ['key' => 'attention', 'label' => 'Atenção', 'reasons' => $reasons, 'reviewed' => false, 'resolved' => false];
        }
        if ($trackingStatus === 'resolved') {
            return ['key' => 'healthy', 'label' => 'Corrigida', 'reasons' => [], 'reviewed' => false, 'resolved' => true];
        }
        return ['key' => 'healthy', 'label' => 'Saudável', 'reasons' => [], 'reviewed' => false, 'resolved' => false];
    }

    /** @param array<int,array<string,mixed>> $companies */
    private function companySummary(array $companies): array
    {
        $summary = $this->emptyCompanySummary();
        $summary['total'] = count($companies);
        foreach ($companies as $company) {
            $health = (string) ($company['health'] ?? 'healthy');
            if (array_key_exists($health, $summary)) {
                $summary[$health]++;
            }
            if (($company['status'] ?? '') === 'active') {
                $summary['active']++;
            }
        }
        return $summary;
    }

    private function emptyCompanySummary(): array
    {
        return [
            'total' => 0,
            'active' => 0,
            'healthy' => 0,
            'attention' => 0,
            'critical' => 0,
            'implantation' => 0,
            'inactive' => 0,
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private function latestHealthChecks(): array
    {
        if (!$this->tableExists('system_health_checks')) {
            return [];
        }

        return $this->fetchAll(
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
    private function recentActivity(int $limit, ?int $tenantId = null): array
    {
        if (!$this->tableExists('audit_logs')) {
            return [];
        }

        $limit = max(1, min(40, $limit));
        $actions = [
            'company.created', 'company.updated', 'company.status_updated',
            'company.attention_marked', 'company.attention_reviewed', 'company.attention_resolved', 'company.attention_reset',
            'user.created', 'user.updated',
            'evolution.instance_created', 'evolution.instance_updated', 'evolution.instance_deleted',
            'agent.created', 'agent.status_updated', 'agent.prompt_updated', 'agent.technical_updated',
            'billing.subscription_created', 'billing.invoice_created', 'billing.invoice_updated',
            'calendar.appointment_created', 'calendar.appointment_status_updated', 'calendar.appointment_deleted',
            'conversation.bulk_marked_read', 'conversation.bulk_deleted',
            'notifications.preferences_updated', 'privacy.settings_updated',
            'crm.lead_created', 'crm.lead_moved', 'crm.lead_updated',
        ];
        $placeholders = implode(',', array_fill(0, count($actions), '?'));
        $sql = 'SELECT al.id, al.tenant_id, al.user_id, al.action, al.context_json, al.created_at,
                       t.name AS tenant_name, u.name AS user_name
                FROM audit_logs al
                LEFT JOIN tenants t ON t.id = al.tenant_id
                LEFT JOIN users u ON u.id = al.user_id
                WHERE al.action IN (' . $placeholders . ')';
        $params = $actions;
        if ($tenantId !== null) {
            $sql .= ' AND al.tenant_id = ?';
            $params[] = $tenantId;
        }
        $sql .= ' ORDER BY al.id DESC LIMIT ' . $limit;

        $rows = $this->fetchAllPositional($sql, $params);
        foreach ($rows as &$row) {
            $row['label'] = $this->activityLabel((string) ($row['action'] ?? ''));
            $row['context'] = $this->decodeJson($row['context_json'] ?? null);
            $row['description'] = $this->activityDescription((string) ($row['action'] ?? ''), $row['context']);
        }
        unset($row);
        return $rows;
    }

    /** @return array<int,array<string,mixed>> */
    private function recentFailures(int $tenantId, int $limit): array
    {
        $limit = max(1, min(20, $limit));
        $failures = [];
        if ($this->tableExists('ai_automation_logs')) {
            $rows = $this->fetchAll(
                'SELECT created_at, event, error_message AS message, "ia" AS source
                 FROM ai_automation_logs
                 WHERE tenant_id = :tenant_id AND status = "error"
                 ORDER BY id DESC LIMIT ' . $limit,
                ['tenant_id' => $tenantId]
            );
            $failures = array_merge($failures, $rows);
        }
        if ($this->tableExists('n8n_flow_logs')) {
            $rows = $this->fetchAll(
                'SELECT created_at, event, error_message AS message, "integracao" AS source
                 FROM n8n_flow_logs
                 WHERE tenant_id = :tenant_id AND status = "error"
                 ORDER BY id DESC LIMIT ' . $limit,
                ['tenant_id' => $tenantId]
            );
            $failures = array_merge($failures, $rows);
        }
        usort($failures, static fn (array $left, array $right): int => strcmp((string) ($right['created_at'] ?? ''), (string) ($left['created_at'] ?? '')));
        return array_slice($failures, 0, $limit);
    }

    /** @return array<int,array<string,mixed>> */
    private function latestSubscriptions(): array
    {
        if (!$this->tableExists('tenant_subscriptions')) {
            return [];
        }
        $planJoin = $this->tableExists('saas_plans') ? 'LEFT JOIN saas_plans sp ON sp.id = ts.plan_id' : '';
        $planFields = $this->tableExists('saas_plans') ? ', sp.name AS plan_name, sp.plan_key' : ', NULL AS plan_name, NULL AS plan_key';
        $rows = $this->fetchAll(
            'SELECT ts.*' . $planFields . '
             FROM tenant_subscriptions ts
             INNER JOIN (
                 SELECT tenant_id, MAX(id) AS max_id
                 FROM tenant_subscriptions
                 GROUP BY tenant_id
             ) latest ON latest.max_id = ts.id
             ' . $planJoin
        );
        $map = [];
        foreach ($rows as $row) {
            $map[(int) ($row['tenant_id'] ?? 0)] = $row;
        }
        return $map;
    }

    /** @return array<int,array<string,mixed>> */
    private function fetchTenants(): array
    {
        if (!$this->tableExists('tenants')) {
            $this->queryErrors[] = 'A tabela de empresas não foi encontrada.';
            return [];
        }
        $rows = $this->fetchAll('SELECT * FROM tenants ORDER BY id DESC');
        if ($rows === []) {
            $count = (int) $this->fetchValue('SELECT COUNT(*) FROM tenants', [], 0);
            if ($count > 0) {
                $this->queryErrors[] = 'Existem empresas no banco, mas a listagem não pôde ser carregada. Revise o log da aplicação.';
            }
        }
        return $rows;
    }

    /** @return array<int,array<string,mixed>> */
    private function aggregateMap(string $table, string $sql): array
    {
        if (!$this->tableExists($table)) {
            return [];
        }
        $rows = $this->fetchAll($sql);
        $map = [];
        foreach ($rows as $row) {
            $tenantId = (int) ($row['tenant_id'] ?? 0);
            if ($tenantId > 0) {
                $map[$tenantId] = $row;
            }
        }
        return $map;
    }

    /** @param array<string,mixed> $filters */
    private function normalizeFilters(array $filters): array
    {
        $status = trim((string) ($filters['status'] ?? ''));
        $plan = trim((string) ($filters['plan'] ?? ''));
        $health = trim((string) ($filters['health'] ?? ''));
        $tracking = trim((string) ($filters['tracking'] ?? ''));
        return [
            'q' => trim((string) ($filters['q'] ?? '')),
            'status' => in_array($status, ['active', 'inactive', 'suspended'], true) ? $status : '',
            'plan' => in_array($plan, ['starter', 'pro', 'business', 'custom'], true) ? $plan : '',
            'health' => in_array($health, ['healthy', 'attention', 'critical', 'implantation', 'inactive'], true) ? $health : '',
            'tracking' => in_array($tracking, ['automatic', 'attention', 'reviewed', 'resolved'], true) ? $tracking : '',
        ];
    }

    /** @param array<int,mixed> $dates */
    private function latestDate(array $dates): ?string
    {
        $latest = null;
        $latestTs = 0;
        foreach ($dates as $date) {
            $value = trim((string) ($date ?? ''));
            $ts = $value !== '' ? (strtotime($value) ?: 0) : 0;
            if ($ts > $latestTs) {
                $latestTs = $ts;
                $latest = $value;
            }
        }
        return $latest;
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
        $human = str_replace(['.', '_'], ' ', $action);
        return ucfirst(trim($human !== '' ? $human : 'Atividade registrada'));
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
            'company.attention_resolved' => 'A equipe registrou que a pendência foi revisada e corrigida.',
            'company.attention_reviewed' => 'A pendência foi visualizada e está em acompanhamento.',
            'company.attention_marked' => 'Novo ponto de atenção incluído para acompanhamento.',
            'evolution.instance_updated' => 'Dados técnicos ou vínculo da conexão foram atualizados.',
            'agent.prompt_updated' => 'As instruções usadas nas próximas respostas foram alteradas.',
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

    private function tableExists(string $table): bool
    {
        if (array_key_exists($table, $this->tableCache)) {
            return $this->tableCache[$table];
        }
        try {
            $statement = $this->pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table'
            );
            $statement->execute(['table' => $table]);
            return $this->tableCache[$table] = (int) $statement->fetchColumn() > 0;
        } catch (Throwable $exception) {
            $this->queryErrors[] = 'Não foi possível verificar a tabela ' . $table . ': ' . $exception->getMessage();
            return $this->tableCache[$table] = false;
        }
    }

    private function columnExists(string $table, string $column): bool
    {
        $key = $table . '.' . $column;
        if (array_key_exists($key, $this->columnCache)) {
            return $this->columnCache[$key];
        }
        try {
            $statement = $this->pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column'
            );
            $statement->execute(['table' => $table, 'column' => $column]);
            return $this->columnCache[$key] = (int) $statement->fetchColumn() > 0;
        } catch (Throwable) {
            return $this->columnCache[$key] = false;
        }
    }

    /** @param array<string,mixed> $params @return array<int,array<string,mixed>> */
    private function fetchAll(string $sql, array $params = []): array
    {
        try {
            $statement = $this->pdo->prepare($sql);
            $statement->execute($params);
            return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $exception) {
            $this->queryErrors[] = $exception->getMessage();
            return [];
        }
    }

    /** @param array<int,mixed> $params @return array<int,array<string,mixed>> */
    private function fetchAllPositional(string $sql, array $params = []): array
    {
        try {
            $statement = $this->pdo->prepare($sql);
            $statement->execute(array_values($params));
            return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $exception) {
            $this->queryErrors[] = $exception->getMessage();
            return [];
        }
    }

    /** @param array<string,mixed> $params @return array<string,mixed> */
    private function fetchOne(string $sql, array $params = []): array
    {
        $rows = $this->fetchAll($sql, $params);
        return $rows[0] ?? [];
    }

    /** @param array<string,mixed> $params */
    private function fetchValue(string $sql, array $params = [], mixed $default = 0): mixed
    {
        try {
            $statement = $this->pdo->prepare($sql);
            $statement->execute($params);
            $value = $statement->fetchColumn();
            return $value === false ? $default : $value;
        } catch (Throwable $exception) {
            $this->queryErrors[] = $exception->getMessage();
            return $default;
        }
    }
}
