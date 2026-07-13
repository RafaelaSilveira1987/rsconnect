<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use Throwable;

final class ImplementationChecklistService
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::connection();
    }

    /** @return array<string, mixed> */
    public function dashboard(): array
    {
        $tenants = $this->pdo->query(
            'SELECT id, name, slug, plan, status, segment, onboarding_completed_at, created_at
             FROM tenants
             ORDER BY created_at DESC, id DESC'
        )->fetchAll(PDO::FETCH_ASSOC);

        $items = [];
        $summary = [
            'total' => count($tenants),
            'ready' => 0,
            'testing' => 0,
            'configuring' => 0,
            'pending' => 0,
            'attention' => 0,
            'average_percent' => 0,
        ];
        $totalPercent = 0;

        foreach ($tenants as $tenant) {
            $card = $this->tenantCard((int) $tenant['id'], $tenant);
            $items[] = $card;
            $summary[$card['status_key']] = ($summary[$card['status_key']] ?? 0) + 1;
            $totalPercent += $card['percent'];
            if ($card['attention_count'] > 0) {
                $summary['attention']++;
            }
        }

        $summary['average_percent'] = $summary['total'] > 0 ? (int) round($totalPercent / $summary['total']) : 0;

        return [
            'summary' => $summary,
            'tenants' => $items,
        ];
    }

    /** @return array<string, mixed> */
    public function tenantDetail(int $tenantId): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM tenants WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $tenantId]);
        $tenant = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$tenant) {
            return ['tenant' => null, 'sections' => [], 'card' => null, 'manual' => []];
        }

        $checks = $this->computeChecks($tenantId);
        $manual = $this->manualItems($tenantId);
        $checks = $this->applyManualState($checks, $manual);
        $sections = $this->groupChecks($checks);
        $percent = $this->percent($checks);
        $status = $this->statusFromPercent($percent, $checks);

        $card = $this->tenantCard($tenantId, $tenant);

        return [
            'tenant' => $tenant,
            'sections' => $sections,
            'checks' => $checks,
            'card' => $card,
            'percent' => $percent,
            'status' => $status,
            'manual' => $manual,
            'quick_actions' => $this->quickActions($tenantId),
        ];
    }

    public function updateItem(int $tenantId, string $itemKey, string $status, string $notes, ?int $userId): void
    {
        $allowed = ['auto', 'pending', 'complete', 'skipped', 'attention'];
        if (!in_array($status, $allowed, true)) {
            $status = 'auto';
        }

        $label = $this->definitionByKey($itemKey)['label'] ?? $itemKey;
        $category = $this->definitionByKey($itemKey)['category'] ?? 'Geral';
        $notes = mb_substr(trim($notes), 0, 1000);

        $statement = $this->pdo->prepare(
            'INSERT INTO ' . $this->checklistTableForWrite() . '
                (tenant_id, item_key, label, category, manual_status, notes, updated_by, updated_at)
             VALUES
                (:tenant_id, :item_key, :label, :category, :manual_status, :notes, :updated_by, NOW())
             ON DUPLICATE KEY UPDATE
                label = VALUES(label),
                category = VALUES(category),
                manual_status = VALUES(manual_status),
                notes = VALUES(notes),
                updated_by = VALUES(updated_by),
                updated_at = NOW()'
        );
        $statement->execute([
            'tenant_id' => $tenantId,
            'item_key' => $itemKey,
            'label' => $label,
            'category' => $category,
            'manual_status' => $status,
            'notes' => $notes !== '' ? $notes : null,
            'updated_by' => $userId,
        ]);

        $this->refreshStatus($tenantId, $userId);
    }

    public function refreshStatus(int $tenantId, ?int $userId = null): void
    {
        $detail = $this->tenantDetailWithoutRefresh($tenantId);
        if (!$detail['tenant']) {
            return;
        }

        $percent = (int) $detail['percent'];
        $status = (string) ($detail['status']['key'] ?? 'pending');
        $attention = 0;
        foreach ($detail['checks'] as $check) {
            if (($check['status'] ?? '') === 'attention') {
                $attention++;
            }
        }
        $readyAt = $status === 'ready' ? date('Y-m-d H:i:s') : null;

        $statement = $this->pdo->prepare(
            'INSERT INTO tenant_implementation_status
                (tenant_id, status, percent_complete, attention_count, last_checked_at, ready_at, updated_by, updated_at)
             VALUES
                (:tenant_id, :status, :percent, :attention_count, NOW(), :ready_at, :updated_by, NOW())
             ON DUPLICATE KEY UPDATE
                status = VALUES(status),
                percent_complete = VALUES(percent_complete),
                attention_count = VALUES(attention_count),
                last_checked_at = NOW(),
                ready_at = CASE WHEN VALUES(status) = "ready" THEN COALESCE(ready_at, VALUES(ready_at)) ELSE NULL END,
                updated_by = VALUES(updated_by),
                updated_at = NOW()'
        );
        $statement->execute([
            'tenant_id' => $tenantId,
            'status' => $status,
            'percent' => $percent,
            'attention_count' => $attention,
            'ready_at' => $readyAt,
            'updated_by' => $userId,
        ]);
    }

    /** @param array<string, mixed> $tenant */
    private function tenantCard(int $tenantId, array $tenant): array
    {
        $detail = $this->tenantDetailWithoutRefresh($tenantId);
        $checks = $detail['checks'];
        $percent = (int) $detail['percent'];
        $status = $detail['status'];

        $attention = 0;
        $done = 0;
        foreach ($checks as $check) {
            if (($check['status'] ?? '') === 'complete' || ($check['status'] ?? '') === 'skipped') {
                $done++;
            }
            if (($check['status'] ?? '') === 'attention') {
                $attention++;
            }
        }

        return [
            'id' => $tenantId,
            'name' => (string) ($tenant['name'] ?? ''),
            'slug' => (string) ($tenant['slug'] ?? ''),
            'plan' => (string) ($tenant['plan'] ?? ''),
            'tenant_status' => (string) ($tenant['status'] ?? ''),
            'segment' => (string) ($tenant['segment'] ?? ''),
            'percent' => $percent,
            'status_key' => (string) ($status['key'] ?? 'pending'),
            'status_label' => (string) ($status['label'] ?? 'Pendente'),
            'status_badge' => (string) ($status['badge'] ?? 'badge-warning'),
            'done_count' => $done,
            'total_count' => count($checks),
            'attention_count' => $attention,
            'next_items' => array_slice(array_values(array_filter($checks, static fn (array $check): bool => ($check['status'] ?? '') !== 'complete' && ($check['status'] ?? '') !== 'skipped')), 0, 3),
        ];
    }

    /** @return array<string, mixed> */
    private function tenantDetailWithoutRefresh(int $tenantId): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM tenants WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $tenantId]);
        $tenant = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$tenant) {
            return ['tenant' => null, 'checks' => [], 'percent' => 0, 'status' => $this->statusFromPercent(0, [])];
        }

        $checks = $this->computeChecks($tenantId);
        $checks = $this->applyManualState($checks, $this->manualItems($tenantId));
        $percent = $this->percent($checks);

        return [
            'tenant' => $tenant,
            'checks' => $checks,
            'percent' => $percent,
            'status' => $this->statusFromPercent($percent, $checks),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function computeChecks(int $tenantId): array
    {
        $defs = $this->definitions();
        $result = [];
        foreach ($defs as $def) {
            $key = $def['key'];
            $status = 'pending';
            $message = 'Aguardando configuração.';
            try {
                [$status, $message] = $this->evaluate($tenantId, $key);
            } catch (Throwable $e) {
                $status = 'attention';
                $message = 'Não foi possível validar automaticamente: ' . $e->getMessage();
            }

            $result[] = [
                'key' => $key,
                'label' => $def['label'],
                'category' => $def['category'],
                'description' => $def['description'],
                'route' => $def['route'] ?? null,
                'status' => $status,
                'auto_status' => $status,
                'message' => $message,
                'weight' => (int) ($def['weight'] ?? 1),
                'manual_status' => 'auto',
                'notes' => null,
            ];
        }

        return $result;
    }

    /** @return array{0:string,1:string} */
    private function evaluate(int $tenantId, string $key): array
    {
        return match ($key) {
            'company_profile' => $this->checkCompanyProfile($tenantId),
            'client_admin' => $this->checkCount('users', 'tenant_id = :tenant_id AND role = "client_admin" AND status = "active"', $tenantId, 'Administrador da empresa criado.', 'Crie um usuário administrador para a empresa.'),
            'whatsapp_instance' => $this->checkCount('evolution_instances', 'tenant_id = :tenant_id', $tenantId, 'Instância WhatsApp cadastrada.', 'Cadastre uma instância WhatsApp.'),
            'evolution_connected' => $this->checkCount('evolution_instances', 'tenant_id = :tenant_id AND status = "connected"', $tenantId, 'Evolution conectada.', 'Conecte a instância Evolution.'),
            'evolution_test' => $this->checkAudit($tenantId, 'evolution.test%', 'Teste de envio registrado.', 'Envie uma mensagem de teste pela tela de instâncias.'),
            'agent_created' => $this->checkCount('ai_agents', 'tenant_id = :tenant_id', $tenantId, 'Agente IA criado.', 'Crie pelo menos um agente IA.'),
            'agent_active' => $this->checkAgentActive($tenantId),
            'ai_credentials' => $this->checkAiCredentials($tenantId),
            'menus_configured' => $this->checkCount('tenant_module_settings', 'tenant_id = :tenant_id', $tenantId, 'Menus da empresa configurados.', 'Revise os módulos e menus da empresa.'),
            'calendar_module' => $this->checkModule($tenantId, 'calendar', 'Agenda habilitada.', 'Ative ou revise o módulo Agenda.'),
            'pre_schedule' => $this->checkPreSchedule($tenantId),
            'n8n_flow' => $this->checkCount('n8n_tenant_flows', 'tenant_id = :tenant_id AND status = "active"', $tenantId, 'Fluxo n8n ativo.', 'Cadastre um fluxo n8n se a empresa usar integrações externas.'),
            'subscription' => $this->checkCount('tenant_subscriptions', 'tenant_id = :tenant_id', $tenantId, 'Assinatura cadastrada.', 'Vincule um plano/assinatura para a empresa.'),
            'invoice_or_gateway' => $this->checkBillingReady($tenantId),
            'lgpd_settings' => $this->checkLgpdSettings($tenantId),
            'lgpd_acceptance' => $this->checkLgpdAcceptance($tenantId),
            'monitoring_health' => $this->checkMonitoring(),
            'backup_registered' => $this->checkBackup(),
            default => ['pending', 'Aguardando validação.'],
        };
    }

    /** @return array{0:string,1:string} */
    private function checkCompanyProfile(int $tenantId): array
    {
        $statement = $this->pdo->prepare('SELECT name, email, phone, segment FROM tenants WHERE id = :tenant_id LIMIT 1');
        $statement->execute(['tenant_id' => $tenantId]);
        $tenant = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
        $missing = [];
        foreach (['name' => 'nome', 'email' => 'e-mail', 'phone' => 'telefone', 'segment' => 'segmento'] as $field => $label) {
            if (trim((string) ($tenant[$field] ?? '')) === '') {
                $missing[] = $label;
            }
        }
        if (!$missing) {
            return ['complete', 'Dados principais da empresa preenchidos.'];
        }
        return ['pending', 'Falta preencher: ' . implode(', ', $missing) . '.'];
    }

    /** @return array{0:string,1:string} */
    private function checkCount(string $table, string $where, int $tenantId, string $ok, string $pending): array
    {
        if (!$this->tableExists($table)) {
            return ['pending', $pending];
        }
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $where);
        $statement->execute(['tenant_id' => $tenantId]);
        return (int) $statement->fetchColumn() > 0 ? ['complete', $ok] : ['pending', $pending];
    }

    /** @return array{0:string,1:string} */
    private function checkAudit(int $tenantId, string $actionLike, string $ok, string $pending): array
    {
        if (!$this->tableExists('audit_logs')) {
            return ['pending', $pending];
        }
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM audit_logs WHERE tenant_id = :tenant_id AND action LIKE :action_like');
        $statement->execute(['tenant_id' => $tenantId, 'action_like' => $actionLike]);
        return (int) $statement->fetchColumn() > 0 ? ['complete', $ok] : ['pending', $pending];
    }

    /** @return array{0:string,1:string} */
    private function checkAgentActive(int $tenantId): array
    {
        if (!$this->tableExists('ai_agents')) {
            return ['pending', 'Crie e ative um agente IA.'];
        }
        $sql = 'SELECT COUNT(*) FROM ai_agents WHERE tenant_id = :tenant_id AND status = "active"';
        if ($this->columnExists('ai_agents', 'auto_reply_enabled')) {
            $sql .= ' AND auto_reply_enabled = 1';
        }
        $statement = $this->pdo->prepare($sql);
        $statement->execute(['tenant_id' => $tenantId]);
        return (int) $statement->fetchColumn() > 0 ? ['complete', 'Agente IA ativo para atendimento.'] : ['pending', 'Ative um agente IA para atendimento automático.'];
    }

    /** @return array{0:string,1:string} */
    private function checkAiCredentials(int $tenantId): array
    {
        if ($this->tableExists('ai_provider_credentials')) {
            $statement = $this->pdo->prepare('SELECT COUNT(*) FROM ai_provider_credentials WHERE (tenant_id = :tenant_id OR tenant_id IS NULL) AND status = "active"');
            $statement->execute(['tenant_id' => $tenantId]);
            if ((int) $statement->fetchColumn() > 0) {
                return ['complete', 'Credencial IA ativa encontrada.'];
            }
        }
        $global = trim((string) ($_ENV['OPENAI_API_KEY'] ?? getenv('OPENAI_API_KEY') ?: '')) !== '';
        return $global ? ['complete', 'Usando chave global de IA configurada.'] : ['pending', 'Configure uma credencial de IA da empresa ou uma chave global.'];
    }

    /** @return array{0:string,1:string} */
    private function checkModule(int $tenantId, string $module, string $ok, string $pending): array
    {
        if (!$this->tableExists('tenant_module_settings')) {
            return ['pending', $pending];
        }
        $statement = $this->pdo->prepare('SELECT is_enabled, is_visible FROM tenant_module_settings WHERE tenant_id = :tenant_id AND module_key = :module LIMIT 1');
        $statement->execute(['tenant_id' => $tenantId, 'module' => $module]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row && (int) $row['is_enabled'] === 1 && (int) $row['is_visible'] === 1) {
            return ['complete', $ok];
        }
        return ['pending', $pending];
    }

    /** @return array{0:string,1:string} */
    private function checkPreSchedule(int $tenantId): array
    {
        if (!$this->tableExists('tenant_pre_schedule_settings')) {
            return ['skipped', 'Recurso opcional. Ative somente para empresas que trabalham com pré-agendamento.'];
        }
        $statement = $this->pdo->prepare('SELECT enabled, require_human_approval FROM tenant_pre_schedule_settings WHERE tenant_id = :tenant_id LIMIT 1');
        $statement->execute(['tenant_id' => $tenantId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row && (int) $row['enabled'] === 1) {
            return ['complete', (int) $row['require_human_approval'] === 1 ? 'Pré-agendamento ativo com aprovação humana.' : 'Pré-agendamento ativo.'];
        }
        return ['skipped', 'Pré-agendamento desativado. Marque como pendente apenas se a empresa precisar desse fluxo.'];
    }

    /** @return array{0:string,1:string} */
    private function checkBillingReady(int $tenantId): array
    {
        $hasGateway = false;
        if ($this->tableExists('payment_gateways')) {
            $hasGateway = (int) $this->pdo->query('SELECT COUNT(*) FROM payment_gateways WHERE status = "active"')->fetchColumn() > 0;
        }
        $hasInvoice = false;
        if ($this->tableExists('tenant_invoices')) {
            $statement = $this->pdo->prepare('SELECT COUNT(*) FROM tenant_invoices WHERE tenant_id = :tenant_id');
            $statement->execute(['tenant_id' => $tenantId]);
            $hasInvoice = (int) $statement->fetchColumn() > 0;
        }
        if ($hasGateway || $hasInvoice) {
            return ['complete', $hasGateway ? 'Gateway de pagamento ativo para geração de cobrança.' : 'Cobrança da empresa cadastrada.'];
        }
        return ['pending', 'Configure um gateway ou crie a primeira cobrança da empresa.'];
    }

    /** @return array{0:string,1:string} */
    private function checkLgpdSettings(int $tenantId): array
    {
        if (!$this->tableExists('tenant_privacy_settings')) {
            return ['pending', 'Configure a política de privacidade da empresa.'];
        }
        $statement = $this->pdo->prepare('SELECT privacy_policy_text, terms_text, dpo_email FROM tenant_privacy_settings WHERE tenant_id = :tenant_id LIMIT 1');
        $statement->execute(['tenant_id' => $tenantId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
        if (trim((string) ($row['privacy_policy_text'] ?? '')) !== '' && trim((string) ($row['terms_text'] ?? '')) !== '') {
            return ['complete', 'Política e termo LGPD configurados.'];
        }
        return ['pending', 'Revise política de privacidade e termo de tratamento de dados.'];
    }

    /** @return array{0:string,1:string} */
    private function checkLgpdAcceptance(int $tenantId): array
    {
        if (!$this->tableExists('privacy_consents')) {
            return ['pending', 'Sem registros de aceite LGPD.'];
        }
        $users = $this->pdo->prepare('SELECT COUNT(*) FROM users WHERE tenant_id = :tenant_id AND status = "active"');
        $users->execute(['tenant_id' => $tenantId]);
        $totalUsers = (int) $users->fetchColumn();
        if ($totalUsers === 0) {
            return ['pending', 'Crie usuários da empresa antes de coletar aceite.'];
        }
        $accepted = $this->pdo->prepare('SELECT COUNT(DISTINCT user_id) FROM privacy_consents WHERE tenant_id = :tenant_id AND user_id IS NOT NULL AND consent_type IN ("company_terms", "privacy_policy")');
        $accepted->execute(['tenant_id' => $tenantId]);
        return (int) $accepted->fetchColumn() > 0 ? ['complete', 'Há aceite LGPD registrado para usuários da empresa.'] : ['pending', 'Aguardando aceite LGPD dos usuários vinculados.'];
    }

    /** @return array{0:string,1:string} */
    private function checkMonitoring(): array
    {
        if (!$this->tableExists('system_health_checks')) {
            return ['pending', 'Execute o monitoramento operacional.'];
        }
        $latest = $this->pdo->query('SELECT COUNT(*) FROM system_health_checks WHERE checked_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)')->fetchColumn();
        return (int) $latest > 0 ? ['complete', 'Monitoramento executado nas últimas 24 horas.'] : ['pending', 'Execute o monitoramento em /monitoramento.'];
    }

    /** @return array{0:string,1:string} */
    private function checkBackup(): array
    {
        if (!$this->tableExists('system_backups')) {
            return ['pending', 'Registre o primeiro backup.'];
        }
        $latest = $this->pdo->query('SELECT COUNT(*) FROM system_backups WHERE status = "success"')->fetchColumn();
        return (int) $latest > 0 ? ['complete', 'Backup registrado no painel operacional.'] : ['pending', 'Registre um backup manual ou configure rotina externa.'];
    }

    /** @return array<int, array<string, mixed>> */
    private function applyManualState(array $checks, array $manual): array
    {
        foreach ($checks as &$check) {
            $item = $manual[$check['key']] ?? null;
            if (!$item) {
                continue;
            }
            $check['manual_status'] = (string) ($item['manual_status'] ?? 'auto');
            $check['notes'] = $item['notes'] ?? null;
            if (($item['manual_status'] ?? 'auto') !== 'auto') {
                $check['status'] = (string) $item['manual_status'];
                $check['message'] = $item['notes'] ?: $check['message'];
            }
        }
        unset($check);
        return $checks;
    }

    /** @return array<string, array<string, mixed>> */
    private function manualItems(int $tenantId): array
    {
        $table = $this->checklistTableForRead();
        if ($table === null) {
            return [];
        }
        $statement = $this->pdo->prepare('SELECT * FROM ' . $table . ' WHERE tenant_id = :tenant_id');
        $statement->execute(['tenant_id' => $tenantId]);
        $items = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $items[(string) $row['item_key']] = $row;
        }
        return $items;
    }

    /** @return array<string, array<int, array<string, mixed>>> */
    private function groupChecks(array $checks): array
    {
        $groups = [];
        foreach ($checks as $check) {
            $groups[$check['category']][] = $check;
        }
        return $groups;
    }

    private function percent(array $checks): int
    {
        $total = 0;
        $done = 0;
        foreach ($checks as $check) {
            $weight = (int) ($check['weight'] ?? 1);
            $total += $weight;
            if (($check['status'] ?? '') === 'complete' || ($check['status'] ?? '') === 'skipped') {
                $done += $weight;
            }
        }
        return $total > 0 ? (int) round(($done / $total) * 100) : 0;
    }

    /** @return array<string, string> */
    private function statusFromPercent(int $percent, array $checks): array
    {
        $attention = count(array_filter($checks, static fn (array $check): bool => ($check['status'] ?? '') === 'attention'));
        if ($attention > 0) {
            return ['key' => 'attention', 'label' => 'Com pendências', 'badge' => 'badge-danger'];
        }
        if ($percent >= 95) {
            return ['key' => 'ready', 'label' => 'Em operação', 'badge' => 'badge-success'];
        }
        if ($percent >= 75) {
            return ['key' => 'testing', 'label' => 'Pronta para teste', 'badge' => 'badge-info'];
        }
        if ($percent >= 35) {
            return ['key' => 'configuring', 'label' => 'Em configuração', 'badge' => 'badge-warning'];
        }
        return ['key' => 'pending', 'label' => 'Pendente', 'badge' => 'badge-warning'];
    }

    /** @return array<int, array<string, mixed>> */
    private function definitions(): array
    {
        return [
            ['key' => 'company_profile', 'category' => 'Empresa', 'label' => 'Dados da empresa', 'description' => 'Nome, contato, telefone e segmento preenchidos.', 'route' => '/companies', 'weight' => 2],
            ['key' => 'client_admin', 'category' => 'Empresa', 'label' => 'Administrador cliente', 'description' => 'Usuário administrador ativo vinculado à empresa.', 'route' => '/users', 'weight' => 2],
            ['key' => 'whatsapp_instance', 'category' => 'WhatsApp', 'label' => 'Instância criada', 'description' => 'Instância Evolution cadastrada para a empresa.', 'route' => '/instances', 'weight' => 2],
            ['key' => 'evolution_connected', 'category' => 'WhatsApp', 'label' => 'Evolution conectada', 'description' => 'Instância em status conectado.', 'route' => '/instances', 'weight' => 3],
            ['key' => 'evolution_test', 'category' => 'WhatsApp', 'label' => 'Teste de envio', 'description' => 'Envio de teste registrado em auditoria.', 'route' => '/instances', 'weight' => 1],
            ['key' => 'agent_created', 'category' => 'IA', 'label' => 'Agente IA criado', 'description' => 'Pelo menos um agente IA cadastrado.', 'route' => '/agents', 'weight' => 2],
            ['key' => 'agent_active', 'category' => 'IA', 'label' => 'Agente IA ativo', 'description' => 'Agente ativo e pronto para responder.', 'route' => '/agents', 'weight' => 3],
            ['key' => 'ai_credentials', 'category' => 'IA', 'label' => 'Credencial IA', 'description' => 'Credencial da empresa ou chave global configurada.', 'route' => '/ai-credentials', 'weight' => 2],
            ['key' => 'menus_configured', 'category' => 'Painel', 'label' => 'Menus revisados', 'description' => 'Módulos/menus configurados para a empresa.', 'route' => '/company-settings', 'weight' => 1],
            ['key' => 'calendar_module', 'category' => 'Agenda', 'label' => 'Agenda habilitada', 'description' => 'Módulo Agenda disponível para a empresa.', 'route' => '/calendar', 'weight' => 1],
            ['key' => 'pre_schedule', 'category' => 'Agenda', 'label' => 'Pré-agendamento', 'description' => 'Opcional: fluxo de pré-agendamento por IA configurado.', 'route' => '/company-settings', 'weight' => 1],
            ['key' => 'n8n_flow', 'category' => 'Integrações', 'label' => 'Fluxo n8n', 'description' => 'Opcional: ao menos um fluxo n8n ativo por empresa.', 'route' => '/n8n-flows', 'weight' => 1],
            ['key' => 'subscription', 'category' => 'Comercial', 'label' => 'Assinatura', 'description' => 'Plano e assinatura vinculados à empresa.', 'route' => '/billing', 'weight' => 2],
            ['key' => 'invoice_or_gateway', 'category' => 'Comercial', 'label' => 'Cobrança/gateway', 'description' => 'Gateway ou cobrança inicial configurada.', 'route' => '/billing', 'weight' => 1],
            ['key' => 'lgpd_settings', 'category' => 'LGPD', 'label' => 'Política LGPD', 'description' => 'Política e termo configurados.', 'route' => '/privacy', 'weight' => 2],
            ['key' => 'lgpd_acceptance', 'category' => 'LGPD', 'label' => 'Aceite LGPD', 'description' => 'Aceite registrado para usuários da empresa.', 'route' => '/privacy', 'weight' => 2],
            ['key' => 'monitoring_health', 'category' => 'Operação', 'label' => 'Monitoramento', 'description' => 'Verificação operacional executada recentemente.', 'route' => '/monitoramento', 'weight' => 1],
            ['key' => 'backup_registered', 'category' => 'Operação', 'label' => 'Backup registrado', 'description' => 'Backup manual ou externo registrado no painel.', 'route' => '/monitoramento', 'weight' => 1],
        ];
    }

    /** @return array<string, mixed> */
    private function definitionByKey(string $key): array
    {
        foreach ($this->definitions() as $definition) {
            if ($definition['key'] === $key) {
                return $definition;
            }
        }
        return [];
    }

    /** @return array<int, array<string, string>> */
    private function quickActions(int $tenantId): array
    {
        return [
            ['label' => 'Dados da empresa', 'url' => '/companies?tenant_id=' . $tenantId],
            ['label' => 'Instâncias', 'url' => '/instances?tenant_id=' . $tenantId],
            ['label' => 'Agentes IA', 'url' => '/agents?tenant_id=' . $tenantId],
            ['label' => 'Conversas', 'url' => '/conversations?tenant_id=' . $tenantId],
            ['label' => 'Fluxos n8n', 'url' => '/n8n-flows?tenant_id=' . $tenantId],
            ['label' => 'Planos e cobrança', 'url' => '/billing?tenant_id=' . $tenantId],
            ['label' => 'Privacidade/LGPD', 'url' => '/privacy?tenant_id=' . $tenantId],
            ['label' => 'Monitoramento', 'url' => '/monitoramento'],
        ];
    }

    private function checklistTableForRead(): ?string
    {
        foreach (['tenant_implementation_checklist', 'tenant_implementation_checklist_items', 'tenant_implementation_checklists'] as $table) {
            if (!$this->tableExists($table)) {
                continue;
            }

            // Algumas instalações já possuíam tenant_implementation_checklists com outro formato
            // (status, evolution_webhook_configured, etc.). Essa tabela antiga não pode receber
            // itens manuais do checklist comercial.
            if ($this->columnExists($table, 'item_key') && $this->columnExists($table, 'manual_status')) {
                return $table;
            }
        }

        return null;
    }

    private function checklistTableForWrite(): string
    {
        $table = $this->checklistTableForRead();
        if ($table !== null) {
            return $table;
        }

        $this->ensureManualChecklistTable();
        return 'tenant_implementation_checklist';
    }

    private function ensureManualChecklistTable(): void
    {
        if ($this->tableExists('tenant_implementation_checklist')
            && $this->columnExists('tenant_implementation_checklist', 'item_key')
            && $this->columnExists('tenant_implementation_checklist', 'manual_status')) {
            return;
        }

        // Sem FKs para evitar erro de collation/tipo em bases que vieram de versões antigas.
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS tenant_implementation_checklist (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                tenant_id BIGINT UNSIGNED NOT NULL,
                item_key VARCHAR(100) COLLATE utf8mb4_unicode_ci NOT NULL,
                label VARCHAR(160) COLLATE utf8mb4_unicode_ci NOT NULL,
                category VARCHAR(80) COLLATE utf8mb4_unicode_ci NOT NULL,
                manual_status ENUM("auto","pending","complete","skipped","attention") COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT "auto",
                notes TEXT COLLATE utf8mb4_unicode_ci NULL,
                updated_by BIGINT UNSIGNED NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_impl_checklist_tenant_item (tenant_id, item_key),
                KEY idx_impl_checklist_tenant_status (tenant_id, manual_status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
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
}
