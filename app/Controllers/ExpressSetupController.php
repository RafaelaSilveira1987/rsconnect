<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\PublicId;
use App\Core\View;
use PDO;

/** Adaptador HTTP de leitura para o wizard. Não cria, altera ou ativa agentes. */
final class ExpressSetupController
{
    public function index(): void
    {
        $this->renderSetup('agent_express/index');
    }

    /** Interface de negócios disponível aos usuários de empresa em /agents. */
    public function businessDashboard(): void
    {
        $this->renderSetup('agents/business');
    }

    private function renderSetup(string $view): void
    {
        $tenantId = (int) (Auth::tenantId() ?? 0);
        if ($tenantId < 1 || Auth::isSuperAdmin()) {
            http_response_code(403);
            View::render('errors.404', ['title' => 'Acesso indisponível']);
            return;
        }

        $pdo = Database::connection();
        $profileQuery = $pdo->prepare('SELECT config_json FROM tenant_agent_profiles WHERE tenant_id=:tenant LIMIT 1');
        $profileQuery->execute(['tenant' => $tenantId]);
        $stored = json_decode((string) ($profileQuery->fetchColumn() ?: ''), true);
        $config = is_array($stored) ? $stored : [];
        $express = is_array($config['express'] ?? null) ? $config['express'] : [];
        $agentQuery = $pdo->prepare('SELECT id,name,is_default FROM ai_agents WHERE tenant_id=:tenant ORDER BY is_default DESC,id ASC');
        $agentQuery->execute(['tenant' => $tenantId]);
        $agents = array_map(static fn (array $a): array => [
            'id' => PublicId::encode('agent', (int) $a['id']),
            'name' => (string) $a['name'],
            'is_default' => (int) ($a['is_default'] ?? 0) === 1,
        ], $agentQuery->fetchAll(PDO::FETCH_ASSOC));

        $companyQuery = $pdo->prepare('SELECT name FROM tenants WHERE id=:tenant LIMIT 1');
        $companyQuery->execute(['tenant' => $tenantId]);
        $companyName = trim((string) ($companyQuery->fetchColumn() ?: 'sua empresa'));
        $customQuestions = [];
        foreach ((array) ($config['triage_fields'] ?? []) as $field) {
            if (!is_array($field) || !str_starts_with((string) ($field['key'] ?? ''), 'express_')) continue;
            if (is_string($field['prompt'] ?? null)) $customQuestions[] = $field['prompt'];
        }
        $capabilities = is_array($config['capabilities'] ?? null) ? $config['capabilities'] : [];
        $commercial = is_array($express['commercial'] ?? null) ? $express['commercial'] : [];
        $routing = is_array($express['routing'] ?? null) ? $express['routing'] : [];
        $hasSaved = $express !== [];
        $payload = [
            'business_info' => [
                'agent_name' => (string) ($express['agent_name'] ?? ($agents[0]['name'] ?? ('Assistente ' . $companyName))),
                'segment' => (string) ($express['segment'] ?? 'general_services'),
                'tone_of_voice' => (string) ($express['tone_of_voice'] ?? 'empathetic_and_welcoming'),
            ],
            'commercial_info' => [
                'pricing_strategy' => (string) ($commercial['pricing_strategy'] ?? 'on_request'),
                'price_value' => $commercial['price_value'] ?? null,
                'payment_methods' => (array) ($commercial['payment_methods'] ?? []),
            ],
            'triage_config' => [
                'collect_name' => $hasSaved ? (bool) array_filter((array) ($config['triage_fields'] ?? []),
                    static fn ($f) => is_array($f) && ($f['key'] ?? '') === 'requester_name') : true,
                'collect_phone' => $hasSaved ? (bool) array_filter((array) ($config['triage_fields'] ?? []),
                    static fn ($f) => is_array($f) && ($f['key'] ?? '') === 'contact_phone') : true,
                'custom_questions' => $customQuestions,
            ],
            'routing_and_schedule' => [
                'timezone' => (string) ($routing['timezone'] ?? 'America/Sao_Paulo'),
                'business_hours' => $routing['business_hours'] ?? [
                    ['day' => 'monday', 'start' => '08:00', 'end' => '18:00'],
                    ['day' => 'tuesday', 'start' => '08:00', 'end' => '18:00'],
                    ['day' => 'wednesday', 'start' => '08:00', 'end' => '18:00'],
                    ['day' => 'thursday', 'start' => '08:00', 'end' => '18:00'],
                    ['day' => 'friday', 'start' => '08:00', 'end' => '18:00'],
                ],
                'out_of_hours_action' => (string) ($routing['out_of_hours_action'] ?? 'capture_lead_and_promise_contact'),
                'default_meeting_link' => $routing['default_meeting_link'] ?? null,
            ],
            'knowledge_base' => ['faq' => (array) ($express['faq'] ?? [])],
            'capabilities' => [
                'allow_rescheduling' => (bool) ($capabilities['calendar.reschedule'] ?? false),
                'escalate_to_human_on_error' => (bool) ($capabilities['handoff.on_error'] ?? true),
                'send_meeting_link_on_confirmation' => (bool) ($capabilities['calendar.confirmation_link'] ?? false),
            ],
        ];
        View::render($view, [
            'title' => $view === 'agents/business' ? 'Meu atendimento' : 'Preparar meu atendimento',
            'expressAssets' => $view === 'agent_express/index',
            'businessDashboardAssets' => $view === 'agents/business',
            'setupPayload' => $payload,
            'setupAgents' => $agents,
            'setupHasSaved' => $hasSaved,
            'setupCanManage' => Auth::can('agents.manage'),
            'setupCanView' => Auth::can('agents.view'),
        ]);
    }
}
