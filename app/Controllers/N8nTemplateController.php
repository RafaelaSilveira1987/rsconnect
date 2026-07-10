<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Env;
use App\Core\Router;
use App\Core\View;
use PDO;
use Throwable;

final class N8nTemplateController
{
    private const TEMPLATES = [
        'agenda-google-calendar' => [
            'title' => 'Agenda Google Calendar',
            'segment' => 'Agenda',
            'file' => 'template-agenda-google-calendar.json',
            'events' => ['calendar.appointment.created', 'calendar.appointment.status_updated'],
            'description' => 'Recebe compromissos do RS Connect, cria evento no Google Calendar do cliente e retorna o resultado ao callback do SaaS.',
        ],
        'crm-google-sheets' => [
            'title' => 'CRM para Google Sheets',
            'segment' => 'CRM',
            'file' => 'template-crm-google-sheets.json',
            'events' => ['crm.lead.created', 'crm.lead.updated', 'crm.lead.moved', 'message.received'],
            'description' => 'Espelha contatos, oportunidades e mensagens em uma planilha por cliente sem transformar a planilha em fonte principal.',
        ],
        'followup-alerta' => [
            'title' => 'Follow-up e Alertas',
            'segment' => 'Operação',
            'file' => 'template-followup-alerta.json',
            'events' => ['task.created', 'followup.due', 'crm.lead.updated'],
            'description' => 'Dispara lembretes internos para equipe comercial ou atendimento quando um follow-up precisa de ação.',
        ],
        'billing-cron' => [
            'title' => 'Cron da régua de cobrança',
            'segment' => 'Financeiro',
            'file' => 'template-billing-cron.json',
            'events' => ['billing.reminders.run', 'cron.daily'],
            'description' => 'Executa todos os dias a URL do cron do RS Connect para processar regras de cobrança sem ação manual.',
        ],
        'billing-disparo-mensagens' => [
            'title' => 'Disparo de cobrança por mensagem',
            'segment' => 'Financeiro',
            'file' => 'template-billing-whatsapp-email.json',
            'events' => ['billing.reminder.before_due', 'billing.reminder.due_today', 'billing.reminder.overdue', 'billing.subscription.suspended'],
            'description' => 'Recebe eventos billing.* por empresa, normaliza a cobrança, dispara mensagem externa e registra callback no RS Connect.',
        ],
    ];

    public function index(): void
    {
        $callbackUrl = Router::url('/webhooks/n8n/callback');
        $samplePayloads = $this->samplePayloads();
        $callbacks = [];

        try {
            $callbacks = Database::connection()->query(
                'SELECT c.*, t.name AS tenant_name, f.name AS flow_name
                 FROM n8n_flow_callback_logs c
                 LEFT JOIN tenants t ON t.id = c.tenant_id
                 LEFT JOIN n8n_tenant_flows f ON f.id = c.flow_id
                 ORDER BY c.created_at DESC
                 LIMIT 80'
            )->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            // Permite abrir a tela antes de rodar a migration 011.
        }

        View::render('n8n_templates.index', [
            'title' => 'Templates n8n',
            'templates' => self::TEMPLATES,
            'callbackUrl' => $callbackUrl,
            'callbackTokenConfigured' => trim((string) Env::get('N8N_CALLBACK_TOKEN', '')) !== '',
            'samplePayloads' => $samplePayloads,
            'callbacks' => $callbacks,
        ]);
    }

    public function download(): void
    {
        $key = trim((string) ($_GET['template'] ?? ''));
        $template = self::TEMPLATES[$key] ?? null;
        if ($template === null) {
            http_response_code(404);
            echo 'Template não encontrado.';
            return;
        }

        $path = dirname(__DIR__, 2) . '/docs/n8n_templates/' . $template['file'];
        if (!is_file($path)) {
            http_response_code(404);
            echo 'Arquivo do template não encontrado.';
            return;
        }

        header('Content-Type: application/json; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . basename($path) . '"');
        readfile($path);
    }

    public function callback(): void
    {
        $raw = file_get_contents('php://input') ?: '';
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            $payload = $_POST;
        }

        $configuredToken = trim((string) Env::get('N8N_CALLBACK_TOKEN', ''));
        if ($configuredToken !== '') {
            $headerToken = $_SERVER['HTTP_X_RS_CONNECT_TOKEN'] ?? '';
            $bearer = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
            $bodyToken = (string) ($payload['token'] ?? '');
            $valid = hash_equals($configuredToken, (string) $headerToken)
                || hash_equals('Bearer ' . $configuredToken, (string) $bearer)
                || hash_equals($configuredToken, $bodyToken);
            if (!$valid) {
                http_response_code(401);
                $this->json(['ok' => false, 'error' => 'Token de callback inválido.']);
                return;
            }
        }

        $tenantId = isset($payload['tenant_id']) ? (int) $payload['tenant_id'] : null;
        $flowId = isset($payload['flow_id']) ? (int) $payload['flow_id'] : null;
        $event = trim((string) ($payload['event'] ?? 'n8n.callback'));
        $status = (string) ($payload['status'] ?? 'info');
        if (!in_array($status, ['success', 'error', 'info'], true)) {
            $status = 'info';
        }
        $message = mb_substr((string) ($payload['message'] ?? $payload['detail'] ?? ''), 0, 700);
        $externalId = mb_substr((string) ($payload['external_id'] ?? $payload['google_event_id'] ?? ''), 0, 190);

        try {
            Database::connection()->prepare(
                'INSERT INTO n8n_flow_callback_logs
                    (tenant_id, flow_id, event, status, external_id, message, metadata_json)
                 VALUES
                    (:tenant_id, :flow_id, :event, :status, :external_id, :message, :metadata_json)'
            )->execute([
                'tenant_id' => $tenantId && $tenantId > 0 ? $tenantId : null,
                'flow_id' => $flowId && $flowId > 0 ? $flowId : null,
                'event' => $event,
                'status' => $status,
                'external_id' => $externalId !== '' ? $externalId : null,
                'message' => $message !== '' ? $message : null,
                'metadata_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        } catch (Throwable $exception) {
            http_response_code(500);
            $this->json(['ok' => false, 'error' => $exception->getMessage()]);
            return;
        }

        $this->json(['ok' => true, 'logged' => true]);
    }

    /** @return array<string,mixed> */
    private function samplePayloads(): array
    {
        return [
            'calendar.appointment.created' => [
                'event' => 'calendar.appointment.created',
                'source' => 'rs-connect',
                'tenant_id' => 1,
                'flow_id' => 1,
                'payload' => [
                    'tenant_id' => 1,
                    'appointment_id' => 15,
                    'title' => 'Reunião com lead',
                    'contact' => ['name' => 'Rafaela', 'phone' => '5532999999999'],
                    'start_time' => '2026-07-15T10:00:00-03:00',
                    'end_time' => '2026-07-15T10:50:00-03:00',
                    'meeting_url' => 'https://meet.google.com/xxx-yyyy-zzz',
                ],
                'callback' => ['url' => Router::url('/webhooks/n8n/callback')],
            ],
            'crm.lead.created' => [
                'event' => 'crm.lead.created',
                'source' => 'rs-connect',
                'tenant_id' => 1,
                'flow_id' => 2,
                'payload' => [
                    'tenant_id' => 1,
                    'lead_id' => 30,
                    'stage' => 'Novo',
                    'value' => 0,
                    'contact' => ['name' => 'Lead Teste', 'phone' => '5532988887777'],
                    'summary' => 'Lead pediu orçamento pelo WhatsApp.',
                ],
                'callback' => ['url' => Router::url('/webhooks/n8n/callback')],
            ],
            'billing.reminder.overdue' => [
                'event' => 'billing.reminder.overdue',
                'source' => 'rs-connect',
                'tenant_id' => 1,
                'flow_id' => 4,
                'tenant' => ['id' => 1, 'name' => 'Cliente Teste', 'email' => 'financeiro@cliente.com.br', 'phone' => '5532999999999'],
                'invoice' => ['id' => 50, 'number' => 'RS-202607-64550', 'amount' => 297.00, 'due_date' => '2026-07-08', 'status' => 'overdue', 'payment_url' => 'https://link-de-pagamento.example/checkout'],
                'rule' => ['label' => '2 dias após vencimento', 'days_from_due' => 2, 'event' => 'billing.reminder.overdue', 'channel' => 'whatsapp'],
                'message' => 'Olá, Cliente Teste. Identificamos que a cobrança RS-202607-64550 está em aberto há 2 dias. Link: https://link-de-pagamento.example/checkout',
                'callback' => ['url' => Router::url('/webhooks/n8n/callback')],
            ],
        ];
    }

    /** @param array<string,mixed> $payload */
    private function json(array $payload): void
    {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
