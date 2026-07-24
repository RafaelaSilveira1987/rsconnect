<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Env;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;

/**
 * Fonte única para a nova leitura operacional.
 *
 * A Central de operação continua responsável pelos diagnósticos técnicos.
 * Este serviço apenas normaliza as evidências já produzidas pelo RS Connect
 * para uma linguagem operacional consistente e conservadora: ausência de erro
 * nunca é convertida automaticamente em "Operando".
 */
final class OperationalHealthService
{
    private const SERVICE_DEFINITIONS = [
        'database' => [
            'label' => 'Banco de dados',
            'category' => 'Sistema',
            'route' => '/central-operacao?tab=status',
            'fresh_minutes' => 15,
            'impact' => 'Uma indisponibilidade do banco pode interromper todos os módulos do RS Connect.',
            'action' => 'Revisar conexão e credenciais do banco.',
        ],
        'migrations' => [
            'label' => 'Estrutura e migrations',
            'category' => 'Sistema',
            'route' => '/central-operacao?tab=status',
            'fresh_minutes' => 1440,
            'impact' => 'Estruturas ausentes podem deixar recursos incompletos ou inconsistentes.',
            'action' => 'Aplicar as migrations pendentes e validar novamente.',
        ],
        'evolution' => [
            'label' => 'WhatsApp / Evolution',
            'category' => 'Integrações',
            'route' => '/instances',
            'fresh_minutes' => 15,
            'impact' => 'Mensagens podem deixar de entrar ou sair enquanto a conexão estiver degradada.',
            'action' => 'Abrir as conexões e confirmar o estado das instâncias.',
        ],
        'openai' => [
            'label' => 'OpenAI / IA',
            'category' => 'Integrações',
            'route' => '/ai-credentials',
            'fresh_minutes' => 60,
            'impact' => 'Assistentes podem não gerar respostas enquanto o provedor estiver indisponível.',
            'action' => 'Revisar credenciais e a última falha do provedor.',
        ],
        'n8n' => [
            'label' => 'n8n',
            'category' => 'Integrações',
            'route' => '/n8n',
            'fresh_minutes' => 60,
            'impact' => 'Automações externas podem deixar de executar ou retornar callbacks.',
            'action' => 'Abrir o módulo n8n e conferir as últimas execuções.',
        ],
        'webhooks' => [
            'label' => 'Webhooks e mensagens',
            'category' => 'Integrações',
            'route' => '/conversations',
            'fresh_minutes' => 60,
            'impact' => 'Eventos externos podem não chegar corretamente ao RS Connect.',
            'action' => 'Validar o tráfego recente de mensagens e callbacks.',
        ],
        'calendar' => [
            'label' => 'Google Agenda',
            'category' => 'Integrações',
            'route' => '/calendar/availability',
            'fresh_minutes' => 360,
            'impact' => 'Disponibilidade e sincronização de agendamentos podem ficar desatualizadas.',
            'action' => 'Conferir a última sincronização e as configurações da agenda.',
        ],
        'payments' => [
            'label' => 'Gateways e pagamentos',
            'category' => 'Integrações',
            'route' => '/payment-gateways',
            'fresh_minutes' => 360,
            'impact' => 'Atualizações financeiras podem deixar de ser conciliadas.',
            'action' => 'Revisar gateway, eventos recentes e callbacks financeiros.',
        ],
        'billing_cron' => [
            'label' => 'Cron de cobrança',
            'category' => 'Rotinas',
            'route' => '/billing-reminders',
            'fresh_minutes' => 360,
            'impact' => 'A régua pode deixar de processar cobranças no período esperado.',
            'action' => 'Conferir o workflow n8n e a última execução automática.',
        ],
        'ai_reprocess' => [
            'label' => 'Fila da IA',
            'category' => 'Rotinas',
            'route' => '/central-operacao?tab=ai_reprocess',
            'fresh_minutes' => 360,
            'impact' => 'Mensagens pendentes podem ficar sem nova tentativa automática.',
            'action' => 'Abrir a fila e identificar se há falha interna ou dependência externa.',
        ],
        'reporting' => [
            'label' => 'Relatórios',
            'category' => 'Rotinas',
            'route' => '/reports',
            'fresh_minutes' => 720,
            'impact' => 'Indicadores podem ficar defasados enquanto a agregação não atualizar.',
            'action' => 'Validar a última agregação e o preenchimento das métricas.',
        ],
        'backup' => [
            'label' => 'Backup',
            'category' => 'Rotinas',
            'route' => '/central-operacao?tab=backups',
            'fresh_minutes' => 240,
            'impact' => 'A capacidade de recuperação fica mais arriscada quando o backup não está atualizado.',
            'action' => 'Executar ou revisar a rotina de backup.',
        ],
    ];

    public function dashboard(): array
    {
        $operations = (new OperationsService())->dashboard();
        $ai = (new AiReprocessService())->dashboard();
        $backup = (new BackupAutomationService())->dashboard();

        $services = $this->normalizeServices($operations['checks'] ?? []);
        $externalBlocks = $this->externalBlocks($ai);
        $this->applyExternalContextToServices($services, $externalBlocks, $ai);

        $verification = $this->verificationState($services);
        $issues = $this->activeIssues($services, $externalBlocks, $ai);
        $companies = $this->companyOverview($ai);
        $routines = $this->routineOverview($services, $ai, $backup);
        $summary = $this->summary($services, $issues, $companies, $verification);
        $history = $this->recentHealthHistory();

        return [
            'verification' => $verification,
            'summary' => $summary,
            'issues' => $issues,
            'services' => $services,
            'routines' => $routines,
            'companies' => $companies,
            'history' => $history,
            'technical' => [
                'pending_ai' => (int) ($ai['pending_total'] ?? 0),
                'blocked_ai' => (int) ($ai['pending_blocked_total'] ?? 0),
                'raw_checks' => count($operations['checks'] ?? []),
            ],
        ];
    }

    private function normalizeServices(array $checks): array
    {
        $byKey = [];
        foreach ($checks as $check) {
            $key = trim((string) ($check['check_key'] ?? ''));
            if ($key !== '') {
                $byKey[$key] = $check;
            }
        }

        $services = [];
        $now = time();
        foreach (self::SERVICE_DEFINITIONS as $key => $definition) {
            $check = $byKey[$key] ?? [];
            $rawStatus = strtolower(trim((string) ($check['status'] ?? 'unknown')));
            $checkedAt = trim((string) ($check['checked_at'] ?? ''));
            $checkedTs = $checkedAt !== '' ? (strtotime($checkedAt) ?: 0) : 0;
            $freshSeconds = max(60, (int) $definition['fresh_minutes'] * 60);
            $ageSeconds = $checkedTs > 0 ? max(0, $now - $checkedTs) : null;
            $stale = $checkedTs === 0 || ($ageSeconds !== null && $ageSeconds > $freshSeconds);

            $status = match ($rawStatus) {
                'ok' => $stale ? 'unknown' : 'operational',
                'down' => $stale ? 'unknown' : 'critical',
                'warning' => $stale ? 'unknown' : 'attention',
                default => 'unknown',
            };

            $rawMessage = trim((string) ($check['message'] ?? ''));
            $evidence = $this->friendlyEvidence($key, $rawMessage, $status, $stale);
            $freshUntil = $checkedTs > 0 ? date('Y-m-d H:i:s', $checkedTs + $freshSeconds) : null;

            $services[$key] = [
                'key' => $key,
                'label' => $definition['label'],
                'category' => $definition['category'],
                'status' => $status,
                'raw_status' => $rawStatus,
                'evidence' => $evidence,
                'technical_details' => $rawMessage,
                'checked_at' => $checkedAt,
                'checked_ts' => $checkedTs,
                'fresh_until' => $freshUntil,
                'fresh_minutes' => (int) $definition['fresh_minutes'],
                'age_seconds' => $ageSeconds,
                'age_label' => $checkedTs > 0 ? $this->ageLabel($ageSeconds ?? 0) : 'nunca verificado',
                'stale' => $stale,
                'latency_ms' => isset($check['latency_ms']) && $check['latency_ms'] !== null ? (int) $check['latency_ms'] : null,
                'route' => $definition['route'],
                'impact' => $definition['impact'],
                'recommended_action' => $definition['action'],
            ];
        }

        return $services;
    }

    private function verificationState(array $services): array
    {
        $checked = [];
        $missing = 0;
        $stale = 0;
        foreach ($services as $service) {
            $ts = (int) ($service['checked_ts'] ?? 0);
            if ($ts <= 0) {
                $missing++;
                continue;
            }
            $checked[] = $ts;
            if (!empty($service['stale'])) {
                $stale++;
            }
        }

        $latest = $checked !== [] ? max($checked) : 0;
        $oldest = $checked !== [] ? min($checked) : 0;
        $sameRun = $checked !== [] && count($checked) === count($services) && ($latest - $oldest) <= 300;
        $complete = $sameRun && $missing === 0 && $stale === 0;

        if ($complete) {
            return [
                'state' => 'complete',
                'label' => 'Verificação completa',
                'message' => 'Todos os serviços possuem evidência atual dentro da janela esperada.',
                'last_checked_at' => date('Y-m-d H:i:s', $latest),
                'last_checked_label' => $this->ageLabel(max(0, time() - $latest)),
                'missing' => 0,
                'stale' => 0,
            ];
        }

        if ($checked === []) {
            return [
                'state' => 'unverified',
                'label' => 'Ainda não verificado',
                'message' => 'Execute uma verificação para produzir evidências antes de interpretar a saúde do sistema.',
                'last_checked_at' => '',
                'last_checked_label' => 'sem evidência',
                'missing' => count($services),
                'stale' => 0,
            ];
        }

        return [
            'state' => 'partial',
            'label' => 'Evidência parcial',
            'message' => 'Há serviços sem verificação recente. O painel não considera ausência de evidência como sucesso.',
            'last_checked_at' => date('Y-m-d H:i:s', $latest),
            'last_checked_label' => $this->ageLabel(max(0, time() - $latest)),
            'missing' => $missing,
            'stale' => $stale,
        ];
    }

    private function externalBlocks(array $ai): array
    {
        $blocks = [];
        foreach (($ai['pending_instances'] ?? []) as $pending) {
            $pendingCount = (int) ($pending['pending_count'] ?? 0);
            if ($pendingCount < 1) {
                continue;
            }

            $state = strtolower(trim((string) (($pending['connection_state'] ?? '') ?: ($pending['instance_status'] ?? ''))));
            if (in_array($state, ['open', 'connected', 'active', 'online'], true)) {
                continue;
            }

            $tenantId = (int) ($pending['tenant_id'] ?? 0);
            $tenantName = trim((string) ($pending['tenant_name'] ?? 'Empresa')) ?: 'Empresa';
            $instance = trim((string) (($pending['instance_label'] ?? '') ?: ($pending['instance_name'] ?? '')));
            $agent = trim((string) ($pending['agent_name'] ?? ''));
            $checkedAt = trim((string) ($pending['last_status_check_at'] ?? ''));

            $blocks[] = [
                'key' => 'external-evolution-' . $tenantId . '-' . (int) ($pending['instance_id'] ?? 0),
                'status' => 'blocked',
                'tenant_id' => $tenantId,
                'tenant_name' => $tenantName,
                'title' => 'WhatsApp de ' . $tenantName . ' aguarda reconexão',
                'summary' => $pendingCount . ' conversa(s) permanecem preservadas na fila.',
                'impact' => 'O atendimento automático dessas conversas está suspenso até a Evolution confirmar a conexão.',
                'recommended_action' => 'Reconectar a instância ' . ($instance !== '' ? $instance : 'WhatsApp') . '.',
                'meta' => trim(($instance !== '' ? 'Instância: ' . $instance : '') . ($agent !== '' ? ($instance !== '' ? ' · ' : '') . 'Assistente: ' . $agent : '')),
                'evidence_at' => $checkedAt,
                'action_label' => 'Abrir WhatsApp',
                'action_url' => '/instances',
                'secondary_label' => 'Ver fila da IA',
                'secondary_url' => '/central-operacao?tab=ai_reprocess',
            ];
        }
        return $blocks;
    }

    private function applyExternalContextToServices(array &$services, array $blocks, array $ai): void
    {
        if ($blocks !== [] && isset($services['evolution'])) {
            $blockedMessages = (int) ($ai['pending_blocked_total'] ?? 0);
            $blockedCompanies = [];
            foreach ($blocks as $block) {
                $tenantId = (int) ($block['tenant_id'] ?? 0);
                if ($tenantId > 0) {
                    $blockedCompanies[$tenantId] = true;
                }
            }
            if (($services['evolution']['status'] ?? '') === 'operational') {
                $services['evolution']['status'] = 'attention';
            }
            $services['evolution']['evidence'] = count($blockedCompanies) . ' empresa(s) com conexão indisponível; ' . $blockedMessages . ' mensagem(ns) aguardam reconexão.';
            $services['evolution']['impact'] = 'Parte dos atendimentos está bloqueada por uma dependência externa, embora o RS Connect continue operacional.';
            $services['evolution']['recommended_action'] = 'Reconectar as instâncias indicadas em “Problemas ativos”.';
        }

        if (isset($services['ai_reprocess']) && (int) ($ai['pending_total'] ?? 0) > 0) {
            $pending = (int) ($ai['pending_total'] ?? 0);
            $blocked = (int) ($ai['pending_blocked_total'] ?? 0);
            if ($pending > 0 && $blocked >= $pending) {
                // A rotina não está quebrada: ela está corretamente preservando a fila.
                if (($services['ai_reprocess']['status'] ?? '') !== 'unknown') {
                    $services['ai_reprocess']['status'] = 'operational';
                }
                $services['ai_reprocess']['evidence'] = $blocked . ' mensagem(ns) preservadas aguardando dependência externa; nenhuma tentativa repetida será feita enquanto a conexão estiver indisponível.';
                $services['ai_reprocess']['impact'] = 'Sem falha interna confirmada na rotina; o processamento depende da reconexão das instâncias afetadas.';
                $services['ai_reprocess']['recommended_action'] = 'Acompanhar a reconexão do WhatsApp e reprocessar depois.';
            }
        }
    }

    private function activeIssues(array $services, array $externalBlocks, array $ai): array
    {
        $issues = $externalBlocks;

        foreach ($services as $key => $service) {
            $status = (string) ($service['status'] ?? 'unknown');
            if ($status === 'operational') {
                continue;
            }

            // Evita repetir o mesmo problema em dois formatos.
            if ($key === 'evolution' && $externalBlocks !== []) {
                continue;
            }
            if ($key === 'ai_reprocess' && (int) ($ai['pending_total'] ?? 0) > 0 && (int) ($ai['pending_blocked_total'] ?? 0) >= (int) ($ai['pending_total'] ?? 0)) {
                continue;
            }

            $issues[] = [
                'key' => 'service-' . $key,
                'status' => $status,
                'tenant_id' => 0,
                'tenant_name' => '',
                'title' => $status === 'unknown'
                    ? $service['label'] . ' está sem evidência recente'
                    : $service['label'] . ($status === 'critical' ? ' está indisponível' : ' requer atenção'),
                'summary' => (string) ($service['evidence'] ?? ''),
                'impact' => (string) ($service['impact'] ?? ''),
                'recommended_action' => (string) ($service['recommended_action'] ?? ''),
                'meta' => !empty($service['checked_at'])
                    ? 'Última evidência: ' . $service['checked_at'] . ' · ' . $service['age_label']
                    : 'Nenhuma evidência registrada.',
                'technical_details' => (string) ($service['technical_details'] ?? ''),
                'action_label' => 'Abrir ferramenta',
                'action_url' => (string) ($service['route'] ?? '/central-operacao'),
                'secondary_label' => 'Central técnica',
                'secondary_url' => '/central-operacao',
            ];
        }

        $weight = ['critical' => 0, 'blocked' => 1, 'attention' => 2, 'unknown' => 3];
        usort($issues, static function (array $a, array $b) use ($weight): int {
            $wa = $weight[(string) ($a['status'] ?? 'unknown')] ?? 3;
            $wb = $weight[(string) ($b['status'] ?? 'unknown')] ?? 3;
            if ($wa !== $wb) {
                return $wa <=> $wb;
            }
            return strcasecmp((string) ($a['title'] ?? ''), (string) ($b['title'] ?? ''));
        });

        return $issues;
    }

    private function summary(array $services, array $issues, array $companies, array $verification): array
    {
        $available = count(array_filter($services, static fn (array $service): bool => ($service['status'] ?? '') === 'operational'));
        $critical = count(array_filter($issues, static fn (array $issue): bool => ($issue['status'] ?? '') === 'critical'));
        $attention = count(array_filter($issues, static fn (array $issue): bool => ($issue['status'] ?? '') === 'attention'));
        $blocked = count(array_filter($issues, static fn (array $issue): bool => ($issue['status'] ?? '') === 'blocked'));
        $unknown = count(array_filter($services, static fn (array $service): bool => ($service['status'] ?? '') === 'unknown'));
        $affected = [];
        foreach ($issues as $issue) {
            $tenantId = (int) ($issue['tenant_id'] ?? 0);
            if ($tenantId > 0) {
                $affected[$tenantId] = true;
            }
        }
        foreach ($companies as $company) {
            if (in_array((string) ($company['status'] ?? ''), ['critical', 'attention', 'blocked'], true)) {
                $affected[(int) ($company['id'] ?? 0)] = true;
            }
        }
        unset($affected[0]);

        $state = 'operational';
        $label = 'RS Connect está operando normalmente';
        $message = 'Todos os serviços monitorados possuem evidência recente de funcionamento.';

        if (($verification['state'] ?? '') !== 'complete') {
            $state = 'unknown';
            $label = 'Verificação necessária';
            $message = 'Há evidências ausentes ou antigas. Atualize o painel antes de concluir que o sistema está saudável.';
        }
        if ($attention > 0) {
            $state = 'attention';
            $label = $attention === 1 ? 'RS Connect requer atenção' : 'RS Connect tem pontos para revisar';
            $message = $attention === 1 ? 'Existe 1 situação operacional que merece revisão.' : 'Existem ' . $attention . ' situações operacionais que merecem revisão.';
        }
        if ($blocked > 0) {
            $state = 'blocked';
            $label = $blocked === 1 ? '1 dependência externa está bloqueando atendimento' : $blocked . ' dependências externas estão bloqueando atendimentos';
            $message = 'O núcleo do RS Connect continua disponível, mas há empresas dependendo de reconexão ou serviço externo.';
        }
        if ($critical > 0) {
            $state = 'critical';
            $label = $critical === 1 ? '1 problema crítico exige ação agora' : $critical . ' problemas críticos exigem ação agora';
            $message = 'Priorize os itens críticos antes de qualquer outra revisão.';
        }

        return [
            'state' => $state,
            'label' => $label,
            'message' => $message,
            'available' => $available,
            'critical' => $critical,
            'attention' => $attention,
            'blocked' => $blocked,
            'unknown' => $unknown,
            'affected_companies' => count($affected),
            'services_total' => count($services),
        ];
    }

    private function routineOverview(array $services, array $ai, array $backup): array
    {
        $items = [];
        foreach (['billing_cron', 'ai_reprocess', 'backup', 'reporting'] as $key) {
            $service = $services[$key] ?? null;
            if (!$service) {
                continue;
            }
            $items[$key] = [
                'key' => $key,
                'label' => $service['label'],
                'status' => $service['status'],
                'last_execution' => $this->routineLastExecution($key, $ai, $backup, $service),
                'result' => $service['evidence'],
                'next_expected' => $this->routineNextExpected($key, $ai, $backup),
                'route' => $service['route'],
            ];
        }
        return array_values($items);
    }

    private function routineLastExecution(string $key, array $ai, array $backup, array $service): string
    {
        if ($key === 'ai_reprocess') {
            $raw = trim((string) ($ai['settings']['last_run_at'] ?? ''));
            return $raw !== '' ? $raw : 'Nenhuma execução registrada';
        }
        if ($key === 'backup') {
            $last = $backup['summary']['last_valid_backup'] ?? [];
            $raw = trim((string) ($last['finished_at'] ?? $last['created_at'] ?? ''));
            return $raw !== '' ? $raw : 'Nenhum backup válido';
        }
        if ($key === 'billing_cron') {
            $row = $this->fetchOne("SELECT checked_at FROM system_health_checks WHERE check_key = 'billing_cron_heartbeat' AND message LIKE '%Régua (cron)%' ORDER BY id DESC LIMIT 1");
            return !empty($row['checked_at']) ? (string) $row['checked_at'] : 'Nenhuma execução automática comprovada';
        }
        if ($key === 'reporting') {
            $row = $this->fetchOne('SELECT MAX(refreshed_at) AS refreshed_at FROM report_daily_metrics');
            return !empty($row['refreshed_at']) ? (string) $row['refreshed_at'] : 'Nenhuma agregação registrada';
        }
        return (string) ($service['checked_at'] ?? 'Sem evidência');
    }

    private function routineNextExpected(string $key, array $ai, array $backup): string
    {
        if ($key === 'ai_reprocess') {
            $runTime = substr((string) ($ai['settings']['run_time'] ?? '03:00'), 0, 5);
            $timezone = (string) ($ai['settings']['timezone'] ?? 'America/Sao_Paulo');
            return $this->nextDailyOccurrence($runTime, $timezone);
        }
        if ($key === 'backup') {
            return trim((string) ($backup['summary']['next_execution'] ?? '')) ?: 'Conforme rotina configurada';
        }
        if ($key === 'billing_cron') {
            return $this->nextDailyOccurrence('09:00', 'America/Sao_Paulo');
        }
        if ($key === 'reporting') {
            return 'Até 24h após a última agregação';
        }
        return '—';
    }

    private function nextDailyOccurrence(string $time, string $timezone): string
    {
        try {
            $tz = new DateTimeZone($timezone);
            $now = new DateTimeImmutable('now', $tz);
            if (!preg_match('/^(\d{2}):(\d{2})$/', $time, $matches)) {
                $matches = [0, '03', '00'];
            }
            $candidate = $now->setTime((int) $matches[1], (int) $matches[2], 0);
            if ($candidate <= $now) {
                $candidate = $candidate->modify('+1 day');
            }
            return $candidate->format('d/m/Y H:i');
        } catch (Throwable) {
            return 'Conforme configuração';
        }
    }

    private function companyOverview(array $ai): array
    {
        $tenants = $this->fetchAll("SELECT id, name, status FROM tenants WHERE status IN ('active','trial','suspended') ORDER BY name");
        if ($tenants === []) {
            return [];
        }

        $instances = $this->groupRows('evolution_instances',
            "SELECT tenant_id, COUNT(*) AS total,\n                    SUM(CASE WHEN COALESCE(NULLIF(connection_state,''), status) IN ('open','connected','active','online') THEN 1 ELSE 0 END) AS connected,\n                    MAX(last_status_check_at) AS last_checked_at\n             FROM evolution_instances GROUP BY tenant_id");
        $agents = $this->groupRows('ai_agents',
            "SELECT tenant_id, COUNT(*) AS total, SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active FROM ai_agents GROUP BY tenant_id");
        $credentials = $this->groupRows('ai_provider_credentials',
            "SELECT tenant_id, COUNT(*) AS total, SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active FROM ai_provider_credentials GROUP BY tenant_id");
        $aiSuccess = $this->groupRows('ai_automation_logs',
            "SELECT tenant_id, MAX(created_at) AS last_success_at FROM ai_automation_logs WHERE event = 'ai.replied' AND status = 'success' GROUP BY tenant_id");
        $calendarSettings = $this->groupRows('tenant_calendar_availability_settings',
            "SELECT tenant_id, COUNT(*) AS total, SUM(CASE WHEN enabled = 1 THEN 1 ELSE 0 END) AS active FROM tenant_calendar_availability_settings GROUP BY tenant_id");
        $calendarSync = $this->latestCalendarSyncByTenant();
        $invoices = $this->groupRows('tenant_invoices',
            "SELECT tenant_id, COUNT(*) AS total, SUM(CASE WHEN status = 'overdue' THEN 1 ELSE 0 END) AS overdue, SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) AS open_count FROM tenant_invoices GROUP BY tenant_id");

        $pending = [];
        $blocked = [];
        foreach (($ai['pending_instances'] ?? []) as $item) {
            $tenantId = (int) ($item['tenant_id'] ?? 0);
            if ($tenantId <= 0) {
                continue;
            }
            $count = (int) ($item['pending_count'] ?? 0);
            $pending[$tenantId] = ($pending[$tenantId] ?? 0) + $count;
            $state = strtolower(trim((string) (($item['connection_state'] ?? '') ?: ($item['instance_status'] ?? ''))));
            if (!in_array($state, ['open', 'connected', 'active', 'online'], true)) {
                $blocked[$tenantId] = ($blocked[$tenantId] ?? 0) + $count;
            }
        }

        $globalAiKey = trim((string) Env::get('OPENAI_API_KEY', '')) !== '';
        $result = [];
        foreach ($tenants as $tenant) {
            $id = (int) $tenant['id'];
            $instance = $instances[$id] ?? ['total' => 0, 'connected' => 0, 'last_checked_at' => null];
            $agent = $agents[$id] ?? ['total' => 0, 'active' => 0];
            $credential = $credentials[$id] ?? ['total' => 0, 'active' => 0];
            $success = $aiSuccess[$id] ?? ['last_success_at' => null];
            $calendar = $calendarSettings[$id] ?? ['total' => 0, 'active' => 0];
            $calendarLast = $calendarSync[$id] ?? null;
            $invoice = $invoices[$id] ?? ['total' => 0, 'overdue' => 0, 'open_count' => 0];

            $whatsapp = ['status' => 'neutral', 'label' => 'Não configurado', 'evidence' => ''];
            if ((int) $instance['total'] > 0) {
                $lastTs = strtotime((string) ($instance['last_checked_at'] ?? '')) ?: 0;
                $recent = $lastTs > 0 && $lastTs >= time() - 900;
                if (($blocked[$id] ?? 0) > 0) {
                    $whatsapp = ['status' => 'blocked', 'label' => 'Aguardando conexão', 'evidence' => ($blocked[$id] ?? 0) . ' conversa(s) aguardando'];
                } elseif (!$recent) {
                    $whatsapp = ['status' => 'unknown', 'label' => 'Sem evidência recente', 'evidence' => 'Estado da instância não confirmado nos últimos 15 min'];
                } elseif ((int) $instance['connected'] === (int) $instance['total']) {
                    $whatsapp = ['status' => 'operational', 'label' => (int) $instance['connected'] . '/' . (int) $instance['total'] . ' conectada(s)', 'evidence' => 'Verificado ' . $this->ageLabel(max(0, time() - $lastTs))];
                } else {
                    $whatsapp = ['status' => 'attention', 'label' => (int) $instance['connected'] . '/' . (int) $instance['total'] . ' conectada(s)', 'evidence' => 'Há instância sem conexão confirmada'];
                }
            }

            $ia = ['status' => 'neutral', 'label' => 'Não configurada', 'evidence' => ''];
            if ((int) $agent['active'] > 0) {
                $credentialReady = (int) $credential['active'] > 0 || $globalAiKey;
                if (!$credentialReady) {
                    $ia = ['status' => 'attention', 'label' => 'Revisar credencial', 'evidence' => 'Assistente ativo sem credencial disponível'];
                } elseif (($pending[$id] ?? 0) > 0 && ($blocked[$id] ?? 0) === 0) {
                    $ia = ['status' => 'attention', 'label' => 'Fila pendente', 'evidence' => ($pending[$id] ?? 0) . ' conversa(s) aguardando'];
                } else {
                    $successTs = strtotime((string) ($success['last_success_at'] ?? '')) ?: 0;
                    if ($successTs > 0 && $successTs >= time() - 86400) {
                        $ia = ['status' => 'operational', 'label' => 'Operando', 'evidence' => 'Última resposta ' . $this->ageLabel(max(0, time() - $successTs))];
                    } else {
                        $ia = ['status' => 'unknown', 'label' => 'Configurada, sem evidência', 'evidence' => 'Nenhuma resposta bem-sucedida nas últimas 24h'];
                    }
                }
            }

            $agenda = ['status' => 'neutral', 'label' => 'Não configurada', 'evidence' => ''];
            if ((int) $calendar['total'] > 0) {
                if ((int) $calendar['active'] < 1) {
                    $agenda = ['status' => 'neutral', 'label' => 'Desativada', 'evidence' => ''];
                } elseif (!$calendarLast) {
                    $agenda = ['status' => 'unknown', 'label' => 'Sem evidência', 'evidence' => 'Integração ativa sem sincronização registrada'];
                } else {
                    $syncStatus = strtolower((string) ($calendarLast['status'] ?? ''));
                    $syncTs = strtotime((string) ($calendarLast['created_at'] ?? '')) ?: 0;
                    if (in_array($syncStatus, ['error', 'failed'], true)) {
                        $agenda = ['status' => 'attention', 'label' => 'Última sincronização falhou', 'evidence' => (string) ($calendarLast['created_at'] ?? '')];
                    } elseif ($syncTs > 0 && $syncTs >= time() - 172800) {
                        $agenda = ['status' => 'operational', 'label' => 'Sincronizando', 'evidence' => 'Última operação ' . $this->ageLabel(max(0, time() - $syncTs))];
                    } else {
                        $agenda = ['status' => 'unknown', 'label' => 'Evidência antiga', 'evidence' => 'Última sincronização há mais de 48h'];
                    }
                }
            }

            $finance = ['status' => 'neutral', 'label' => 'Sem cobrança', 'evidence' => ''];
            if ((int) $invoice['total'] > 0) {
                if ((int) $invoice['overdue'] > 0) {
                    $finance = ['status' => 'attention', 'label' => (int) $invoice['overdue'] . ' vencida(s)', 'evidence' => 'Há cobrança financeira que requer acompanhamento'];
                } elseif ((int) $invoice['open_count'] > 0) {
                    $finance = ['status' => 'operational', 'label' => (int) $invoice['open_count'] . ' em aberto', 'evidence' => 'Sem atraso registrado'];
                } else {
                    $finance = ['status' => 'operational', 'label' => 'Sem atraso', 'evidence' => 'Nenhuma fatura vencida'];
                }
            }

            $status = $this->worstCompanyStatus([$whatsapp, $ia, $agenda, $finance]);
            $result[] = [
                'id' => $id,
                'name' => (string) $tenant['name'],
                'status' => $status,
                'whatsapp' => $whatsapp,
                'ia' => $ia,
                'agenda' => $agenda,
                'finance' => $finance,
                'pending' => (int) ($pending[$id] ?? 0),
            ];
        }

        $weight = ['critical' => 0, 'blocked' => 1, 'attention' => 2, 'unknown' => 3, 'neutral' => 4, 'operational' => 5];
        usort($result, static function (array $a, array $b) use ($weight): int {
            $wa = $weight[(string) ($a['status'] ?? 'neutral')] ?? 4;
            $wb = $weight[(string) ($b['status'] ?? 'neutral')] ?? 4;
            return $wa !== $wb ? $wa <=> $wb : strcasecmp((string) $a['name'], (string) $b['name']);
        });

        return $result;
    }

    private function worstCompanyStatus(array $cells): string
    {
        $weight = ['critical' => 0, 'blocked' => 1, 'attention' => 2, 'unknown' => 3, 'neutral' => 4, 'operational' => 5];
        $status = 'operational';
        $bestWeight = 5;
        foreach ($cells as $cell) {
            $candidate = (string) ($cell['status'] ?? 'neutral');
            $candidateWeight = $weight[$candidate] ?? 4;
            if ($candidateWeight < $bestWeight) {
                $bestWeight = $candidateWeight;
                $status = $candidate;
            }
        }
        return $status;
    }

    private function recentHealthHistory(): array
    {
        if (!$this->tableExists('system_health_checks')) {
            return ['events' => [], 'summary' => ['ok' => 0, 'warning' => 0, 'down' => 0]];
        }
        try {
            $statement = Database::connection()->query(
                "SELECT check_key, label, status, message, checked_at\n                 FROM system_health_checks\n                 WHERE checked_at >= (NOW() - INTERVAL 24 HOUR)\n                   AND check_key <> 'billing_cron_heartbeat'\n                 ORDER BY id DESC LIMIT 180"
            );
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $summary = ['ok' => 0, 'warning' => 0, 'down' => 0];
            foreach ($rows as $row) {
                $status = (string) ($row['status'] ?? 'warning');
                if (isset($summary[$status])) {
                    $summary[$status]++;
                }
            }
            return ['events' => array_slice($rows, 0, 12), 'summary' => $summary];
        } catch (Throwable) {
            return ['events' => [], 'summary' => ['ok' => 0, 'warning' => 0, 'down' => 0]];
        }
    }

    private function friendlyEvidence(string $key, string $message, string $status, bool $stale): string
    {
        if ($message === '') {
            return $stale ? 'Nenhuma evidência recente foi registrada.' : 'Verificação concluída sem detalhe adicional.';
        }

        if ($stale) {
            return 'A última evidência ficou antiga para este tipo de serviço. Execute uma nova verificação.';
        }

        // Evita despejar HTML ou páginas de erro inteiras na leitura operacional.
        if (preg_match('/HTTP\s*(\d{3})/i', $message, $match) === 1 && (str_contains($message, '<html') || mb_strlen($message) > 280)) {
            return 'Falha HTTP ' . $match[1] . ' registrada. Abra os detalhes técnicos para consultar o retorno completo.';
        }
        if (str_contains($message, '<html') || str_contains($message, '<style')) {
            return 'O serviço retornou uma página de erro em vez da resposta esperada. Abra os detalhes técnicos.';
        }

        $plain = trim(preg_replace('/\s+/', ' ', strip_tags($message)) ?? $message);
        if (mb_strlen($plain) > 220) {
            return rtrim(mb_substr($plain, 0, 217)) . '…';
        }
        return $plain;
    }

    private function ageLabel(int $seconds): string
    {
        if ($seconds < 60) {
            return 'há ' . max(1, $seconds) . ' s';
        }
        if ($seconds < 3600) {
            return 'há ' . (int) floor($seconds / 60) . ' min';
        }
        if ($seconds < 86400) {
            return 'há ' . (int) floor($seconds / 3600) . ' h';
        }
        return 'há ' . (int) floor($seconds / 86400) . ' dia(s)';
    }

    private function latestCalendarSyncByTenant(): array
    {
        if (!$this->tableExists('calendar_google_sync_logs')) {
            return [];
        }
        $rows = $this->fetchAll(
            "SELECT l.tenant_id, l.status, l.created_at\n             FROM calendar_google_sync_logs l\n             INNER JOIN (SELECT tenant_id, MAX(id) AS id FROM calendar_google_sync_logs GROUP BY tenant_id) x ON x.id = l.id"
        );
        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['tenant_id']] = $row;
        }
        return $result;
    }

    private function groupRows(string $table, string $sql): array
    {
        if (!$this->tableExists($table)) {
            return [];
        }
        $result = [];
        foreach ($this->fetchAll($sql) as $row) {
            $tenantId = (int) ($row['tenant_id'] ?? 0);
            if ($tenantId > 0) {
                $result[$tenantId] = $row;
            }
        }
        return $result;
    }

    private function fetchAll(string $sql): array
    {
        try {
            return Database::connection()->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return [];
        }
    }

    private function fetchOne(string $sql): ?array
    {
        try {
            $row = Database::connection()->query($sql)->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable) {
            return null;
        }
    }

    private function tableExists(string $table): bool
    {
        try {
            $statement = Database::connection()->prepare(
                'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table'
            );
            $statement->execute(['table' => $table]);
            return (int) $statement->fetchColumn() > 0;
        } catch (Throwable) {
            return false;
        }
    }
}
