<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Env;
use PDO;
use Throwable;

final class OperationalOverviewService
{
    public function dashboard(): array
    {
        $operations = (new OperationsService())->dashboard();
        $ai = (new AiReprocessService())->dashboard();
        $backup = (new BackupAutomationService())->dashboard();

        $attention = $this->attentionItems($operations, $ai);
        $healthy = $this->healthyItems($operations, $ai);
        $routines = $this->routineItems($operations, $ai, $backup);
        $companies = $this->companyOverview($ai);

        $critical = count(array_filter($attention, static fn (array $item): bool => ($item['level'] ?? '') === 'critical'));
        $warning = count(array_filter($attention, static fn (array $item): bool => ($item['level'] ?? '') === 'warning'));
        $blocked = count(array_filter($attention, static fn (array $item): bool => ($item['level'] ?? '') === 'blocked'));
        $unknown = count(array_filter($attention, static fn (array $item): bool => ($item['level'] ?? '') === 'unknown'));
        $affectedTenants = [];
        foreach ($attention as $item) {
            $tenantId = (int) ($item['tenant_id'] ?? 0);
            if ($tenantId > 0) {
                $affectedTenants[$tenantId] = true;
            }
        }

        $status = 'operational';
        $label = 'RS Connect operando normalmente';
        $message = 'Nenhum problema operacional exige ação imediata.';
        if ($critical > 0) {
            $status = 'critical';
            $label = $critical === 1 ? '1 problema crítico precisa de atenção' : $critical . ' problemas críticos precisam de atenção';
            $message = 'Priorize os itens em vermelho antes das demais revisões.';
        } elseif (($warning + $blocked + $unknown) > 0) {
            $status = 'attention';
            $total = $warning + $blocked + $unknown;
            $label = $total === 1 ? '1 ponto precisa de atenção' : $total . ' pontos precisam de atenção';
            $message = $blocked > 0
                ? 'Há dependências externas ou rotinas que precisam ser acompanhadas.'
                : 'O sistema está operando, mas há pontos que merecem revisão.';
        }

        return [
            'status' => [
                'key' => $status,
                'label' => $label,
                'message' => $message,
                'critical' => $critical,
                'warning' => $warning,
                'blocked' => $blocked,
                'unknown' => $unknown,
                'affected_companies' => count($affectedTenants),
                'last_checked_at' => (string) ($operations['overall']['last_checked_at'] ?? ''),
            ],
            'attention' => $attention,
            'healthy' => $healthy,
            'routines' => $routines,
            'companies' => $companies,
            'technical' => [
                'checks_total' => count($operations['checks'] ?? []),
                'healthy_total' => (int) ($operations['summary']['healthy'] ?? 0),
                'alerts_total' => (int) ($operations['summary']['alerts'] ?? 0),
                'ai_pending_total' => (int) ($ai['pending_total'] ?? 0),
                'ai_blocked_total' => (int) ($ai['pending_blocked_total'] ?? 0),
            ],
        ];
    }

    private function attentionItems(array $operations, array $ai): array
    {
        $items = [];
        $blockedTenants = [];
        $pendingInstances = $ai['pending_instances'] ?? [];
        foreach ($pendingInstances as $pending) {
            $pendingCount = (int) ($pending['pending_count'] ?? 0);
            if ($pendingCount < 1) {
                continue;
            }

            $state = strtolower(trim((string) (($pending['connection_state'] ?? '') ?: ($pending['instance_status'] ?? ''))));
            $connected = in_array($state, ['open', 'connected', 'active', 'online'], true);
            $tenantId = (int) ($pending['tenant_id'] ?? 0);
            $tenantName = trim((string) ($pending['tenant_name'] ?? 'Empresa')) ?: 'Empresa';
            $instance = trim((string) (($pending['instance_label'] ?? '') ?: ($pending['instance_name'] ?? '')));
            $agent = trim((string) ($pending['agent_name'] ?? ''));

            if (!$connected) {
                $blockedTenants[$tenantId] = true;
                $items[] = [
                    'key' => 'ai-blocked-' . $tenantId . '-' . (int) ($pending['instance_id'] ?? 0),
                    'level' => 'blocked',
                    'tenant_id' => $tenantId,
                    'title' => 'WhatsApp de ' . $tenantName . ' precisa ser reconectado',
                    'summary' => $pendingCount . ' conversa(s) aguardam resposta e estão preservadas na fila.',
                    'impact' => 'A IA não repetirá tentativas enquanto a Evolution informar esta conexão como indisponível.',
                    'meta' => trim('Instância: ' . ($instance !== '' ? $instance : 'não identificada') . ($agent !== '' ? ' · Assistente: ' . $agent : '')),
                    'action_label' => 'Abrir WhatsApp',
                    'action_url' => '/instances',
                    'secondary_label' => 'Ver fila da IA',
                    'secondary_url' => '/central-operacao?tab=ai_reprocess',
                ];
                continue;
            }

            $items[] = [
                'key' => 'ai-pending-' . $tenantId . '-' . (int) ($pending['instance_id'] ?? 0),
                'level' => 'warning',
                'tenant_id' => $tenantId,
                'title' => $tenantName . ' tem mensagens aguardando reprocessamento',
                'summary' => $pendingCount . ' conversa(s) estão pendentes mesmo com a conexão disponível.',
                'impact' => 'Revise a fila para identificar falha de IA, envio ou mensagem que precisa ser reavaliada.',
                'meta' => $instance !== '' ? 'Instância: ' . $instance : '',
                'action_label' => 'Abrir fila da IA',
                'action_url' => '/central-operacao?tab=ai_reprocess',
                'secondary_label' => 'Abrir WhatsApp',
                'secondary_url' => '/instances',
            ];
        }

        foreach (($operations['checks'] ?? []) as $check) {
            $status = (string) ($check['status'] ?? 'unknown');
            if (!in_array($status, ['down', 'warning', 'unknown'], true)) {
                continue;
            }

            $key = (string) ($check['check_key'] ?? '');
            if ($key === 'ai_reprocess' && (int) ($ai['pending_total'] ?? 0) > 0 && (int) ($ai['pending_blocked_total'] ?? 0) >= (int) ($ai['pending_total'] ?? 0)) {
                continue;
            }
            if ($key === 'evolution' && $blockedTenants !== []) {
                continue;
            }

            $friendly = $this->checkCopy($key, (string) ($check['label'] ?? $key), (string) ($check['message'] ?? ''));
            $items[] = [
                'key' => 'check-' . $key,
                'level' => $status === 'down' ? 'critical' : ($status === 'unknown' ? 'unknown' : 'warning'),
                'tenant_id' => 0,
                'title' => $friendly['title'],
                'summary' => $friendly['summary'],
                'impact' => $friendly['impact'],
                'meta' => !empty($check['checked_at']) ? 'Verificado em ' . (string) $check['checked_at'] : 'Ainda sem evidência registrada.',
                'action_label' => 'Abrir ferramenta',
                'action_url' => (string) ($check['route'] ?? '/central-operacao'),
                'secondary_label' => 'Central técnica',
                'secondary_url' => '/central-operacao',
            ];
        }

        $weight = ['critical' => 0, 'warning' => 1, 'blocked' => 2, 'unknown' => 3];
        usort($items, static function (array $a, array $b) use ($weight): int {
            $wa = $weight[(string) ($a['level'] ?? 'warning')] ?? 2;
            $wb = $weight[(string) ($b['level'] ?? 'warning')] ?? 2;
            if ($wa !== $wb) {
                return $wa <=> $wb;
            }
            return strcasecmp((string) ($a['title'] ?? ''), (string) ($b['title'] ?? ''));
        });

        return $items;
    }

    private function healthyItems(array $operations, array $ai): array
    {
        $items = [];
        foreach (($operations['checks'] ?? []) as $check) {
            if (($check['status'] ?? '') !== 'ok') {
                continue;
            }
            $key = (string) ($check['check_key'] ?? '');
            $message = trim((string) ($check['message'] ?? 'Operando normalmente.'));
            $items[] = [
                'key' => $key,
                'label' => (string) ($check['label'] ?? $key),
                'message' => $message,
                'route' => (string) ($check['route'] ?? '/central-operacao'),
            ];
        }

        if ((int) ($ai['pending_total'] ?? 0) === 0) {
            $items[] = [
                'key' => 'ai_queue_clear',
                'label' => 'Fila da IA',
                'message' => 'Nenhuma conversa aguardando reprocessamento.',
                'route' => '/central-operacao?tab=ai_reprocess',
            ];
        }
        return $items;
    }

    private function routineItems(array $operations, array $ai, array $backup): array
    {
        $checksByKey = [];
        foreach (($operations['checks'] ?? []) as $check) {
            $checksByKey[(string) ($check['check_key'] ?? '')] = $check;
        }

        $items = [];
        foreach ([
            'billing_cron' => ['label' => 'Cron de cobrança', 'route' => '/billing-reminders'],
            'ai_reprocess' => ['label' => 'Fila da IA', 'route' => '/central-operacao?tab=ai_reprocess'],
            'backup' => ['label' => 'Backup', 'route' => '/central-operacao?tab=backups'],
            'reporting' => ['label' => 'Relatórios', 'route' => '/reports'],
        ] as $key => $definition) {
            $check = $checksByKey[$key] ?? [];
            $status = (string) ($check['status'] ?? 'unknown');
            $message = trim((string) ($check['message'] ?? 'Sem evidência recente.'));

            if ($key === 'ai_reprocess' && (int) ($ai['pending_total'] ?? 0) > 0 && (int) ($ai['pending_blocked_total'] ?? 0) >= (int) ($ai['pending_total'] ?? 0)) {
                $status = 'blocked';
                $message = (int) ($ai['pending_blocked_total'] ?? 0) . ' mensagem(ns) aguardam reconexão do WhatsApp; a fila está preservada.';
            }
            if ($key === 'backup' && !empty($backup['summary']['last_valid_backup'])) {
                $lastValidBackup = $backup['summary']['last_valid_backup'];
                $message = 'Último backup válido em ' . (string) ($lastValidBackup['finished_at'] ?? $lastValidBackup['created_at'] ?? 'data não identificada') . '.';
            }

            $items[] = [
                'key' => $key,
                'label' => $definition['label'],
                'status' => $status,
                'message' => $message,
                'checked_at' => (string) ($check['checked_at'] ?? ''),
                'route' => $definition['route'],
            ];
        }
        return $items;
    }

    private function companyOverview(array $ai): array
    {
        $tenants = $this->fetchAll("SELECT id, name, status FROM tenants WHERE status IN ('active','trial','suspended') ORDER BY name");
        if ($tenants === []) {
            return [];
        }

        $instanceStats = $this->groupedStats(
            'evolution_instances',
            "SELECT tenant_id, COUNT(*) AS total, SUM(CASE WHEN COALESCE(NULLIF(connection_state,''), status) IN ('open','connected','active','online') THEN 1 ELSE 0 END) AS ok_count FROM evolution_instances GROUP BY tenant_id"
        );
        $agentStats = $this->groupedStats(
            'ai_agents',
            "SELECT tenant_id, COUNT(*) AS total, SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS ok_count FROM ai_agents GROUP BY tenant_id"
        );
        $credentialStats = $this->groupedStats(
            'ai_provider_credentials',
            "SELECT tenant_id, COUNT(*) AS total, SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS ok_count FROM ai_provider_credentials GROUP BY tenant_id"
        );
        $calendarStats = $this->groupedStats(
            'tenant_calendar_availability_settings',
            "SELECT tenant_id, 1 AS total, SUM(CASE WHEN enabled = 1 THEN 1 ELSE 0 END) AS ok_count FROM tenant_calendar_availability_settings GROUP BY tenant_id"
        );
        $invoiceStats = $this->groupedStats(
            'tenant_invoices',
            "SELECT tenant_id, COUNT(*) AS total, SUM(CASE WHEN status = 'overdue' THEN 1 ELSE 0 END) AS overdue_count, SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) AS open_count FROM tenant_invoices GROUP BY tenant_id"
        );

        $blockedByTenant = [];
        $pendingByTenant = [];
        foreach (($ai['pending_instances'] ?? []) as $item) {
            $tenantId = (int) ($item['tenant_id'] ?? 0);
            $pendingByTenant[$tenantId] = ($pendingByTenant[$tenantId] ?? 0) + (int) ($item['pending_count'] ?? 0);
            $state = strtolower(trim((string) (($item['connection_state'] ?? '') ?: ($item['instance_status'] ?? ''))));
            if (!in_array($state, ['open', 'connected', 'active', 'online'], true)) {
                $blockedByTenant[$tenantId] = ($blockedByTenant[$tenantId] ?? 0) + (int) ($item['pending_count'] ?? 0);
            }
        }

        $hasGlobalAiKey = trim((string) Env::get('OPENAI_API_KEY', '')) !== '';
        $result = [];
        foreach ($tenants as $tenant) {
            $id = (int) $tenant['id'];
            $instances = $instanceStats[$id] ?? ['total' => 0, 'ok_count' => 0];
            $agents = $agentStats[$id] ?? ['total' => 0, 'ok_count' => 0];
            $credentials = $credentialStats[$id] ?? ['total' => 0, 'ok_count' => 0];
            $calendar = $calendarStats[$id] ?? ['total' => 0, 'ok_count' => 0];
            $invoices = $invoiceStats[$id] ?? ['total' => 0, 'overdue_count' => 0, 'open_count' => 0];

            $whatsapp = ['status' => 'neutral', 'label' => 'Não configurado'];
            if ((int) $instances['total'] > 0) {
                $whatsapp = (int) $instances['ok_count'] > 0
                    ? ['status' => 'ok', 'label' => (int) $instances['ok_count'] . '/' . (int) $instances['total'] . ' conectada(s)']
                    : ['status' => 'warning', 'label' => 'Revisar conexão'];
            }
            if (($blockedByTenant[$id] ?? 0) > 0) {
                $whatsapp = ['status' => 'blocked', 'label' => 'Aguardando conexão'];
            }

            $ia = ['status' => 'neutral', 'label' => 'Não configurada'];
            if ((int) $agents['ok_count'] > 0) {
                $credentialReady = (int) $credentials['ok_count'] > 0 || $hasGlobalAiKey;
                $ia = $credentialReady ? ['status' => 'ok', 'label' => 'Operando'] : ['status' => 'warning', 'label' => 'Revisar credencial'];
            }
            if (($pendingByTenant[$id] ?? 0) > 0 && ($blockedByTenant[$id] ?? 0) === 0) {
                $ia = ['status' => 'warning', 'label' => 'Fila pendente'];
            }

            $agenda = (int) $calendar['total'] < 1
                ? ['status' => 'neutral', 'label' => 'Não configurada']
                : ((int) $calendar['ok_count'] > 0 ? ['status' => 'ok', 'label' => 'Ativa'] : ['status' => 'neutral', 'label' => 'Desativada']);

            $finance = ['status' => 'ok', 'label' => 'Sem atraso'];
            if ((int) ($invoices['overdue_count'] ?? 0) > 0) {
                $finance = ['status' => 'warning', 'label' => (int) $invoices['overdue_count'] . ' vencida(s)'];
            } elseif ((int) ($invoices['total'] ?? 0) < 1) {
                $finance = ['status' => 'neutral', 'label' => 'Sem cobrança'];
            } elseif ((int) ($invoices['open_count'] ?? 0) > 0) {
                $finance = ['status' => 'ok', 'label' => (int) $invoices['open_count'] . ' em aberto'];
            }

            $severity = 'ok';
            foreach ([$whatsapp, $ia, $agenda, $finance] as $cell) {
                if (($cell['status'] ?? '') === 'warning') {
                    $severity = 'warning';
                    break;
                }
                if (($cell['status'] ?? '') === 'blocked') {
                    $severity = 'blocked';
                }
            }
            if ($severity === 'ok' && ($whatsapp['status'] ?? '') === 'neutral' && ($ia['status'] ?? '') === 'neutral') {
                $severity = 'neutral';
            }

            $result[] = [
                'id' => $id,
                'name' => (string) $tenant['name'],
                'status' => $severity,
                'whatsapp' => $whatsapp,
                'ia' => $ia,
                'agenda' => $agenda,
                'finance' => $finance,
                'pending' => (int) ($pendingByTenant[$id] ?? 0),
            ];
        }

        $weight = ['warning' => 0, 'blocked' => 1, 'neutral' => 2, 'ok' => 3];
        usort($result, static function (array $a, array $b) use ($weight): int {
            $wa = $weight[(string) ($a['status'] ?? 'neutral')] ?? 2;
            $wb = $weight[(string) ($b['status'] ?? 'neutral')] ?? 2;
            if ($wa !== $wb) {
                return $wa <=> $wb;
            }
            return strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
        });

        return $result;
    }

    private function checkCopy(string $key, string $label, string $message): array
    {
        return match ($key) {
            'database' => ['title' => 'Banco de dados precisa ser revisado', 'summary' => $message, 'impact' => 'Falhas no banco podem impedir qualquer módulo do RS Connect de operar corretamente.'],
            'migrations' => ['title' => 'Estrutura do sistema não está totalmente validada', 'summary' => $message, 'impact' => 'Uma migration ausente pode deixar funcionalidades incompletas ou inconsistentes.'],
            'evolution' => ['title' => 'WhatsApp / Evolution requer revisão', 'summary' => $message, 'impact' => 'Mensagens podem deixar de entrar ou sair até a conexão ser normalizada.'],
            'openai' => ['title' => 'Serviço de IA requer revisão', 'summary' => $message, 'impact' => 'Assistentes podem não gerar respostas enquanto a integração estiver com falha.'],
            'n8n' => ['title' => 'Automações n8n requerem revisão', 'summary' => $message, 'impact' => 'Fluxos externos podem não ser executados ou receber callbacks.'],
            'webhooks' => ['title' => 'Webhooks e mensagens requerem revisão', 'summary' => $message, 'impact' => 'Eventos externos podem não chegar corretamente ao RS Connect.'],
            'calendar' => ['title' => 'Google Agenda requer revisão', 'summary' => $message, 'impact' => 'Disponibilidade, criação ou sincronização de eventos pode ficar indisponível.'],
            'payments' => ['title' => 'Pagamentos requerem revisão', 'summary' => $message, 'impact' => 'Confirmações e atualizações financeiras podem não ser processadas.'],
            'billing_cron' => ['title' => 'Cron de cobrança requer atenção', 'summary' => $message, 'impact' => 'A régua pode deixar de processar cobranças no horário esperado.'],
            'ai_reprocess' => ['title' => 'Rotina da fila da IA requer atenção', 'summary' => $message, 'impact' => 'Mensagens pendentes podem permanecer sem nova tentativa automática.'],
            'reporting' => ['title' => 'Atualização dos relatórios requer atenção', 'summary' => $message, 'impact' => 'Indicadores podem ficar defasados até a agregação voltar a operar.'],
            'backup' => ['title' => 'Backup precisa de atenção', 'summary' => $message, 'impact' => 'A recuperação do sistema fica mais arriscada quando o último backup válido está atrasado.'],
            default => ['title' => $label . ' requer atenção', 'summary' => $message, 'impact' => 'Abra os detalhes técnicos para confirmar a causa e a ação necessária.'],
        };
    }

    private function groupedStats(string $table, string $sql): array
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
