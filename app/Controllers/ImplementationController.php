<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Flash;
use App\Core\Router;
use App\Core\View;
use PDO;
use Throwable;

final class ImplementationController
{
    private const MANUAL_FIELDS = [
        'evolution_webhook_configured',
        'n8n_agenda_configured',
        'n8n_billing_configured',
        'n8n_callback_tested',
        'payment_link_tested',
        'client_trained',
        'environment_validated',
        'implementation_completed',
    ];

    private const STATUS_LABELS = [
        'not_started' => 'Não iniciado',
        'in_progress' => 'Em implantação',
        'waiting_client' => 'Aguardando cliente',
        'waiting_rs' => 'Aguardando RS Connect',
        'completed' => 'Concluído',
    ];

    public function index(): void
    {
        $pdo = Database::connection();
        $tenants = $pdo->query(
            'SELECT t.*, c.status AS checklist_status, c.evolution_webhook_configured,
                    c.n8n_agenda_configured, c.n8n_billing_configured, c.n8n_callback_tested,
                    c.payment_link_tested, c.client_trained, c.environment_validated,
                    c.implementation_completed, c.notes AS checklist_notes, c.updated_at AS checklist_updated_at,
                    u.name AS checklist_updated_by
             FROM tenants t
             LEFT JOIN tenant_implementation_checklists c ON c.tenant_id = t.id
             LEFT JOIN users u ON u.id = c.updated_by_user_id
             ORDER BY FIELD(t.status, "active", "suspended", "inactive"), t.name'
        )->fetchAll(PDO::FETCH_ASSOC);

        $cards = [];
        $summary = [
            'total' => count($tenants),
            'completed' => 0,
            'in_progress' => 0,
            'critical' => 0,
            'avg_progress' => 0,
        ];

        foreach ($tenants as $tenant) {
            $card = $this->buildTenantCard($pdo, $tenant);
            $cards[] = $card;
            if ($card['is_completed']) {
                $summary['completed']++;
            } else {
                $summary['in_progress']++;
            }
            if ($card['critical_count'] > 0) {
                $summary['critical']++;
            }
            $summary['avg_progress'] += $card['progress'];
        }
        if ($summary['total'] > 0) {
            $summary['avg_progress'] = (int) round($summary['avg_progress'] / $summary['total']);
        }

        View::render('implementations.index', [
            'title' => 'Implantações',
            'cards' => $cards,
            'summary' => $summary,
            'statusLabels' => self::STATUS_LABELS,
        ]);
    }

    public function save(): void
    {
        $tenantId = (int) ($_POST['tenant_id'] ?? 0);
        $status = (string) ($_POST['status'] ?? 'in_progress');
        $notes = trim((string) ($_POST['notes'] ?? ''));

        if ($tenantId < 1) {
            Flash::set('error', 'Empresa não informada.');
            $this->redirect('/implementations');
        }
        if (!array_key_exists($status, self::STATUS_LABELS)) {
            $status = 'in_progress';
        }

        $values = [];
        foreach (self::MANUAL_FIELDS as $field) {
            $values[$field] = isset($_POST[$field]) ? 1 : 0;
        }
        if ($values['implementation_completed'] === 1) {
            $status = 'completed';
        }

        $pdo = Database::connection();
        $exists = $pdo->prepare('SELECT id FROM tenants WHERE id = :id LIMIT 1');
        $exists->execute(['id' => $tenantId]);
        if (!$exists->fetchColumn()) {
            Flash::set('error', 'Empresa não encontrada.');
            $this->redirect('/implementations');
        }

        try {
            $sql = 'INSERT INTO tenant_implementation_checklists
                        (tenant_id, status, evolution_webhook_configured, n8n_agenda_configured,
                         n8n_billing_configured, n8n_callback_tested, payment_link_tested,
                         client_trained, environment_validated, implementation_completed,
                         notes, updated_by_user_id)
                    VALUES
                        (:tenant_id, :status, :evolution_webhook_configured, :n8n_agenda_configured,
                         :n8n_billing_configured, :n8n_callback_tested, :payment_link_tested,
                         :client_trained, :environment_validated, :implementation_completed,
                         :notes, :updated_by_user_id)
                    ON DUPLICATE KEY UPDATE
                        status = VALUES(status),
                        evolution_webhook_configured = VALUES(evolution_webhook_configured),
                        n8n_agenda_configured = VALUES(n8n_agenda_configured),
                        n8n_billing_configured = VALUES(n8n_billing_configured),
                        n8n_callback_tested = VALUES(n8n_callback_tested),
                        payment_link_tested = VALUES(payment_link_tested),
                        client_trained = VALUES(client_trained),
                        environment_validated = VALUES(environment_validated),
                        implementation_completed = VALUES(implementation_completed),
                        notes = VALUES(notes),
                        updated_by_user_id = VALUES(updated_by_user_id),
                        updated_at = CURRENT_TIMESTAMP';
            $statement = $pdo->prepare($sql);
            $statement->execute([
                'tenant_id' => $tenantId,
                'status' => $status,
                'evolution_webhook_configured' => $values['evolution_webhook_configured'],
                'n8n_agenda_configured' => $values['n8n_agenda_configured'],
                'n8n_billing_configured' => $values['n8n_billing_configured'],
                'n8n_callback_tested' => $values['n8n_callback_tested'],
                'payment_link_tested' => $values['payment_link_tested'],
                'client_trained' => $values['client_trained'],
                'environment_validated' => $values['environment_validated'],
                'implementation_completed' => $values['implementation_completed'],
                'notes' => $notes !== '' ? $notes : null,
                'updated_by_user_id' => Auth::id(),
            ]);

            Audit::log('implementation.checklist_updated', ['tenant_id' => $tenantId, 'status' => $status], $tenantId);
            Flash::set('success', 'Checklist de implantação atualizado.');
        } catch (Throwable $exception) {
            Flash::set('error', 'Não foi possível salvar o checklist: ' . $exception->getMessage());
        }

        $this->redirect('/implementations');
    }

    private function buildTenantCard(PDO $pdo, array $tenant): array
    {
        $tenantId = (int) $tenant['id'];

        $manual = [
            'status' => (string) ($tenant['checklist_status'] ?: 'in_progress'),
            'evolution_webhook_configured' => (int) ($tenant['evolution_webhook_configured'] ?? 0),
            'n8n_agenda_configured' => (int) ($tenant['n8n_agenda_configured'] ?? 0),
            'n8n_billing_configured' => (int) ($tenant['n8n_billing_configured'] ?? 0),
            'n8n_callback_tested' => (int) ($tenant['n8n_callback_tested'] ?? 0),
            'payment_link_tested' => (int) ($tenant['payment_link_tested'] ?? 0),
            'client_trained' => (int) ($tenant['client_trained'] ?? 0),
            'environment_validated' => (int) ($tenant['environment_validated'] ?? 0),
            'implementation_completed' => (int) ($tenant['implementation_completed'] ?? 0),
        ];

        $counts = $this->tenantCounts($pdo, $tenantId);
        $hasInboundMessage = $counts['incoming_messages'] > 0;
        $hasEvolutionTest = $counts['evolution_test_sent'] > 0;
        $hasN8nSuccess = $counts['n8n_success_logs'] > 0;
        $hasN8nCallback = $counts['n8n_callback_success_logs'] > 0;
        $hasPaymentLink = $counts['payment_links'] > 0;

        $sections = [
            'WhatsApp / Evolution' => [
                $this->item('Instância criada', $counts['instances_total'] > 0, 'Crie ou vincule uma instância Evolution para a empresa.', 'critical'),
                $this->item('QR Code conectado', $counts['instances_connected'] > 0, 'O cliente pode gerar o QR Code em Instâncias e conectar o WhatsApp.', 'critical'),
                $this->item('Webhook Evolution configurado', $manual['evolution_webhook_configured'] === 1 || $hasInboundMessage, 'Configure o webhook da Evolution apontando para o RS Connect.', 'warning', true, 'evolution_webhook_configured'),
                $this->item('Teste de envio realizado', $hasEvolutionTest, 'Use o botão de teste na tela de Instâncias.', 'warning'),
            ],
            'IA' => [
                $this->item('Agente criado', $counts['agents_total'] > 0, 'Crie um agente IA para a empresa.', 'critical'),
                $this->item('Prompt configurado pelo cliente', $counts['prompt_configured'] > 0 || !empty($tenant['onboarding_assistant_prompt_completed_at']), 'Oriente o cliente a usar a Configuração inicial e gerar o prompt.', 'warning'),
                $this->item('Credencial de IA configurada', $counts['ai_credentials_active'] > 0, 'Cadastre uma credencial de IA no painel RS, ou mantenha a chave global no .env.', 'warning'),
                $this->item('Resposta automática ativa', $counts['agents_auto_reply'] > 0, 'Ative a resposta automática no agente.', 'warning'),
            ],
            'n8n' => [
                $this->item('Fluxo de agenda configurado', $manual['n8n_agenda_configured'] === 1 || $counts['n8n_agenda_flows'] > 0, 'Cadastre o fluxo de agenda em Fluxos n8n.', 'info', true, 'n8n_agenda_configured'),
                $this->item('Fluxo de cobrança configurado', $manual['n8n_billing_configured'] === 1 || $counts['n8n_billing_flows'] > 0, 'Cadastre o fluxo billing.* para cobranças.', 'info', true, 'n8n_billing_configured'),
                $this->item('Callback testado', $manual['n8n_callback_tested'] === 1 || $hasN8nCallback, 'Teste o retorno do n8n para o endpoint de callback.', 'info', true, 'n8n_callback_tested'),
                $this->item('Último teste n8n com sucesso', $hasN8nSuccess, 'Use o botão Testar em Fluxos n8n.', 'info'),
            ],
            'Cobrança' => [
                $this->item('Plano vinculado', $counts['subscriptions_total'] > 0, 'Vincule um plano em Planos e cobrança.', 'warning'),
                $this->item('Gateway configurado', $counts['payment_gateways_active'] > 0, 'Configure um gateway ativo no painel RS.', 'warning'),
                $this->item('Link de pagamento testado', $manual['payment_link_tested'] === 1 || $hasPaymentLink, 'Gere um link de cobrança em Gateways de pagamento.', 'info', true, 'payment_link_tested'),
                $this->item('Régua de cobrança ativa', $counts['billing_rules_active'] > 0, 'Mantenha regras ativas na Régua de cobrança.', 'info'),
            ],
            'Finalização' => [
                $this->item('Cliente treinado', $manual['client_trained'] === 1, 'Registre quando o cliente já recebeu orientação de uso.', 'info', true, 'client_trained'),
                $this->item('Ambiente validado', $manual['environment_validated'] === 1, 'Marque após validar atendimento, IA, agenda, cobrança e n8n.', 'warning', true, 'environment_validated'),
                $this->item('Implantação concluída', $manual['implementation_completed'] === 1, 'Finalize quando todos os pontos críticos estiverem resolvidos.', 'critical', true, 'implementation_completed'),
            ],
        ];

        $items = [];
        foreach ($sections as $sectionItems) {
            foreach ($sectionItems as $item) {
                $items[] = $item;
            }
        }

        $done = count(array_filter($items, static fn (array $item): bool => $item['done']));
        $total = count($items);
        $progress = $total > 0 ? (int) round(($done / $total) * 100) : 0;
        $criticalCount = count(array_filter($items, static fn (array $item): bool => !$item['done'] && $item['severity'] === 'critical'));
        $warningCount = count(array_filter($items, static fn (array $item): bool => !$item['done'] && $item['severity'] === 'warning'));
        $isCompleted = $manual['implementation_completed'] === 1 || ($progress >= 100 && $criticalCount === 0);
        $autoStatus = $isCompleted ? 'completed' : ($criticalCount > 0 ? 'waiting_rs' : 'in_progress');
        $status = $manual['status'] !== 'in_progress' || $tenant['checklist_status'] ? $manual['status'] : $autoStatus;

        return [
            'tenant' => $tenant,
            'manual' => $manual,
            'sections' => $sections,
            'progress' => $progress,
            'done_count' => $done,
            'total_count' => $total,
            'critical_count' => $criticalCount,
            'warning_count' => $warningCount,
            'is_completed' => $isCompleted,
            'status' => $status,
            'status_label' => self::STATUS_LABELS[$status] ?? $status,
            'notes' => (string) ($tenant['checklist_notes'] ?? ''),
            'updated_at' => $tenant['checklist_updated_at'] ?? null,
            'updated_by' => $tenant['checklist_updated_by'] ?? null,
        ];
    }

    private function tenantCounts(PDO $pdo, int $tenantId): array
    {
        $queries = [
            'instances_total' => 'SELECT COUNT(*) FROM evolution_instances WHERE tenant_id = :tenant_id',
            'instances_connected' => 'SELECT COUNT(*) FROM evolution_instances WHERE tenant_id = :tenant_id AND status = "connected"',
            'incoming_messages' => 'SELECT COUNT(*) FROM conversation_messages WHERE tenant_id = :tenant_id AND direction = "incoming"',
            'evolution_test_sent' => 'SELECT COUNT(*) FROM audit_logs WHERE tenant_id = :tenant_id AND action = "evolution.test_sent"',
            'agents_total' => 'SELECT COUNT(*) FROM ai_agents WHERE tenant_id = :tenant_id',
            'prompt_configured' => 'SELECT COUNT(*) FROM ai_agents WHERE tenant_id = :tenant_id AND prompt_builder_json IS NOT NULL',
            'ai_credentials_active' => 'SELECT COUNT(*) FROM ai_provider_credentials WHERE tenant_id = :tenant_id AND status = "active"',
            'agents_auto_reply' => 'SELECT COUNT(*) FROM ai_agents WHERE tenant_id = :tenant_id AND status = "active" AND auto_reply_enabled = 1',
            'n8n_agenda_flows' => 'SELECT COUNT(*) FROM n8n_tenant_flows WHERE tenant_id = :tenant_id AND status = "active" AND (flow_key LIKE "%agenda%" OR events_json LIKE "%calendar.appointment%")',
            'n8n_billing_flows' => 'SELECT COUNT(*) FROM n8n_tenant_flows WHERE tenant_id = :tenant_id AND status = "active" AND (flow_key LIKE "%billing%" OR flow_key LIKE "%cobranca%" OR events_json LIKE "%billing.%")',
            'n8n_success_logs' => 'SELECT COUNT(*) FROM n8n_flow_logs WHERE tenant_id = :tenant_id AND status = "success"',
            'n8n_callback_success_logs' => 'SELECT COUNT(*) FROM n8n_flow_callback_logs WHERE tenant_id = :tenant_id AND status = "success"',
            'subscriptions_total' => 'SELECT COUNT(*) FROM tenant_subscriptions WHERE tenant_id = :tenant_id AND billing_status IN ("trialing", "active", "overdue")',
            'payment_links' => 'SELECT COUNT(*) FROM tenant_invoices WHERE tenant_id = :tenant_id AND (external_checkout_url IS NOT NULL OR external_invoice_url IS NOT NULL OR payment_link_created_at IS NOT NULL)',
            'payment_gateways_active' => 'SELECT COUNT(*) FROM payment_gateways WHERE status = "active"',
            'billing_rules_active' => 'SELECT COUNT(*) FROM billing_reminder_rules WHERE status = "active"',
        ];

        $counts = [];
        foreach ($queries as $key => $sql) {
            try {
                $statement = $pdo->prepare($sql);
                if (str_contains($sql, ':tenant_id')) {
                    $statement->execute(['tenant_id' => $tenantId]);
                } else {
                    $statement->execute();
                }
                $counts[$key] = (int) $statement->fetchColumn();
            } catch (Throwable) {
                $counts[$key] = 0;
            }
        }
        return $counts;
    }

    private function item(string $label, bool $done, string $hint, string $severity, bool $manual = false, ?string $field = null): array
    {
        return compact('label', 'done', 'hint', 'severity', 'manual', 'field');
    }

    private function redirect(string $path): never
    {
        header('Location: ' . Router::url($path));
        exit;
    }
}
