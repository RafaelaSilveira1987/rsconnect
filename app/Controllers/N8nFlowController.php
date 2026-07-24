<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Crypto;
use App\Core\Database;
use App\Core\Flash;
use App\Core\Router;
use App\Core\View;
use App\Services\AutomationWebhookService;
use App\Services\SubscriptionService;
use PDO;
use Throwable;

final class N8nFlowController
{
    private const EVENT_OPTIONS = [
        'message.received' => 'Mensagem recebida do lead',
        'ai.replied' => 'IA respondeu automaticamente',
        'calendar.appointment.created' => 'Agendamento criado',
        'calendar.appointment.status_updated' => 'Status do agendamento alterado',
        'crm.lead.created' => 'Oportunidade criada no CRM',
        'crm.lead.updated' => 'Oportunidade atualizada',
        'crm.lead.moved' => 'Oportunidade movida no funil',
        'operations.backup.requested' => 'Backup solicitado pelo RS Connect',
        '*' => 'Todos os eventos',
    ];

    public function hub(): void
    {
        $pdo = Database::connection();
        $metrics = [
            'flows_total' => 0,
            'flows_active' => 0,
            'tenants_covered' => 0,
            'executions_24h' => 0,
            'success_24h' => 0,
            'errors_24h' => 0,
        ];
        $recentLogs = [];

        try {
            $metrics['flows_total'] = (int) $pdo->query('SELECT COUNT(*) FROM n8n_tenant_flows')->fetchColumn();
            $metrics['flows_active'] = (int) $pdo->query("SELECT COUNT(*) FROM n8n_tenant_flows WHERE status = 'active'")->fetchColumn();
            $metrics['tenants_covered'] = (int) $pdo->query("SELECT COUNT(DISTINCT tenant_id) FROM n8n_tenant_flows WHERE status = 'active'")->fetchColumn();
            $metrics['executions_24h'] = (int) $pdo->query("SELECT COUNT(*) FROM n8n_flow_logs WHERE created_at >= (NOW() - INTERVAL 24 HOUR)")->fetchColumn();
            $metrics['success_24h'] = (int) $pdo->query("SELECT COUNT(*) FROM n8n_flow_logs WHERE status = 'success' AND created_at >= (NOW() - INTERVAL 24 HOUR)")->fetchColumn();
            $metrics['errors_24h'] = (int) $pdo->query("SELECT COUNT(*) FROM n8n_flow_logs WHERE status = 'error' AND created_at >= (NOW() - INTERVAL 24 HOUR)")->fetchColumn();
            $recentLogs = $pdo->query(
                'SELECT l.*, f.name AS flow_name, t.name AS tenant_name
                 FROM n8n_flow_logs l
                 LEFT JOIN n8n_tenant_flows f ON f.id = l.flow_id
                 INNER JOIN tenants t ON t.id = l.tenant_id
                 ORDER BY l.created_at DESC LIMIT 12'
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            // Mantém o hub acessível mesmo antes de todas as migrations.
        }

        View::render('n8n_flows.hub', [
            'title' => 'n8n',
            'metrics' => $metrics,
            'recentLogs' => $recentLogs,
        ]);
    }

    public function index(): void
    {
        $pdo = Database::connection();
        $tenants = $pdo->query('SELECT id, name, status FROM tenants ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);

        $flows = $pdo->query(
            'SELECT f.*, t.name AS tenant_name, u.name AS creator_name
             FROM n8n_tenant_flows f
             INNER JOIN tenants t ON t.id = f.tenant_id
             LEFT JOIN users u ON u.id = f.created_by_user_id
             ORDER BY t.name, f.status, f.flow_key'
        )->fetchAll(PDO::FETCH_ASSOC);

        foreach ($flows as &$flow) {
            $flow['webhook_url_masked'] = $this->maskUrl(Crypto::decrypt((string) $flow['webhook_url_encrypted']));
            $flow['secret_masked'] = !empty($flow['secret_token_encrypted']) ? '••••••••••••' : 'Não informado';
            $events = json_decode((string) ($flow['events_json'] ?? ''), true);
            $flow['events_label'] = $this->eventsLabel(is_array($events) ? $events : []);
            unset($flow['webhook_url_encrypted'], $flow['secret_token_encrypted']);
        }
        unset($flow);

        $logs = $pdo->query(
            'SELECT l.*, f.name AS flow_name, t.name AS tenant_name
             FROM n8n_flow_logs l
             LEFT JOIN n8n_tenant_flows f ON f.id = l.flow_id
             INNER JOIN tenants t ON t.id = l.tenant_id
             ORDER BY l.created_at DESC
             LIMIT 120'
        )->fetchAll(PDO::FETCH_ASSOC);

        View::render('n8n_flows.index', [
            'title' => 'Fluxos n8n por empresa',
            'tenants' => $tenants,
            'flows' => $flows,
            'logs' => $logs,
            'eventOptions' => self::EVENT_OPTIONS,
        ]);
    }

    public function save(): void
    {
        $id = (int) ($_POST['id'] ?? 0);
        $tenantId = (int) ($_POST['tenant_id'] ?? 0);
        $flowKey = $this->slug(trim((string) ($_POST['flow_key'] ?? '')));
        $name = trim((string) ($_POST['name'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $webhookUrl = trim((string) ($_POST['webhook_url'] ?? ''));
        $secretToken = trim((string) ($_POST['secret_token'] ?? ''));
        $status = (string) ($_POST['status'] ?? 'active');
        $events = $_POST['events'] ?? [];
        $events = is_array($events) ? array_values(array_filter(array_map('strval', $events))) : [];

        if ($tenantId < 1 || $flowKey === '' || $name === '') {
            Flash::set('error', 'Informe empresa, identificador do fluxo e nome.');
            $this->redirect('/n8n-flows');
        }
        if ($id < 1 && ($webhookUrl === '' || !filter_var($webhookUrl, FILTER_VALIDATE_URL))) {
            Flash::set('error', 'Informe uma URL válida do webhook n8n.');
            $this->redirect('/n8n-flows');
        }
        if ($webhookUrl !== '' && !filter_var($webhookUrl, FILTER_VALIDATE_URL)) {
            Flash::set('error', 'URL do webhook n8n inválida.');
            $this->redirect('/n8n-flows');
        }
        if (!in_array($status, ['active', 'inactive'], true)) {
            $status = 'active';
        }
        if ($events === []) {
            $events = ['*'];
        }

        $pdo = Database::connection();
        if ($id < 1) {
            $limit = (new SubscriptionService())->ensureCanCreate($tenantId, 'n8n_flows');
            if (empty($limit['ok'])) {
                Flash::set('error', $limit['message']);
                $this->redirect('/n8n-flows');
            }
        }
        try {
            $pdo->beginTransaction();
            if ($id > 0) {
                $sql = 'UPDATE n8n_tenant_flows
                        SET tenant_id = :tenant_id,
                            flow_key = :flow_key,
                            name = :name,
                            description = :description,
                            events_json = :events_json,
                            status = :status';
                $params = [
                    'tenant_id' => $tenantId,
                    'flow_key' => $flowKey,
                    'name' => $name,
                    'description' => $description !== '' ? $description : null,
                    'events_json' => json_encode($events, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'status' => $status,
                    'id' => $id,
                ];
                if ($webhookUrl !== '') {
                    $sql .= ', webhook_url_encrypted = :webhook_url';
                    $params['webhook_url'] = Crypto::encrypt($webhookUrl);
                }
                if ($secretToken !== '') {
                    $sql .= ', secret_token_encrypted = :secret_token';
                    $params['secret_token'] = Crypto::encrypt($secretToken);
                }
                $sql .= ' WHERE id = :id';
                $statement = $pdo->prepare($sql);
                $statement->execute($params);
                Audit::log('n8n.flow_updated', ['flow_id' => $id, 'flow_key' => $flowKey], $tenantId);
            } else {
                $statement = $pdo->prepare(
                    'INSERT INTO n8n_tenant_flows
                        (tenant_id, flow_key, name, description, webhook_url_encrypted, secret_token_encrypted,
                         events_json, status, created_by_user_id)
                     VALUES
                        (:tenant_id, :flow_key, :name, :description, :webhook_url, :secret_token,
                         :events_json, :status, :created_by_user_id)'
                );
                $statement->execute([
                    'tenant_id' => $tenantId,
                    'flow_key' => $flowKey,
                    'name' => $name,
                    'description' => $description !== '' ? $description : null,
                    'webhook_url' => Crypto::encrypt($webhookUrl),
                    'secret_token' => $secretToken !== '' ? Crypto::encrypt($secretToken) : null,
                    'events_json' => json_encode($events, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'status' => $status,
                    'created_by_user_id' => Auth::id(),
                ]);
                $id = (int) $pdo->lastInsertId();
                Audit::log('n8n.flow_created', ['flow_id' => $id, 'flow_key' => $flowKey], $tenantId);
            }

            $pdo->commit();
            Flash::set('success', 'Fluxo n8n salvo para a empresa selecionada.');
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $message = str_contains($exception->getMessage(), 'uq_n8n_flow_tenant_key') || str_contains($exception->getMessage(), 'Duplicate')
                ? 'Já existe um fluxo com esse identificador para esta empresa.'
                : 'Não foi possível salvar o fluxo: ' . $exception->getMessage();
            Flash::set('error', $message);
        }

        $this->redirect('/n8n-flows');
    }

    public function test(): void
    {
        $flowId = (int) ($_POST['flow_id'] ?? 0);
        $pdo = Database::connection();
        $statement = $pdo->prepare('SELECT * FROM n8n_tenant_flows WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $flowId]);
        $flow = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$flow) {
            Flash::set('error', 'Fluxo n8n não encontrado.');
            $this->redirect('/n8n-flows');
        }

        $results = (new AutomationWebhookService())->dispatch('n8n.flow.test', [
            'tenant_id' => (int) $flow['tenant_id'],
            'flow_id' => (int) $flow['id'],
            'flow_key' => (string) $flow['flow_key'],
            'message' => 'Teste enviado pelo painel RS Connect.',
        ], Crypto::decrypt((string) $flow['webhook_url_encrypted']), (int) $flow['tenant_id']);

        $first = $results[0] ?? ['ok' => false, 'error' => 'Sem retorno.'];
        Flash::set(!empty($first['ok']) ? 'success' : 'error', !empty($first['ok']) ? 'Teste enviado ao n8n com sucesso.' : 'Falha no teste n8n: ' . ($first['error'] ?? 'erro desconhecido'));
        $this->redirect('/n8n-flows');
    }

    private function eventsLabel(array $events): string
    {
        if ($events === [] || in_array('*', $events, true) || in_array('all', $events, true)) {
            return 'Todos os eventos';
        }
        $labels = [];
        foreach ($events as $event) {
            $labels[] = self::EVENT_OPTIONS[(string) $event] ?? (string) $event;
        }
        return implode(', ', $labels);
    }

    private function slug(string $value): string
    {
        $value = mb_strtolower($value);
        $value = preg_replace('/[^a-z0-9_.-]+/i', '-', $value) ?: '';
        return trim($value, '-');
    }

    private function maskUrl(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return mb_substr($url, 0, 500);
        }
        return mb_substr($parts['scheme'] . '://' . $parts['host'] . ($parts['path'] ?? ''), 0, 500);
    }

    private function redirect(string $path): never
    {
        header('Location: ' . Router::url($path));
        exit;
    }
}
