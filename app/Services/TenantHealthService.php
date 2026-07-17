<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Crypto;
use App\Core\Database;
use App\Core\Env;
use PDO;
use Throwable;

final class TenantHealthService
{
    public const VERSION = '34.4-tenant-health';

    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::connection();
    }

    /** @return array<string,mixed> */
    public function dashboard(int $tenantId): array
    {
        $tenant = $this->row('SELECT * FROM tenants WHERE id = :id LIMIT 1', ['id' => $tenantId]);
        if (!$tenant) {
            return ['tenant' => null, 'snapshot' => null, 'checks' => [], 'groups' => [], 'incidents' => [], 'events' => []];
        }

        $snapshot = $this->row(
            'SELECT hs.*, u.name AS checked_by_name
             FROM tenant_health_snapshots hs
             LEFT JOIN users u ON u.id = hs.checked_by
             WHERE hs.tenant_id = :tenant_id
             ORDER BY hs.id DESC LIMIT 1',
            ['tenant_id' => $tenantId]
        );

        $checks = [];
        if ($snapshot) {
            $checks = $this->all(
                'SELECT * FROM tenant_health_checks WHERE snapshot_id = :snapshot_id ORDER BY sort_order, id',
                ['snapshot_id' => (int) $snapshot['id']]
            );
            foreach ($checks as &$check) {
                $details = json_decode((string) ($check['details_json'] ?? ''), true);
                $check['details'] = is_array($details) ? $details : [];
            }
            unset($check);
        }

        $groups = [];
        foreach ($checks as $check) {
            $groups[(string) $check['category']][] = $check;
        }

        $incidents = $this->all(
            'SELECT i.*, u.name AS assigned_user_name
             FROM tenant_health_incidents i
             LEFT JOIN users u ON u.id = i.assigned_user_id
             WHERE i.tenant_id = :tenant_id
             ORDER BY FIELD(i.status,"open","acknowledged","monitoring","resolved"),
                      FIELD(i.severity,"critical","warning"), i.last_seen_at DESC
             LIMIT 100',
            ['tenant_id' => $tenantId]
        );

        $events = $this->all(
            'SELECT e.*, i.title, u.name AS user_name
             FROM tenant_health_incident_events e
             INNER JOIN tenant_health_incidents i ON i.id = e.incident_id
             LEFT JOIN users u ON u.id = e.user_id
             WHERE e.tenant_id = :tenant_id
             ORDER BY e.id DESC LIMIT 60',
            ['tenant_id' => $tenantId]
        );

        $openIncidents = array_values(array_filter($incidents, static fn (array $i): bool => ($i['status'] ?? '') !== 'resolved'));

        return [
            'tenant' => $tenant,
            'snapshot' => $snapshot,
            'checks' => $checks,
            'groups' => $groups,
            'incidents' => $incidents,
            'open_incidents' => $openIncidents,
            'events' => $events,
            'summary' => [
                'open' => count($openIncidents),
                'critical' => count(array_filter($openIncidents, static fn (array $i): bool => ($i['severity'] ?? '') === 'critical')),
                'monitoring' => count(array_filter($openIncidents, static fn (array $i): bool => ($i['status'] ?? '') === 'monitoring')),
            ],
            'version' => self::VERSION,
        ];
    }

    /** @return array<string,mixed> */
    public function runForTenant(int $tenantId, ?int $userId = null, string $source = 'manual'): array
    {
        $tenant = $this->row('SELECT * FROM tenants WHERE id = :id LIMIT 1', ['id' => $tenantId]);
        if (!$tenant) {
            throw new \RuntimeException('Empresa não encontrada.');
        }

        $checks = array_merge(
            $this->accessChecks($tenant),
            $this->whatsappChecks($tenantId),
            $this->aiChecks($tenantId),
            $this->automationChecks($tenantId),
            $this->calendarChecks($tenantId),
            $this->securityChecks($tenantId)
        );

        $counts = ['ok' => 0, 'info' => 0, 'warning' => 0, 'critical' => 0];
        foreach ($checks as $check) {
            $status = (string) ($check['status'] ?? 'info');
            $counts[$status] = ($counts[$status] ?? 0) + 1;
        }

        $access = (new AccessControlService())->statusForTenant($tenantId);
        $overall = !$access['allowed'] ? 'blocked'
            : ($counts['critical'] > 0 ? 'critical'
                : ($counts['warning'] > 0 ? 'attention'
                    : ($this->isIdle($tenantId) ? 'idle' : 'healthy')));
        $score = max(0, min(100, 100 - ($counts['critical'] * 25) - ($counts['warning'] * 8)));
        $checkedAt = date('Y-m-d H:i:s');

        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare(
                'INSERT INTO tenant_health_snapshots
                    (tenant_id, overall_status, score, ok_count, info_count, warning_count, critical_count, summary_json, source, checked_by, checked_at)
                 VALUES
                    (:tenant_id, :overall_status, :score, :ok_count, :info_count, :warning_count, :critical_count, :summary_json, :source, :checked_by, :checked_at)'
            );
            $statement->execute([
                'tenant_id' => $tenantId,
                'overall_status' => $overall,
                'score' => $score,
                'ok_count' => $counts['ok'],
                'info_count' => $counts['info'],
                'warning_count' => $counts['warning'],
                'critical_count' => $counts['critical'],
                'summary_json' => json_encode(['access' => $access['code'] ?? 'allowed'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'source' => in_array($source, ['manual', 'cron', 'automatic'], true) ? $source : 'manual',
                'checked_by' => $userId,
                'checked_at' => $checkedAt,
            ]);
            $snapshotId = (int) $this->pdo->lastInsertId();

            $insert = $this->pdo->prepare(
                'INSERT INTO tenant_health_checks
                    (snapshot_id, tenant_id, category, component_key, component_label, status, summary, details_json, action_url, sort_order, checked_at)
                 VALUES
                    (:snapshot_id, :tenant_id, :category, :component_key, :component_label, :status, :summary, :details_json, :action_url, :sort_order, :checked_at)'
            );
            foreach ($checks as $check) {
                $insert->execute([
                    'snapshot_id' => $snapshotId,
                    'tenant_id' => $tenantId,
                    'category' => $check['category'],
                    'component_key' => $check['key'],
                    'component_label' => $check['label'],
                    'status' => $check['status'],
                    'summary' => mb_substr((string) $check['summary'], 0, 500),
                    'details_json' => empty($check['details']) ? null : json_encode($check['details'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'action_url' => $check['action_url'] ?? null,
                    'sort_order' => (int) ($check['sort'] ?? 100),
                    'checked_at' => $checkedAt,
                ]);
            }

            $this->syncIncidents($tenantId, $snapshotId, $checks, $userId, $checkedAt);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return $this->dashboard($tenantId);
    }

    public function runAll(?int $userId = null, string $source = 'cron'): array
    {
        $tenants = $this->all('SELECT id FROM tenants WHERE status IN ("active","suspended") ORDER BY id');
        $result = ['total' => count($tenants), 'success' => 0, 'errors' => []];
        foreach ($tenants as $tenant) {
            try {
                $this->runForTenant((int) $tenant['id'], $userId, $source);
                $result['success']++;
            } catch (Throwable $e) {
                $result['errors'][] = ['tenant_id' => (int) $tenant['id'], 'message' => $e->getMessage()];
            }
        }
        return $result;
    }

    public function updateIncident(int $incidentId, int $tenantId, string $action, string $note, ?int $userId): void
    {
        $incident = $this->row(
            'SELECT * FROM tenant_health_incidents WHERE id = :id AND tenant_id = :tenant_id LIMIT 1',
            ['id' => $incidentId, 'tenant_id' => $tenantId]
        );
        if (!$incident) {
            throw new \RuntimeException('Incidente não encontrado.');
        }

        $status = match ($action) {
            'acknowledge' => 'acknowledged',
            'monitor' => 'monitoring',
            'resolve' => 'resolved',
            'reopen' => 'open',
            default => throw new \RuntimeException('Ação inválida.'),
        };
        $event = match ($status) {
            'acknowledged' => 'acknowledged',
            'monitoring' => 'monitoring',
            'resolved' => 'resolved',
            default => 'reopened',
        };
        $note = mb_substr(trim($note), 0, 1000);

        $statement = $this->pdo->prepare(
            'UPDATE tenant_health_incidents SET
                status = :status_set,
                acknowledged_at = CASE WHEN :status_ack IN ("acknowledged","monitoring","resolved") THEN COALESCE(acknowledged_at, NOW()) ELSE NULL END,
                resolved_at = CASE WHEN :status_resolved = "resolved" THEN NOW() ELSE NULL END,
                assigned_user_id = CASE WHEN :status_assignee IN ("acknowledged","monitoring") THEN :user_id ELSE assigned_user_id END,
                notes = CASE WHEN :note_check <> "" THEN :note_value ELSE notes END
             WHERE id = :id AND tenant_id = :tenant_id'
        );
        $statement->execute([
            'status_set' => $status,
            'status_ack' => $status,
            'status_resolved' => $status,
            'status_assignee' => $status,
            'user_id' => $userId,
            'note_check' => $note,
            'note_value' => $note,
            'id' => $incidentId,
            'tenant_id' => $tenantId,
        ]);
        $this->recordIncidentEvent($incidentId, $tenantId, $event, $note, $userId);
    }

    /** @return array<int,array<string,mixed>> */
    private function accessChecks(array $tenant): array
    {
        $tenantId = (int) $tenant['id'];
        $access = (new AccessControlService())->statusForTenant($tenantId);
        $status = !empty($access['allowed']) ? 'ok' : 'critical';
        return [[
            'category' => 'Acesso e assinatura',
            'key' => 'access',
            'label' => 'Acesso da empresa',
            'status' => $status,
            'summary' => (string) ($access['message'] ?? 'Validação concluída.'),
            'details' => [
                'Situação da empresa' => (string) ($tenant['status'] ?? ''),
                'Código da validação' => (string) ($access['code'] ?? 'allowed'),
                'Tolerância financeira' => (string) ($access['grace_days'] ?? 5) . ' dia(s)',
                'Fim da vigência' => (string) ($access['subscription']['current_period_ends_at'] ?? 'Não informado'),
            ],
            'action_url' => '/billing?tenant_id=' . $tenantId,
            'sort' => 10,
        ]];
    }

    /** @return array<int,array<string,mixed>> */
    private function whatsappChecks(int $tenantId): array
    {
        $instances = $this->all('SELECT * FROM evolution_instances WHERE tenant_id = :tenant_id ORDER BY is_default DESC, id', ['tenant_id' => $tenantId]);
        if (!$instances) {
            return [$this->check('WhatsApp', 'instances.none', 'Conexões WhatsApp', 'critical', 'Nenhuma conexão WhatsApp foi cadastrada.', [], '/instances', 20)];
        }

        $checks = [];
        foreach ($instances as $instance) {
            $id = (int) $instance['id'];
            $dbStatus = strtolower((string) ($instance['status'] ?? 'disconnected'));
            $status = in_array($dbStatus, ['connected', 'open', 'active', 'online'], true) ? 'ok' : 'critical';
            $details = ['Status salvo' => $dbStatus, 'Identificador Evolution' => (string) $instance['instance_name']];
            $summary = $status === 'ok' ? 'A conexão está marcada como operacional.' : 'A conexão está desconectada ou aguardando QR Code.';

            try {
                $live = $this->evolutionRequest($instance, '/instance/connectionState/' . rawurlencode((string) $instance['instance_name']));
                $state = strtolower((string) ($live['instance']['state'] ?? $live['state'] ?? ''));
                $details['Status consultado na Evolution'] = $state !== '' ? $state : 'Resposta sem estado';
                if (!in_array($state, ['open', 'connected', 'active', 'online'], true)) {
                    $status = 'critical';
                    $summary = 'A Evolution informou que a conexão não está operacional.';
                }
            } catch (Throwable $e) {
                if ($status === 'ok') {
                    $status = 'warning';
                }
                $details['Teste da Evolution'] = $e->getMessage();
                $summary = 'Não foi possível confirmar a conexão diretamente na Evolution.';
            }

            try {
                $webhook = $this->evolutionRequest($instance, '/webhook/find/' . rawurlencode((string) $instance['instance_name']));
                $events = is_array($webhook['events'] ?? null) ? $webhook['events'] : [];
                $enabled = filter_var($webhook['enabled'] ?? false, FILTER_VALIDATE_BOOL);
                $byEvents = filter_var($webhook['webhookByEvents'] ?? false, FILTER_VALIDATE_BOOL);
                $url = (string) ($webhook['url'] ?? '');
                $valid = $enabled && !$byEvents && in_array('MESSAGES_UPSERT', $events, true)
                    && str_contains($url, '/webhooks/evolution') && str_contains($url, 'instance_id=' . $id);
                $details['Webhook'] = $valid ? 'Configurado corretamente' : 'Revisar configuração';
                $details['MESSAGES_UPSERT'] = in_array('MESSAGES_UPSERT', $events, true) ? 'Ativo' : 'Ausente';
                if (!$valid) {
                    $status = 'critical';
                    $summary = 'O webhook da conexão precisa ser corrigido.';
                }
            } catch (Throwable $e) {
                if ($status === 'ok') {
                    $status = 'warning';
                }
                $details['Validação do webhook'] = $e->getMessage();
            }

            $last = $this->row(
                'SELECT
                    MAX(CASE WHEN cm.direction = "incoming" THEN cm.created_at END) AS last_incoming,
                    MAX(CASE WHEN cm.direction = "outgoing" THEN cm.created_at END) AS last_outgoing
                 FROM conversation_messages cm
                 INNER JOIN conversations c ON c.id = cm.conversation_id
                 WHERE cm.tenant_id = :tenant_id AND c.evolution_instance_id = :instance_id',
                ['tenant_id' => $tenantId, 'instance_id' => $id]
            );
            $details['Última mensagem recebida'] = (string) ($last['last_incoming'] ?? 'Nenhuma registrada');
            $details['Última mensagem enviada'] = (string) ($last['last_outgoing'] ?? 'Nenhuma registrada');

            $checks[] = $this->check('WhatsApp', 'instance.' . $id, 'WhatsApp — ' . (string) $instance['name'], $status, $summary, $details, '/instances', 20 + $id);
        }
        return $checks;
    }

    /** @return array<int,array<string,mixed>> */
    private function aiChecks(int $tenantId): array
    {
        $agents = $this->all(
            'SELECT a.*, i.name AS instance_label, i.status AS instance_status,
                    (SELECT COUNT(*) FROM ai_provider_credentials c WHERE c.tenant_id = a.tenant_id AND c.status = "active" AND (c.agent_id = a.id OR c.agent_id IS NULL)) AS credential_count,
                    (SELECT MAX(created_at) FROM ai_automation_logs l WHERE l.agent_id = a.id AND l.status = "success") AS last_success,
                    (SELECT MAX(created_at) FROM ai_automation_logs l WHERE l.agent_id = a.id AND l.status = "error") AS last_error,
                    (SELECT COUNT(*) FROM ai_automation_logs l WHERE l.agent_id = a.id AND l.status = "error" AND l.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)) AS errors_24h
             FROM ai_agents a
             LEFT JOIN evolution_instances i ON i.id = a.instance_id
             WHERE a.tenant_id = :tenant_id
             ORDER BY a.is_default DESC, a.id',
            ['tenant_id' => $tenantId]
        );
        if (!$agents) {
            return [$this->check('Assistente de IA', 'agents.none', 'Assistentes virtuais', 'critical', 'Nenhum assistente virtual foi cadastrado.', [], '/agents', 40)];
        }

        $checks = [];
        foreach ($agents as $agent) {
            $id = (int) $agent['id'];
            $status = 'ok';
            $problems = [];
            if (($agent['status'] ?? '') !== 'active') {
                $status = 'warning';
                $problems[] = 'assistente inativo';
            }
            if ((int) ($agent['auto_reply_enabled'] ?? 0) !== 1) {
                $status = 'warning';
                $problems[] = 'respostas automáticas desligadas';
            }
            if (empty($agent['instance_id'])) {
                $status = 'critical';
                $problems[] = 'sem conexão WhatsApp vinculada';
            }
            if ((int) ($agent['credential_count'] ?? 0) < 1) {
                $status = 'critical';
                $problems[] = 'sem credencial de IA ativa';
            }
            $consecutive = $this->consecutiveAiErrors($id);
            if ($consecutive >= 3) {
                $status = 'critical';
                $problems[] = $consecutive . ' falhas consecutivas';
            } elseif ((int) ($agent['errors_24h'] ?? 0) > 0 && $status === 'ok') {
                $status = 'warning';
                $problems[] = (int) $agent['errors_24h'] . ' falha(s) nas últimas 24h';
            }

            $pendingCooldown = (int) $this->value(
                'SELECT COUNT(*) FROM ai_automation_logs l
                 WHERE l.agent_id = :agent_id AND l.event = "ai.cooldown" AND l.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                   AND NOT EXISTS (
                       SELECT 1 FROM ai_automation_logs ok
                       WHERE ok.conversation_id = l.conversation_id AND ok.event = "ai.replied" AND ok.status = "success" AND ok.created_at > l.created_at
                   )',
                ['agent_id' => $id],
                0
            );

            $summary = $problems ? 'Revisar: ' . implode('; ', $problems) . '.' : 'Assistente configurado e sem falhas recentes.';
            $details = [
                'Status' => (string) ($agent['status'] ?? ''),
                'Respostas automáticas' => (int) ($agent['auto_reply_enabled'] ?? 0) === 1 ? 'Ativas' : 'Desligadas',
                'Conexão vinculada' => (string) ($agent['instance_label'] ?? 'Não vinculada'),
                'Credencial ativa encontrada' => (int) ($agent['credential_count'] ?? 0) > 0 ? 'Sim' : 'Não',
                'Modelo' => (string) ($agent['model_name'] ?? ''),
                'Última resposta bem-sucedida' => (string) ($agent['last_success'] ?? 'Nenhuma'),
                'Última falha' => (string) ($agent['last_error'] ?? 'Nenhuma'),
                'Falhas consecutivas' => (string) $consecutive,
                'Mensagens aguardando reprocessamento' => (string) $pendingCooldown,
                'Intervalo configurado' => (string) ($agent['cooldown_seconds'] ?? 0) . ' segundo(s)',
            ];
            $checks[] = $this->check('Assistente de IA', 'agent.' . $id, 'Assistente — ' . (string) $agent['name'], $status, $summary, $details, '/agents', 40 + $id);
        }
        return $checks;
    }

    /** @return array<int,array<string,mixed>> */
    private function automationChecks(int $tenantId): array
    {
        $flows = $this->all('SELECT * FROM n8n_tenant_flows WHERE tenant_id = :tenant_id ORDER BY id', ['tenant_id' => $tenantId]);
        if (!$flows) {
            return [$this->check('Integrações n8n', 'n8n.none', 'Fluxos n8n', 'info', 'Nenhum fluxo n8n foi configurado para esta empresa.', [], '/n8n-flows', 60)];
        }
        $checks = [];
        foreach ($flows as $flow) {
            $id = (int) $flow['id'];
            $errors = (int) $this->value('SELECT COUNT(*) FROM n8n_flow_logs WHERE flow_id = :id AND status = "error" AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)', ['id' => $id], 0);
            $status = ($flow['status'] ?? '') === 'active' ? 'ok' : 'info';
            $summary = $status === 'ok' ? 'Fluxo ativo e sem falhas recentes.' : 'Fluxo inativo.';
            if ($errors >= 3) {
                $status = 'critical';
                $summary = $errors . ' falhas foram registradas nas últimas 24 horas.';
            } elseif ($errors > 0) {
                $status = 'warning';
                $summary = $errors . ' falha(s) registrada(s) nas últimas 24 horas.';
            } elseif (!empty($flow['last_error_at']) && (empty($flow['last_success_at']) || $flow['last_error_at'] > $flow['last_success_at'])) {
                $status = 'warning';
                $summary = 'A última execução registrada terminou com erro.';
            }
            $details = [
                'Situação' => (string) ($flow['status'] ?? ''),
                'Último sucesso' => (string) ($flow['last_success_at'] ?? 'Nenhum'),
                'Último erro' => (string) ($flow['last_error_at'] ?? 'Nenhum'),
                'Falhas em 24h' => (string) $errors,
            ];
            $checks[] = $this->check('Integrações n8n', 'flow.' . $id, 'Fluxo — ' . (string) $flow['name'], $status, $summary, $details, '/n8n-flows', 60 + $id);
        }
        return $checks;
    }

    /** @return array<int,array<string,mixed>> */
    private function calendarChecks(int $tenantId): array
    {
        $settings = $this->row('SELECT * FROM tenant_calendar_availability_settings WHERE tenant_id = :tenant_id LIMIT 1', ['tenant_id' => $tenantId]);
        if (!$settings || (int) ($settings['enabled'] ?? 0) !== 1) {
            return [$this->check('Agenda', 'calendar.disabled', 'Agenda inteligente', 'info', 'A Agenda Inteligente não está ativada para esta empresa.', [], '/calendar?tab=availability', 80)];
        }

        $status = 'ok';
        $problems = [];
        $mode = (string) ($settings['availability_mode'] ?? 'free_slots');
        $urlField = $mode === 'marked_events' ? 'marked_events_webhook_url_encrypted' : 'free_slots_webhook_url_encrypted';
        if (empty($settings[$urlField]) && empty($settings['n8n_webhook_url_encrypted'])) {
            $status = 'critical';
            $problems[] = 'webhook n8n não configurado';
        }
        $lastRequest = $this->row('SELECT * FROM calendar_availability_requests WHERE tenant_id = :tenant_id ORDER BY id DESC LIMIT 1', ['tenant_id' => $tenantId]);
        $lastSync = $this->row('SELECT * FROM calendar_google_sync_logs WHERE tenant_id = :tenant_id ORDER BY id DESC LIMIT 1', ['tenant_id' => $tenantId]);
        $expiredHolds = (int) $this->value(
            'SELECT COUNT(*) FROM calendar_availability_slots WHERE tenant_id = :tenant_id AND event_state = "held" AND hold_expires_at IS NOT NULL AND hold_expires_at < NOW()',
            ['tenant_id' => $tenantId],
            0
        );
        if (($lastRequest['status'] ?? '') === 'error' || ($lastSync['status'] ?? '') === 'error') {
            $status = 'warning';
            $problems[] = 'última consulta ou sincronização terminou com erro';
        }
        if ($expiredHolds > 0) {
            $status = $status === 'critical' ? 'critical' : 'warning';
            $problems[] = $expiredHolds . ' pré-reserva(s) vencida(s)';
        }
        $summary = $problems ? 'Revisar: ' . implode('; ', $problems) . '.' : 'Agenda configurada e sem falhas recentes.';
        return [$this->check('Agenda', 'calendar.integration', 'Agenda e Google Calendar', $status, $summary, [
            'Modo' => $mode === 'marked_events' ? 'Eventos VAGO' : 'Espaços livres',
            'Calendário' => (string) ($settings['google_calendar_id'] ?? 'primary'),
            'Última busca' => (string) ($lastRequest['created_at'] ?? 'Nenhuma'),
            'Situação da última busca' => (string) ($lastRequest['status'] ?? 'Não disponível'),
            'Última sincronização Google' => (string) ($lastSync['created_at'] ?? 'Nenhuma'),
            'Pré-reservas vencidas' => (string) $expiredHolds,
        ], '/calendar?tab=availability', 80)];
    }

    /** @return array<int,array<string,mixed>> */
    private function securityChecks(int $tenantId): array
    {
        $summary = $this->row(
            'SELECT COUNT(*) AS total,
                    COALESCE(SUM(status = "active"),0) AS active_count,
                    COALESCE(SUM(role = "client_admin" AND status = "active"),0) AS admin_count,
                    COALESCE(SUM(locked_until IS NOT NULL AND locked_until > NOW()),0) AS locked_count,
                    MAX(last_login_at) AS last_login_at
             FROM users WHERE tenant_id = :tenant_id',
            ['tenant_id' => $tenantId]
        );
        $status = 'ok';
        $problems = [];
        if ((int) ($summary['admin_count'] ?? 0) < 1) {
            $status = 'critical';
            $problems[] = 'nenhum administrador ativo';
        }
        if ((int) ($summary['locked_count'] ?? 0) > 0) {
            $status = $status === 'critical' ? 'critical' : 'warning';
            $problems[] = (int) $summary['locked_count'] . ' usuário(s) bloqueado(s)';
        }
        $message = $problems ? 'Revisar: ' . implode('; ', $problems) . '.' : 'Usuários e acessos sem bloqueios ativos.';
        return [$this->check('Segurança e usuários', 'security.users', 'Usuários e acessos', $status, $message, [
            'Usuários cadastrados' => (string) ($summary['total'] ?? 0),
            'Usuários ativos' => (string) ($summary['active_count'] ?? 0),
            'Administradores ativos' => (string) ($summary['admin_count'] ?? 0),
            'Usuários bloqueados' => (string) ($summary['locked_count'] ?? 0),
            'Último login' => (string) ($summary['last_login_at'] ?? 'Nenhum'),
        ], '/users', 100)];
    }

    private function syncIncidents(int $tenantId, int $snapshotId, array $checks, ?int $userId, string $checkedAt): void
    {
        $problemFingerprints = [];
        foreach ($checks as $check) {
            if (!in_array($check['status'], ['warning', 'critical'], true)) {
                continue;
            }
            $fingerprint = substr(hash('sha256', $check['category'] . '|' . $check['key']), 0, 48);
            $problemFingerprints[] = $fingerprint;
            $existing = $this->row(
                'SELECT * FROM tenant_health_incidents WHERE tenant_id = :tenant_id AND fingerprint = :fingerprint LIMIT 1',
                ['tenant_id' => $tenantId, 'fingerprint' => $fingerprint]
            );
            $details = empty($check['details']) ? null : json_encode($check['details'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!$existing) {
                $statement = $this->pdo->prepare(
                    'INSERT INTO tenant_health_incidents
                        (tenant_id, fingerprint, category, component_key, severity, status, title, summary, technical_details_json, related_url, first_seen_at, last_seen_at, last_snapshot_id)
                     VALUES
                        (:tenant_id, :fingerprint, :category, :component_key, :severity, "open", :title, :summary, :details, :related_url, :first_seen_at, :last_seen_at, :snapshot_id)'
                );
                $statement->execute([
                    'tenant_id' => $tenantId,
                    'fingerprint' => $fingerprint,
                    'category' => $check['category'],
                    'component_key' => $check['key'],
                    'severity' => $check['status'],
                    'title' => $check['label'],
                    'summary' => $check['summary'],
                    'details' => $details,
                    'related_url' => $check['action_url'] ?? null,
                    'first_seen_at' => $checkedAt,
                    'last_seen_at' => $checkedAt,
                    'snapshot_id' => $snapshotId,
                ]);
                $this->recordIncidentEvent((int) $this->pdo->lastInsertId(), $tenantId, 'opened', 'Problema identificado automaticamente.', $userId);
            } else {
                $wasResolved = ($existing['status'] ?? '') === 'resolved';
                $newStatus = $wasResolved ? 'open' : (string) $existing['status'];
                $statement = $this->pdo->prepare(
                    'UPDATE tenant_health_incidents SET
                        severity = :severity, status = :status_set, title = :title, summary = :summary,
                        technical_details_json = :details, related_url = :related_url,
                        occurrence_count = occurrence_count + 1, last_seen_at = :seen_at,
                        resolved_at = CASE WHEN :status_resolved = "resolved" THEN resolved_at ELSE NULL END,
                        last_snapshot_id = :snapshot_id
                     WHERE id = :id'
                );
                $statement->execute([
                    'severity' => $check['status'],
                    'status_set' => $newStatus,
                    'status_resolved' => $newStatus,
                    'title' => $check['label'],
                    'summary' => $check['summary'],
                    'details' => $details,
                    'related_url' => $check['action_url'] ?? null,
                    'first_seen_at' => $checkedAt,
                    'last_seen_at' => $checkedAt,
                    'snapshot_id' => $snapshotId,
                    'id' => (int) $existing['id'],
                ]);
                if ($wasResolved) {
                    $this->recordIncidentEvent((int) $existing['id'], $tenantId, 'reopened', 'O problema voltou a ser identificado.', $userId);
                }
            }
        }

        $open = $this->all(
            'SELECT id, fingerprint FROM tenant_health_incidents WHERE tenant_id = :tenant_id AND status <> "resolved"',
            ['tenant_id' => $tenantId]
        );
        foreach ($open as $incident) {
            if (in_array((string) $incident['fingerprint'], $problemFingerprints, true)) {
                continue;
            }
            $this->pdo->prepare('UPDATE tenant_health_incidents SET status = "resolved", resolved_at = :resolved_at WHERE id = :id')
                ->execute(['resolved_at' => $checkedAt, 'id' => (int) $incident['id']]);
            $this->recordIncidentEvent((int) $incident['id'], $tenantId, 'auto_resolved', 'A verificação seguinte confirmou a normalização.', $userId);
        }
    }

    private function recordIncidentEvent(int $incidentId, int $tenantId, string $eventType, string $note, ?int $userId): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO tenant_health_incident_events (incident_id, tenant_id, event_type, note, user_id)
             VALUES (:incident_id, :tenant_id, :event_type, :note, :user_id)'
        );
        $statement->execute([
            'incident_id' => $incidentId,
            'tenant_id' => $tenantId,
            'event_type' => $eventType,
            'note' => $note !== '' ? mb_substr($note, 0, 1000) : null,
            'user_id' => $userId,
        ]);
    }

    private function consecutiveAiErrors(int $agentId): int
    {
        $rows = $this->all('SELECT status FROM ai_automation_logs WHERE agent_id = :id ORDER BY id DESC LIMIT 10', ['id' => $agentId]);
        $count = 0;
        foreach ($rows as $row) {
            if (($row['status'] ?? '') !== 'error') {
                break;
            }
            $count++;
        }
        return $count;
    }

    private function isIdle(int $tenantId): bool
    {
        $last = (string) $this->value('SELECT MAX(created_at) FROM conversation_messages WHERE tenant_id = :tenant_id AND direction = "incoming"', ['tenant_id' => $tenantId], '');
        return $last === '' || strtotime($last) < strtotime('-7 days');
    }

    private function evolutionRequest(array $instance, string $path): array
    {
        $url = rtrim((string) $instance['base_url'], '/') . $path;
        $apiKey = Crypto::decrypt((string) $instance['api_key_encrypted']);
        $curl = curl_init($url);
        if ($curl === false) {
            throw new \RuntimeException('Não foi possível iniciar a consulta.');
        }
        $verify = filter_var(Env::get('EVOLUTION_SSL_VERIFY', true), FILTER_VALIDATE_BOOL);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 7,
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'apikey: ' . $apiKey],
            CURLOPT_SSL_VERIFYPEER => $verify,
            CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
        ]);
        $response = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        if ($response === false) {
            throw new \RuntimeException('Falha de conexão: ' . $error);
        }
        $decoded = json_decode((string) $response, true);
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException('HTTP ' . $status . ' ao consultar a Evolution.');
        }
        return is_array($decoded) ? $decoded : [];
    }

    private function check(string $category, string $key, string $label, string $status, string $summary, array $details, ?string $actionUrl, int $sort): array
    {
        return compact('category', 'key', 'label', 'status', 'summary', 'details') + ['action_url' => $actionUrl, 'sort' => $sort];
    }

    private function row(string $sql, array $params = []): ?array
    {
        try {
            $statement = $this->pdo->prepare($sql);
            $statement->execute($params);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable) {
            return null;
        }
    }

    private function all(string $sql, array $params = []): array
    {
        try {
            $statement = $this->pdo->prepare($sql);
            $statement->execute($params);
            return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return [];
        }
    }

    private function value(string $sql, array $params = [], mixed $default = null): mixed
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
