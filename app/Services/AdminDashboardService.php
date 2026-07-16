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

        return [
            'metrics' => $metrics,
            'health_checks' => $this->latestHealthChecks(),
            'attention_companies' => array_slice($attention, 0, 7),
            'recent_companies' => array_slice($companies, 0, 6),
            'recent_activity' => $this->recentActivity(10),
            'company_summary' => $collection['summary'],
        ];
    }

    /**
     * @param array{q?:string,status?:string,plan?:string,health?:string} $filters
     */
    public function companies(array $filters): array
    {
        $tenants = $this->fetchAll('SELECT * FROM tenants ORDER BY created_at DESC');
        if ($tenants === []) {
            return [
                'companies' => [],
                'all_companies' => [],
                'summary' => $this->emptyCompanySummary(),
                'filters' => $this->normalizeFilters($filters),
            ];
        }

        $users = $this->aggregateMap(
            'users',
            "SELECT tenant_id, COUNT(*) AS total, SUM(status = 'active') AS active_count, MAX(last_login_at) AS last_login_at FROM users WHERE tenant_id IS NOT NULL GROUP BY tenant_id"
        );
        $instances = $this->aggregateMap(
            'evolution_instances',
            "SELECT tenant_id, COUNT(*) AS total, SUM(status = 'connected') AS connected_count, SUM(status = 'disconnected') AS disconnected_count, SUM(status = 'pending') AS pending_count, MAX(updated_at) AS last_update_at FROM evolution_instances GROUP BY tenant_id"
        );
        $agents = $this->aggregateMap(
            'ai_agents',
            "SELECT tenant_id, COUNT(*) AS total, SUM(status = 'active') AS active_count, SUM(status = 'active' AND COALESCE(auto_reply_enabled, 0) = 1) AS auto_reply_count, MAX(updated_at) AS last_update_at FROM ai_agents GROUP BY tenant_id"
        );
        $conversations = $this->aggregateMap(
            'conversations',
            "SELECT tenant_id, COUNT(*) AS total, SUM(status <> 'closed') AS open_count, COALESCE(SUM(unread_count), 0) AS unread_count, MAX(updated_at) AS last_update_at FROM conversations GROUP BY tenant_id"
        );
        $messages = $this->aggregateMap(
            'conversation_messages',
            "SELECT tenant_id,
                    SUM(created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)) AS count_24h,
                    SUM(created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS count_30d,
                    MAX(created_at) AS last_message_at
             FROM conversation_messages
             GROUP BY tenant_id"
        );
        $subscriptions = $this->latestSubscriptions();
        $implementations = $this->aggregateMap('tenant_implementation_status', 'SELECT * FROM tenant_implementation_status');
        $invoices = $this->aggregateMap(
            'tenant_invoices',
            "SELECT tenant_id,
                    SUM(status = 'overdue' OR (status = 'open' AND due_date < CURDATE())) AS overdue_count,
                    SUM(status = 'open' AND due_date >= CURDATE()) AS open_count,
                    MIN(CASE WHEN status IN ('open','overdue') THEN due_date END) AS next_due_date
             FROM tenant_invoices
             GROUP BY tenant_id"
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
            $enriched[] = $company;
        }

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

        $company['recent_activity'] = $this->recentActivity(12, $tenantId);
        $company['recent_failures'] = $this->recentFailures($tenantId, 8);
        $company['latest_users'] = $this->tableExists('users')
            ? $this->fetchAll(
                'SELECT id, name, email, role, status, last_login_at, created_at FROM users WHERE tenant_id = :tenant_id ORDER BY created_at DESC LIMIT 6',
                ['tenant_id' => $tenantId]
            )
            : [];

        return $company;
    }

    /** @param array<int,array<string,mixed>> $companies */
    private function dashboardMetrics(array $companies): array
    {
        $mrr = 0.0;
        $activeSubscriptions = 0;
        $activeCompanies = 0;
        $onboarding = 0;
        $messages24 = 0;
        $connectedInstances = 0;
        $unread = 0;

        foreach ($companies as $company) {
            if (($company['status'] ?? '') === 'active') {
                $activeCompanies++;
            }
            if (empty($company['onboarding_completed_at'])) {
                $onboarding++;
            }
            $messages24 += (int) ($company['messages']['count_24h'] ?? 0);
            $connectedInstances += (int) ($company['instances']['connected_count'] ?? 0);
            $unread += (int) ($company['conversations']['unread_count'] ?? 0);

            $subscriptionStatus = (string) ($company['subscription']['billing_status'] ?? '');
            if (in_array($subscriptionStatus, ['active', 'trialing', 'overdue'], true)) {
                $activeSubscriptions++;
                $amount = (float) ($company['subscription']['amount'] ?? 0);
                $cycle = (string) ($company['subscription']['billing_cycle'] ?? 'monthly');
                $divisor = match ($cycle) {
                    'quarterly' => 3,
                    'semiannual' => 6,
                    'annual' => 12,
                    default => 1,
                };
                $mrr += $divisor > 0 ? $amount / $divisor : $amount;
            }
        }

        $criticalIncidents = $this->tableExists('system_incidents')
            ? (int) $this->fetchValue("SELECT COUNT(*) FROM system_incidents WHERE resolved_at IS NULL AND severity IN ('error','critical')")
            : 0;

        return [
            'active_companies' => $activeCompanies,
            'total_companies' => count($companies),
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
            return ['key' => 'inactive', 'label' => 'Inativa', 'reasons' => ['Empresa marcada como inativa.']];
        }

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
        if ($aiErrors > 0) {
            $attention[] = $aiErrors . ' falha(s) de IA nos últimos 7 dias.';
        }
        if ($n8nErrors > 0) {
            $attention[] = $n8nErrors . ' falha(s) de integração nos últimos 7 dias.';
        }

        $lastActivity = strtotime((string) ($company['last_activity_at'] ?? '')) ?: 0;
        $createdAt = strtotime((string) ($company['created_at'] ?? '')) ?: 0;
        if (!$isOnboarding && $lastActivity > 0 && $lastActivity < time() - (14 * 86400)) {
            $attention[] = 'Sem atividade recente há mais de 14 dias.';
        } elseif (!$isOnboarding && $lastActivity === 0 && $createdAt > 0 && $createdAt < time() - (7 * 86400)) {
            $attention[] = 'Ainda não há uso registrado da plataforma.';
        }

        $reasons = array_values(array_unique(array_merge($critical, $attention)));
        if ($critical !== []) {
            return ['key' => 'critical', 'label' => 'Crítica', 'reasons' => $reasons];
        }
        if ($isOnboarding && $attention !== []) {
            return ['key' => 'implantation', 'label' => 'Em implantação', 'reasons' => $reasons];
        }
        if ($attention !== []) {
            return ['key' => 'attention', 'label' => 'Atenção', 'reasons' => $reasons];
        }
        return ['key' => 'healthy', 'label' => 'Saudável', 'reasons' => []];
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

        $limit = max(1, min(30, $limit));
        $sql = 'SELECT al.id, al.tenant_id, al.user_id, al.action, al.context_json, al.created_at,
                       t.name AS tenant_name, u.name AS user_name
                FROM audit_logs al
                LEFT JOIN tenants t ON t.id = al.tenant_id
                LEFT JOIN users u ON u.id = al.user_id';
        $params = [];
        if ($tenantId !== null) {
            $sql .= ' WHERE al.tenant_id = :tenant_id';
            $params['tenant_id'] = $tenantId;
        }
        $sql .= ' ORDER BY al.id DESC LIMIT ' . $limit;

        $rows = $this->fetchAll($sql, $params);
        foreach ($rows as &$row) {
            $row['label'] = $this->activityLabel((string) ($row['action'] ?? ''));
            $row['context'] = $this->decodeJson($row['context_json'] ?? null);
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
        return [
            'q' => trim((string) ($filters['q'] ?? '')),
            'status' => in_array($status, ['active', 'inactive', 'suspended'], true) ? $status : '',
            'plan' => in_array($plan, ['starter', 'pro', 'business', 'custom'], true) ? $plan : '',
            'health' => in_array($health, ['healthy', 'attention', 'critical', 'implantation', 'inactive'], true) ? $health : '',
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
            'user.created' => 'Usuário criado',
            'user.updated' => 'Usuário atualizado',
            'instance.created' => 'Conexão WhatsApp preparada',
            'instance.updated' => 'Conexão WhatsApp atualizada',
            'instance.deleted' => 'Conexão WhatsApp removida',
            'agent.created' => 'Assistente virtual criado',
            'agent.updated' => 'Assistente virtual atualizado',
            'agent.prompt_updated' => 'Instruções do assistente atualizadas',
            'subscription.saved' => 'Assinatura atualizada',
            'invoice.created' => 'Cobrança criada',
            'invoice.status_updated' => 'Cobrança atualizada',
            'calendar.appointment.created' => 'Compromisso criado',
            'calendar.appointment.deleted' => 'Compromisso excluído',
            'conversations.mark_read' => 'Conversas marcadas como lidas',
            'conversations.deleted' => 'Conversas excluídas',
        ];
        if (isset($labels[$action])) {
            return $labels[$action];
        }
        $human = str_replace(['.', '_'], ' ', $action);
        return ucfirst(trim($human !== '' ? $human : 'Atividade registrada'));
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
        } catch (Throwable) {
            return $this->tableCache[$table] = false;
        }
    }

    /** @param array<string,mixed> $params @return array<int,array<string,mixed>> */
    private function fetchAll(string $sql, array $params = []): array
    {
        try {
            $statement = $this->pdo->prepare($sql);
            $statement->execute($params);
            return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return [];
        }
    }

    /** @param array<string,mixed> $params */
    private function fetchValue(string $sql, array $params = [], mixed $default = 0): mixed
    {
        try {
            $statement = $this->pdo->prepare($sql);
            $statement->execute($params);
            $value = $statement->fetchColumn();
            return $value === false ? $default : $value;
        } catch (Throwable) {
            return $default;
        }
    }
}
