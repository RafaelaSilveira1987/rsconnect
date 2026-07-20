<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Crypto;
use App\Core\Database;
use App\Core\Env;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;

final class TenantHealthService
{
    public const VERSION = '34.5.3-health-stable';

    private PDO $pdo;
    private ?int $databaseOffsetMinutes = null;

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

        $tracking = $this->row(
            'SELECT * FROM tenant_admin_tracking WHERE tenant_id = :tenant_id LIMIT 1',
            ['tenant_id' => $tenantId]
        ) ?? [];
        $occurrences = $this->recentOperationalOccurrences(
            $tenantId,
            !empty($tracking['acknowledged_at']) ? (string) $tracking['acknowledged_at'] : null,
            100
        );
        $occurrenceSummary = [
            'total' => count($occurrences),
            'unreviewed' => count(array_filter($occurrences, static fn (array $item): bool => empty($item['reviewed']))),
            'reviewed' => count(array_filter($occurrences, static fn (array $item): bool => !empty($item['reviewed']))),
            'ai' => count(array_filter($occurrences, static fn (array $item): bool => ($item['source'] ?? '') === 'ai')),
            'integration' => count(array_filter($occurrences, static fn (array $item): bool => ($item['source'] ?? '') === 'integration')),
        ];

        $openIncidents = array_values(array_filter($incidents, static fn (array $i): bool => ($i['status'] ?? '') !== 'resolved'));

        $configuration = $this->configurationInventory($tenantId, $checks);

        return [
            'tenant' => $tenant,
            'snapshot' => $snapshot,
            'checks' => $checks,
            'groups' => $groups,
            'configuration' => $configuration,
            'tracking' => $tracking,
            'occurrences' => $occurrences,
            'occurrence_summary' => $occurrenceSummary,
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
            $details['Última mensagem recebida'] = $this->formatDatabaseDate($last['last_incoming'] ?? null, 'Nenhuma registrada');
            $details['Última mensagem enviada'] = $this->formatDatabaseDate($last['last_outgoing'] ?? null, 'Nenhuma registrada');

            $checks[] = $this->check('WhatsApp', 'instance.' . $id, 'WhatsApp — ' . (string) $instance['name'], $status, $summary, $details, '/instances', 20 + $id);
        }
        return $checks;
    }

    /** @return array<int,array<string,mixed>> */
    private function aiChecks(int $tenantId): array
    {
        $agents = $this->all(
            'SELECT a.*, i.name AS instance_label, i.status AS instance_status,
                    (SELECT COUNT(*) FROM evolution_instances ii WHERE ii.tenant_id = a.tenant_id) AS tenant_instance_count,
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
            return [$this->check('Assistente de IA', 'agents.none', 'Assistentes virtuais', 'critical', 'Nenhum assistente virtual foi cadastrado.', [], '/agents?tenant_id=' . $tenantId, 40)];
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
            if (empty($agent['instance_id'])
                && ((int) ($agent['is_default'] ?? 0) !== 1 || (int) ($agent['tenant_instance_count'] ?? 0) < 1)
            ) {
                $status = 'critical';
                $problems[] = 'sem conexão WhatsApp disponível';
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

            $pending = $this->pendingAiResponses(
                $tenantId,
                $id,
                (int) ($agent['reply_to_reactions'] ?? 0) === 1
            );
            $pendingConversations = (int) ($pending['conversation_count'] ?? 0);
            $pendingMessages = (int) ($pending['message_count'] ?? 0);

            if ($pendingConversations > 0) {
                if ($status === 'ok') {
                    $status = 'warning';
                }
                $problems[] = $pendingConversations . ' conversa(s) aguardando resposta da IA';
            }

            $summary = $problems ? 'Revisar: ' . implode('; ', $problems) . '.' : 'Assistente configurado e sem falhas recentes.';
            $details = [
                'Status' => (string) ($agent['status'] ?? ''),
                'Respostas automáticas' => (int) ($agent['auto_reply_enabled'] ?? 0) === 1 ? 'Ativas' : 'Desligadas',
                'Conexão vinculada' => (string) ($agent['instance_label'] ?? 'Não vinculada'),
                'Credencial ativa encontrada' => (int) ($agent['credential_count'] ?? 0) > 0 ? 'Sim' : 'Não',
                'Modelo' => (string) ($agent['model_name'] ?? ''),
                'Última resposta bem-sucedida' => $this->formatDatabaseDate($agent['last_success'] ?? null, 'Nenhuma'),
                'Última falha' => $this->formatDatabaseDate($agent['last_error'] ?? null, 'Nenhuma'),
                'Falhas consecutivas' => (string) $consecutive,
                'Conversas aguardando resposta' => (string) $pendingConversations,
                'Mensagens acumuladas nessas conversas' => (string) $pendingMessages,
                'Aguardando desde' => $this->formatDatabaseDate($pending['oldest_pending_at'] ?? null, 'Nenhuma'),
                'O que significa' => $pendingConversations > 0
                    ? 'A conversa recebeu mensagem durante o intervalo mínimo e ainda não possui resposta posterior. Use Reprocessar agora ou abra a conversa.'
                    : 'Não há conversa sem resposta causada pelo intervalo mínimo.',
                'Intervalo configurado' => (string) ($agent['cooldown_seconds'] ?? 0) . ' segundo(s)',
            ];
            $checks[] = $this->check('Assistente de IA', 'agent.' . $id, 'Assistente — ' . (string) $agent['name'], $status, $summary, $details, '/agents?tenant_id=' . $tenantId, 40 + $id);
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
                'Último sucesso' => $this->formatDatabaseDate($flow['last_success_at'] ?? null, 'Nenhum'),
                'Último erro' => $this->formatDatabaseDate($flow['last_error_at'] ?? null, 'Nenhum'),
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
            return [$this->check('Agenda', 'calendar.disabled', 'Agenda inteligente', 'info', 'A Agenda Inteligente não está ativada para esta empresa.', [], '/calendar?section=availability', 80)];
        }

        $status = 'ok';
        $problems = [];
        $mode = (string) ($settings['availability_mode'] ?? 'free_slots');
        $urlField = $mode === 'marked_events' ? 'marked_events_webhook_url_encrypted' : 'free_slots_webhook_url_encrypted';
        if (empty($settings[$urlField]) && empty($settings['n8n_webhook_url_encrypted'])) {
            $status = 'critical';
            $problems[] = 'webhook n8n não configurado';
        }
        if ($mode === 'free_slots'
            && !empty($settings['create_google_event_on_confirm'])
            && empty($settings['calendar_event_webhook_url_encrypted'])) {
            $status = 'critical';
            $problems[] = 'fluxo do ciclo Google não configurado';
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
        $confirmedWithoutEvent = 0;
        $failedSyncs = 0;
        $lastMaintenance = null;
        if ($this->hasColumn('calendar_appointments', 'google_sync_key')) {
            $confirmedWithoutEvent = (int) $this->value(
                'SELECT COUNT(*) FROM calendar_appointments
                 WHERE tenant_id = :tenant_id
                   AND availability_source IN ("google_free_slots", "internal_fallback")
                   AND status IN ("scheduled", "confirmed")
                   AND starts_at >= NOW()
                   AND (google_event_id IS NULL OR google_event_id = "")',
                ['tenant_id' => $tenantId],
                0
            );
            $failedSyncs = (int) $this->value(
                'SELECT COUNT(*) FROM calendar_appointments
                 WHERE tenant_id = :tenant_id AND (sync_status = "failed" OR google_event_state = "error")',
                ['tenant_id' => $tenantId],
                0
            );
            if ($this->tableExists('calendar_maintenance_runs')) {
                $lastMaintenance = $this->row(
                    'SELECT * FROM calendar_maintenance_runs WHERE tenant_id = :tenant_id OR tenant_id IS NULL ORDER BY id DESC LIMIT 1',
                    ['tenant_id' => $tenantId]
                );
            }
        }
        if ($confirmedWithoutEvent > 0) {
            $status = $status === 'critical' ? 'critical' : 'warning';
            $problems[] = $confirmedWithoutEvent . ' compromisso(s) confirmado(s) sem evento Google';
        }
        if ($failedSyncs > 0) {
            $status = $status === 'critical' ? 'critical' : 'warning';
            $problems[] = $failedSyncs . ' sincronização(ões) com falha';
        }
        $summary = $problems ? 'Revisar: ' . implode('; ', $problems) . '.' : 'Agenda configurada e sem falhas recentes.';
        return [$this->check('Agenda', 'calendar.integration', 'Agenda e Google Calendar', $status, $summary, [
            'Modo' => $mode === 'marked_events' ? 'Eventos VAGO' : 'Espaços livres',
            'Calendário' => (string) ($settings['google_calendar_id'] ?? 'primary'),
            'Última busca' => $this->formatDatabaseDate($lastRequest['created_at'] ?? null, 'Nenhuma'),
            'Situação da última busca' => (string) ($lastRequest['status'] ?? 'Não disponível'),
            'Última sincronização Google' => $this->formatDatabaseDate($lastSync['created_at'] ?? null, 'Nenhuma'),
            'Última manutenção' => $this->formatDatabaseDate($lastMaintenance['finished_at'] ?? $lastMaintenance['started_at'] ?? null, 'Nenhuma'),
            'Pré-reservas vencidas' => (string) $expiredHolds,
            'Confirmados sem evento' => (string) $confirmedWithoutEvent,
            'Sincronizações com falha' => (string) $failedSyncs,
        ], '/calendar?section=availability', 80)];
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
            'Último login' => $this->formatDatabaseDate($summary['last_login_at'] ?? null, 'Nenhum'),
        ], '/users', 100)];
    }

    /**
     * Retorna uma leitura completa das configurações operacionais da empresa.
     * Segredos nunca são exibidos: chaves e tokens aparecem apenas como configurados ou ausentes.
     *
     * @param array<int,array<string,mixed>> $checks
     * @return array<string,mixed>
     */
    private function configurationInventory(int $tenantId, array $checks = []): array
    {
        $tenant = $this->row('SELECT * FROM tenants WHERE id = :id LIMIT 1', ['id' => $tenantId]) ?? [];
        $checkMap = [];
        foreach ($checks as $check) {
            $key = (string) ($check['component_key'] ?? $check['key'] ?? '');
            if ($key !== '') {
                $checkMap[$key] = $check;
            }
        }

        $access = (new AccessControlService())->statusForTenant($tenantId);
        $subscription = $this->row(
            'SELECT ts.*, sp.name AS plan_name, sp.plan_key, sp.description AS plan_description,
                    sp.features_json, sp.limits_json
             FROM tenant_subscriptions ts
             LEFT JOIN saas_plans sp ON sp.id = ts.plan_id
             WHERE ts.tenant_id = :tenant_id
             ORDER BY ts.id DESC LIMIT 1',
            ['tenant_id' => $tenantId]
        ) ?? [];
        $invoiceSummary = $this->row(
            'SELECT COUNT(*) AS total,
                    COALESCE(SUM(status = "open"),0) AS open_count,
                    COALESCE(SUM(status = "overdue"),0) AS overdue_count,
                    MIN(CASE WHEN status IN ("open","overdue") THEN due_date END) AS oldest_due,
                    MAX(paid_at) AS last_paid_at
             FROM tenant_invoices WHERE tenant_id = :tenant_id',
            ['tenant_id' => $tenantId]
        ) ?? [];

        $companyFields = [
            'Nome de exibição' => $this->valueOr($tenant['name'] ?? null),
            'Razão social' => $this->valueOr($tenant['legal_name'] ?? null),
            'CNPJ/CPF' => $this->valueOr($tenant['document'] ?? null),
            'Situação cadastral' => $this->valueOr($tenant['status'] ?? null),
            'Plano cadastral' => $this->valueOr($tenant['plan'] ?? null),
            'Segmento' => $this->valueOr($tenant['segment'] ?? null),
            'E-mail comercial' => $this->valueOr($tenant['email'] ?? null),
            'Telefone' => $this->valueOr($tenant['phone'] ?? null),
            'WhatsApp comercial' => $this->valueOr($tenant['commercial_whatsapp'] ?? null),
            'Site' => $this->valueOr($tenant['website'] ?? null),
            'Instagram' => $this->valueOr($tenant['instagram'] ?? null),
            'Endereço' => $this->addressLabel($tenant),
            'Etapa de implantação' => (string) ($tenant['onboarding_step'] ?? 1),
            'Implantação concluída em' => $this->valueOr($tenant['onboarding_completed_at'] ?? null),
            'Cadastrada em' => $this->valueOr($tenant['created_at'] ?? null),
            'Atualizada em' => $this->valueOr($tenant['updated_at'] ?? null),
        ];
        $accessFields = [
            'Acesso atual' => !empty($access['allowed']) ? 'Liberado' : 'Bloqueado',
            'Motivo da validação' => $this->valueOr($access['message'] ?? null),
            'Código da validação' => $this->valueOr($access['code'] ?? null),
            'Tolerância financeira' => (string) ($access['grace_days'] ?? Env::get('BILLING_ACCESS_GRACE_DAYS', 5)) . ' dia(s)',
            'Plano da assinatura' => $this->valueOr($subscription['plan_name'] ?? $subscription['plan_key'] ?? null),
            'Situação da assinatura' => $this->valueOr($subscription['billing_status'] ?? null),
            'Ciclo de cobrança' => $this->valueOr($subscription['billing_cycle'] ?? null),
            'Valor' => isset($subscription['amount']) ? 'R$ ' . number_format((float) $subscription['amount'], 2, ',', '.') : 'Não informado',
            'Início da vigência' => $this->valueOr($subscription['current_period_starts_at'] ?? null),
            'Fim da vigência' => $this->valueOr($subscription['current_period_ends_at'] ?? null),
            'Fim do teste' => $this->valueOr($subscription['trial_ends_at'] ?? null),
            'Próxima cobrança' => $this->valueOr($subscription['next_billing_at'] ?? null),
            'Cobranças abertas' => (string) ($invoiceSummary['open_count'] ?? 0),
            'Cobranças vencidas' => (string) ($invoiceSummary['overdue_count'] ?? 0),
            'Vencimento pendente mais antigo' => $this->valueOr($invoiceSummary['oldest_due'] ?? null),
            'Último pagamento' => $this->valueOr($invoiceSummary['last_paid_at'] ?? null),
        ];

        $groups = [[
            'key' => 'company',
            'label' => 'Empresa, plano e acesso',
            'description' => 'Dados cadastrais, vigência, cobrança e motivo atual de liberação ou bloqueio.',
            'action_url' => '/company-settings?id=' . $tenantId,
            'records' => [
                $this->configurationRecord('Dados da empresa', (string) ($tenant['name'] ?? 'Empresa'), (string) ($tenant['status'] ?? 'info'), $companyFields, [
                    'Sobre a empresa' => $this->valueOr($tenant['company_about'] ?? null),
                    'Serviços ou produtos' => $this->valueOr($tenant['company_services'] ?? null),
                    'Diferenciais' => $this->valueOr($tenant['company_differentials'] ?? null),
                    'Horário comercial informado' => $this->valueOr($tenant['company_business_hours'] ?? null),
                    'Observações internas' => $this->valueOr($tenant['company_notes'] ?? null),
                ]),
                $this->configurationRecord('Assinatura e acesso', (string) ($subscription['plan_name'] ?? 'Sem assinatura'), !empty($access['allowed']) ? 'ok' : 'critical', $accessFields, [
                    'Recursos do plano' => $this->jsonReadable($subscription['features_json'] ?? null),
                    'Limites do plano' => $this->jsonReadable($subscription['limits_json'] ?? null),
                    'Observações da assinatura' => $this->valueOr($subscription['notes'] ?? null),
                ]),
            ],
        ]];

        $instances = $this->all(
            'SELECT i.*,
                    (SELECT COUNT(*) FROM ai_agents a WHERE a.instance_id = i.id) AS linked_agents
             FROM evolution_instances i WHERE i.tenant_id = :tenant_id
             ORDER BY i.is_default DESC, i.id',
            ['tenant_id' => $tenantId]
        );
        $instanceRecords = [];
        foreach ($instances as $instance) {
            $id = (int) ($instance['id'] ?? 0);
            $healthDetails = (array) ($checkMap['instance.' . $id]['details'] ?? []);
            $fields = [
                'Nome interno' => $this->valueOr($instance['name'] ?? null),
                'Identificador na Evolution' => $this->valueOr($instance['instance_name'] ?? null),
                'URL base' => $this->valueOr($instance['base_url'] ?? null),
                'API Key' => !empty($instance['api_key_encrypted']) ? 'Configurada e protegida' : 'Não configurada',
                'Situação salva' => $this->valueOr($instance['status'] ?? null),
                'Conexão padrão' => $this->yesNo($instance['is_default'] ?? 0),
                'Assistentes vinculados' => (string) ($instance['linked_agents'] ?? 0),
                'Criada em' => $this->valueOr($instance['created_at'] ?? null),
                'Atualizada em' => $this->valueOr($instance['updated_at'] ?? null),
            ];
            foreach ($healthDetails as $label => $value) {
                $fields[(string) $label] = (string) $value;
            }
            $instanceRecords[] = $this->configurationRecord(
                'Conexão WhatsApp — ' . (string) ($instance['name'] ?? $instance['instance_name'] ?? '#' . $id),
                (string) ($instance['instance_name'] ?? ''),
                (string) ($checkMap['instance.' . $id]['status'] ?? $instance['status'] ?? 'info'),
                $fields
            );
        }
        if (!$instanceRecords) {
            $instanceRecords[] = $this->configurationRecord('Conexões WhatsApp', 'Nenhuma conexão cadastrada', 'critical', ['Situação' => 'Não configurada']);
        }
        $groups[] = [
            'key' => 'whatsapp',
            'label' => 'WhatsApp e Evolution',
            'description' => 'Conexões, identificadores, webhooks e atividade recente, sem expor chaves.',
            'action_url' => '/instances',
            'records' => $instanceRecords,
        ];

        $credentials = $this->all(
            'SELECT c.*, a.name AS agent_name
             FROM ai_provider_credentials c
             LEFT JOIN ai_agents a ON a.id = c.agent_id
             WHERE c.tenant_id = :tenant_id
             ORDER BY c.is_default DESC, c.id',
            ['tenant_id' => $tenantId]
        );
        $credentialsByAgent = [];
        foreach ($credentials as $credential) {
            $credentialsByAgent[(int) ($credential['agent_id'] ?? 0)][] = $credential;
        }

        $groupRulesRows = $this->all(
            'SELECT agent_id, contact_group, allow_pre_schedule,
                    require_demand_before_pre_schedule, allow_reschedule_without_demand, instructions
             FROM ai_agent_group_rules
             WHERE tenant_id = :tenant_id
             ORDER BY agent_id, contact_group',
            ['tenant_id' => $tenantId]
        );
        $groupRulesByAgent = [];
        foreach ($groupRulesRows as $groupRuleRow) {
            $groupRulesByAgent[(int) ($groupRuleRow['agent_id'] ?? 0)][] = $groupRuleRow;
        }

        $agents = $this->all(
            'SELECT a.*, i.name AS instance_label, i.instance_name
             FROM ai_agents a
             LEFT JOIN evolution_instances i ON i.id = a.instance_id
             WHERE a.tenant_id = :tenant_id
             ORDER BY a.is_default DESC, a.id',
            ['tenant_id' => $tenantId]
        );
        $agentRecords = [];
        foreach ($agents as $agent) {
            $id = (int) ($agent['id'] ?? 0);
            $agentCredentials = array_merge($credentialsByAgent[0] ?? [], $credentialsByAgent[$id] ?? []);
            $credentialLabels = [];
            foreach ($agentCredentials as $credential) {
                $credentialLabels[] = sprintf(
                    '%s · %s · %s · chave %s',
                    (string) ($credential['label'] ?? 'Credencial'),
                    (string) ($credential['provider'] ?? ''),
                    (string) ($credential['status'] ?? ''),
                    !empty($credential['api_key_encrypted']) ? 'configurada' : 'ausente'
                );
            }
            $groupRuleLines = [];
            foreach ($groupRulesByAgent[$id] ?? [] as $groupRule) {
                $groupKey = (string) ($groupRule['contact_group'] ?? 'unclassified');
                $groupLabel = ConversationFlowService::GROUPS[$groupKey] ?? $groupKey;
                $groupRuleLines[] = $groupLabel . ': agenda ' . ((int) ($groupRule['allow_pre_schedule'] ?? 0) === 1 ? 'permitida' : 'bloqueada') .
                    '; demanda ' . ((int) ($groupRule['require_demand_before_pre_schedule'] ?? 0) === 1 ? 'obrigatória' : 'dispensada') .
                    '; remarcação sem repetir ' . ((int) ($groupRule['allow_reschedule_without_demand'] ?? 0) === 1 ? 'sim' : 'não') .
                    (trim((string) ($groupRule['instructions'] ?? '')) !== '' ? '; orientação: ' . trim((string) $groupRule['instructions']) : '');
            }

            $fields = [
                'Nome' => $this->valueOr($agent['name'] ?? null),
                'Área de atendimento' => $this->valueOr($agent['segment'] ?? null),
                'Situação' => $this->valueOr($agent['status'] ?? null),
                'Assistente principal' => $this->yesNo($agent['is_default'] ?? 0),
                'Respostas automáticas' => $this->yesNo($agent['auto_reply_enabled'] ?? 0),
                'Conexão WhatsApp' => $this->valueOr($agent['instance_label'] ?? $agent['instance_name'] ?? null),
                'Provedor' => $this->valueOr($agent['model_provider'] ?? null),
                'Modelo' => $this->valueOr($agent['model_name'] ?? null),
                'Criatividade' => isset($agent['temperature']) ? (string) $agent['temperature'] : 'Não informada',
                'Mensagens lembradas' => (string) ($agent['max_context_messages'] ?? 12),
                'Intervalo mínimo' => (string) ($agent['cooldown_seconds'] ?? 0) . ' segundo(s)',
                'Responder a reações' => $this->yesNo($agent['reply_to_reactions'] ?? 0),
                'Palavras de atendimento humano' => $this->valueOr($agent['handoff_keywords'] ?? null),
                'Ação ao chamar uma pessoa' => $this->valueOr($agent['handoff_action'] ?? null),
                'Horário de atendimento ativado' => $this->yesNo($agent['business_hours_enabled'] ?? 0),
                'Fuso horário' => $this->valueOr($agent['business_timezone'] ?? null),
                'Horários configurados' => $this->jsonReadable($agent['business_hours_json'] ?? null),
                'Integração externa do assistente' => $this->yesNo($agent['n8n_enabled'] ?? 0),
                'Webhook externo' => !empty($agent['n8n_webhook_url']) ? $this->maskEndpoint((string) $agent['n8n_webhook_url']) : 'Não configurado',
                'Credenciais disponíveis' => $credentialLabels ? implode("\n", $credentialLabels) : 'Nenhuma credencial encontrada',
            ];
            $agentRecords[] = $this->configurationRecord(
                'Assistente — ' . (string) ($agent['name'] ?? '#' . $id),
                (string) ($agent['segment'] ?? ''),
                (string) ($checkMap['agent.' . $id]['status'] ?? $agent['status'] ?? 'info'),
                $fields,
                [
                    'Instruções principais' => $this->valueOr($agent['system_prompt'] ?? null),
                    'Base de conhecimento' => $this->valueOr($agent['knowledge_base'] ?? null),
                    'Construtor do prompt' => $this->jsonReadable($agent['prompt_builder_json'] ?? null),
                    'Mensagem fora do horário' => $this->valueOr($agent['after_hours_message'] ?? null),
                    'Mensagem de encaminhamento humano' => $this->valueOr($agent['human_handoff_message'] ?? null),
                    'Regras por grupo de contato' => $groupRuleLines ? implode("\n", $groupRuleLines) : 'Usando regras padrão da plataforma',
                ]
            );
        }
        foreach ($credentials as $credential) {
            $scope = !empty($credential['agent_id']) ? 'Assistente: ' . (string) ($credential['agent_name'] ?? '#' . $credential['agent_id']) : 'Toda a empresa';
            $agentRecords[] = $this->configurationRecord(
                'Credencial de IA — ' . (string) ($credential['label'] ?? '#' . $credential['id']),
                $scope,
                (string) ($credential['status'] ?? 'info'),
                [
                    'Escopo' => $scope,
                    'Provedor' => $this->valueOr($credential['provider'] ?? null),
                    'Modelo padrão' => $this->valueOr($credential['default_model'] ?? null),
                    'URL base' => $this->valueOr($credential['base_url'] ?? null),
                    'Situação' => $this->valueOr($credential['status'] ?? null),
                    'Credencial padrão' => $this->yesNo($credential['is_default'] ?? 0),
                    'API Key' => !empty($credential['api_key_encrypted']) ? 'Configurada e protegida' : 'Não configurada',
                    'Atualizada em' => $this->valueOr($credential['updated_at'] ?? null),
                ]
            );
        }
        if (!$agentRecords) {
            $agentRecords[] = $this->configurationRecord('Assistentes e credenciais', 'Nenhum assistente cadastrado', 'critical', ['Situação' => 'Não configurado']);
        }
        $groups[] = [
            'key' => 'ai',
            'label' => 'Assistentes e credenciais de IA',
            'description' => 'Comportamento, prompt, horários, intervalo, reações, modelo e credenciais protegidas.',
            'action_url' => '/agents?tenant_id=' . $tenantId,
            'records' => $agentRecords,
        ];

        $flows = $this->all('SELECT * FROM n8n_tenant_flows WHERE tenant_id = :tenant_id ORDER BY id', ['tenant_id' => $tenantId]);
        $flowRecords = [];
        foreach ($flows as $flow) {
            $id = (int) ($flow['id'] ?? 0);
            $flowRecords[] = $this->configurationRecord(
                'Fluxo n8n — ' . (string) ($flow['name'] ?? '#' . $id),
                (string) ($flow['flow_key'] ?? ''),
                (string) ($checkMap['flow.' . $id]['status'] ?? $flow['status'] ?? 'info'),
                [
                    'Chave do fluxo' => $this->valueOr($flow['flow_key'] ?? null),
                    'Descrição' => $this->valueOr($flow['description'] ?? null),
                    'Situação' => $this->valueOr($flow['status'] ?? null),
                    'Eventos' => $this->jsonReadable($flow['events_json'] ?? null),
                    'URL do webhook' => $this->maskedEncryptedEndpoint($flow['webhook_url_encrypted'] ?? null),
                    'Token secreto' => !empty($flow['secret_token_encrypted']) ? 'Configurado e protegido' : 'Não configurado',
                    'Último sucesso' => $this->valueOr($flow['last_success_at'] ?? null),
                    'Último erro' => $this->valueOr($flow['last_error_at'] ?? null),
                    'Atualizado em' => $this->valueOr($flow['updated_at'] ?? null),
                ],
                ['Mensagem do último erro' => $this->valueOr($flow['last_error'] ?? null)]
            );
        }
        if (!$flowRecords) {
            $flowRecords[] = $this->configurationRecord('Fluxos n8n', 'Nenhum fluxo cadastrado', 'info', ['Situação' => 'Não configurado']);
        }
        $groups[] = [
            'key' => 'n8n',
            'label' => 'Integrações e fluxos n8n',
            'description' => 'Eventos, webhooks mascarados, tokens protegidos e situação das últimas execuções.',
            'action_url' => '/n8n-flows',
            'records' => $flowRecords,
        ];

        $calendar = $this->row('SELECT * FROM tenant_calendar_availability_settings WHERE tenant_id = :tenant_id LIMIT 1', ['tenant_id' => $tenantId]) ?? [];
        $preSchedule = $this->row('SELECT * FROM tenant_pre_schedule_settings WHERE tenant_id = :tenant_id LIMIT 1', ['tenant_id' => $tenantId]) ?? [];
        $calendarFields = [
            'Agenda Inteligente ativada' => $this->yesNo($calendar['enabled'] ?? 0),
            'Modo de disponibilidade' => ($calendar['availability_mode'] ?? 'free_slots') === 'marked_events' ? 'Eventos VAGO' : 'Espaços livres',
            'Exigir disponibilidade antes de aprovar' => $this->yesNo($calendar['require_before_approval'] ?? 0),
            'Consultar automaticamente' => $this->yesNo($calendar['auto_request_on_pre_schedule'] ?? 0),
            'Usar n8n' => $this->yesNo($calendar['use_n8n'] ?? 0),
            'Fallback interno' => $this->yesNo($calendar['use_internal_fallback'] ?? 0),
            'Fuso horário' => $this->valueOr($calendar['timezone'] ?? null),
            'Duração padrão' => (string) ($calendar['default_duration_minutes'] ?? 0) . ' minuto(s)',
            'Intervalo entre horários' => (string) ($calendar['slot_interval_minutes'] ?? 0) . ' minuto(s)',
            'Intervalo de segurança' => (string) ($calendar['buffer_minutes'] ?? 0) . ' minuto(s)',
            'Antecedência mínima' => (string) ($calendar['min_notice_hours'] ?? 0) . ' hora(s)',
            'Dias pesquisados' => (string) ($calendar['search_days_ahead'] ?? 0),
            'Máximo de sugestões' => (string) ($calendar['max_suggestions'] ?? 0),
            'Dias de atendimento' => $this->workdaysLabel($calendar['workdays_json'] ?? null),
            'Horário de atendimento' => $this->workingHoursLabel($calendar['working_hours_json'] ?? null),
            'Google Calendar' => $this->valueOr($calendar['google_calendar_id'] ?? null),
            'Fuso UTC Google' => $this->valueOr($calendar['google_utc_offset'] ?? null),
            'Ignorar eventos transparentes' => $this->yesNo($calendar['ignore_transparent_events'] ?? 0),
            'Exigir VAGO como disponível' => $this->yesNo($calendar['marked_require_transparent'] ?? 0),
            'Título VAGO online' => $this->valueOr($calendar['marked_online_title'] ?? null),
            'Título VAGO presencial' => $this->valueOr($calendar['marked_in_person_title'] ?? null),
            'Prefixo de pré-reserva' => $this->valueOr($calendar['marked_hold_prefix'] ?? null),
            'Prefixo de confirmação' => $this->valueOr($calendar['marked_confirmed_prefix'] ?? null),
            'Tempo de pré-reserva' => (string) ($calendar['hold_minutes'] ?? 0) . ' minuto(s)',
            'Revalidar antes de atualizar' => $this->yesNo($calendar['revalidate_before_update'] ?? 0),
            'Restaurar ao cancelar' => $this->yesNo($calendar['restore_on_cancel'] ?? 0),
            'Webhook espaços livres' => $this->maskedEncryptedEndpoint($calendar['free_slots_webhook_url_encrypted'] ?? $calendar['n8n_webhook_url_encrypted'] ?? null),
            'Webhook eventos VAGO' => $this->maskedEncryptedEndpoint($calendar['marked_events_webhook_url_encrypted'] ?? null),
            'Token da agenda' => !empty($calendar['secret_token_encrypted']) ? 'Configurado e protegido' : 'Não configurado',
        ];
        $preFields = [
            'Pré-agendamento ativado' => $this->yesNo($preSchedule['enabled'] ?? 0),
            'Exigir aprovação humana' => $this->yesNo($preSchedule['require_human_approval'] ?? 0),
            'IA pode sugerir horários' => $this->yesNo($preSchedule['ai_can_suggest_slots'] ?? 0),
            'IA pode confirmar' => $this->yesNo($preSchedule['ai_can_confirm'] ?? 0),
            'Enviar mensagem ao aprovar' => $this->yesNo($preSchedule['send_approval_message'] ?? 0),
            'Duração padrão' => (string) ($preSchedule['default_duration_minutes'] ?? 0) . ' minuto(s)',
        ];
        $groups[] = [
            'key' => 'calendar',
            'label' => 'Agenda e pré-agendamento',
            'description' => 'Regras de disponibilidade, Google Calendar, eventos VAGO e mensagens enviadas ao contato.',
            'action_url' => '/calendar?section=availability',
            'records' => [
                $this->configurationRecord('Agenda Inteligente', 'Disponibilidade e Google Calendar', !empty($calendar['enabled']) ? 'ok' : 'info', $calendarFields),
                $this->configurationRecord('Pré-agendamento', 'Regras e mensagens ao cliente', !empty($preSchedule['enabled']) ? 'ok' : 'info', $preFields, [
                    'Mensagem padrão' => $this->valueOr($preSchedule['default_message'] ?? null),
                    'Mensagem para coletar preferência' => $this->valueOr($preSchedule['collect_message'] ?? null),
                    'Mensagem de aprovação' => $this->valueOr($preSchedule['approved_message'] ?? null),
                    'Mensagem de recusa' => $this->valueOr($preSchedule['rejected_message'] ?? null),
                    'Mensagem de remarcação' => $this->valueOr($preSchedule['reschedule_message'] ?? null),
                ]),
            ],
        ];

        $users = $this->all('SELECT * FROM users WHERE tenant_id = :tenant_id ORDER BY role, name', ['tenant_id' => $tenantId]);
        $userRecords = [$this->configurationRecord('Política de acesso', 'Regras globais aplicadas à empresa', 'info', [
            'Limite de tentativas incorretas' => (string) Env::get('SECURITY_LOGIN_ATTEMPT_LIMIT', 6),
            'Janela de bloqueio' => (string) Env::get('SECURITY_LOGIN_ATTEMPT_WINDOW_MINUTES', 15) . ' minuto(s)',
            'Sessão ociosa' => (string) Env::get('SECURITY_SESSION_IDLE_MINUTES', 120) . ' minuto(s)',
            'Headers de segurança' => $this->yesNo(Env::get('SECURITY_HEADERS_ENABLED', true)),
            'Tolerância de cobrança' => (string) Env::get('BILLING_ACCESS_GRACE_DAYS', 5) . ' dia(s)',
        ])];
        foreach ($users as $user) {
            $locked = !empty($user['locked_until']) && strtotime((string) $user['locked_until']) > time();
            $userRecords[] = $this->configurationRecord(
                'Usuário — ' . (string) ($user['name'] ?? '#' . $user['id']),
                (string) ($user['email'] ?? ''),
                $locked ? 'warning' : (string) ($user['status'] ?? 'info'),
                [
                    'Perfil' => $this->valueOr($user['role'] ?? null),
                    'Situação' => $this->valueOr($user['status'] ?? null),
                    'Tentativas incorretas' => (string) ($user['failed_login_count'] ?? 0),
                    'Última tentativa incorreta' => $this->valueOr($user['last_failed_login_at'] ?? null),
                    'Bloqueado até' => $this->valueOr($user['locked_until'] ?? null),
                    'Motivo do bloqueio' => $this->valueOr($user['lock_reason'] ?? null),
                    'Último acesso' => $this->valueOr($user['last_login_at'] ?? null),
                    'Cadastrado em' => $this->valueOr($user['created_at'] ?? null),
                ]
            );
        }
        $groups[] = [
            'key' => 'security',
            'label' => 'Usuários e segurança',
            'description' => 'Perfis, bloqueios, tentativas incorretas e políticas globais aplicadas ao acesso.',
            'action_url' => '/users',
            'records' => $userRecords,
        ];

        $moduleService = new TenantModuleService();
        $moduleSettings = $moduleService->settingsForTenant($tenantId);
        $moduleFields = [];
        foreach (TenantModuleService::modules() as $key => $definition) {
            $enabled = (bool) ($moduleSettings[$key]['is_enabled'] ?? $definition['default_enabled']);
            $visible = (bool) ($moduleSettings[$key]['is_visible'] ?? $definition['default_visible']);
            $moduleFields[(string) $definition['label']] = ($enabled ? 'Acesso permitido' : 'Acesso bloqueado') . ' · ' . ($visible ? 'visível no menu' : 'oculto no menu');
        }
        $notifications = $this->row('SELECT * FROM tenant_notification_preferences WHERE tenant_id = :tenant_id LIMIT 1', ['tenant_id' => $tenantId]) ?? [];
        $privacy = $this->row('SELECT * FROM tenant_privacy_settings WHERE tenant_id = :tenant_id LIMIT 1', ['tenant_id' => $tenantId]) ?? [];
        $groups[] = [
            'key' => 'experience',
            'label' => 'Menus, notificações e privacidade',
            'description' => 'O que a equipe do cliente vê, quais alertas recebe e quais regras de privacidade estão vigentes.',
            'action_url' => '/company-settings?id=' . $tenantId,
            'records' => [
                $this->configurationRecord('Módulos do cliente', 'Visibilidade e permissão de acesso', 'info', $moduleFields),
                $this->configurationRecord('Preferências de notificação', 'Alertas exibidos no sininho', 'info', [
                    'Novas mensagens' => $this->yesNo($notifications['messages_enabled'] ?? 1),
                    'Falhas do assistente' => $this->yesNo($notifications['ai_errors_enabled'] ?? 1),
                    'Falhas de automação' => $this->yesNo($notifications['automation_errors_enabled'] ?? 1),
                    'Agenda' => $this->yesNo($notifications['calendar_enabled'] ?? 1),
                    'Financeiro e assinatura' => $this->yesNo($notifications['billing_enabled'] ?? 1),
                    'Avisos da plataforma' => $this->yesNo($notifications['system_enabled'] ?? 1),
                    'Atualizada em' => $this->valueOr($notifications['updated_at'] ?? null),
                ]),
                $this->configurationRecord('Privacidade e LGPD', 'Políticas aplicadas à empresa', 'info', [
                    'Exigir aceite da empresa' => $this->yesNo($privacy['require_company_acceptance'] ?? 1),
                    'Versão da política' => $this->valueOr($privacy['policy_version'] ?? null),
                    'Responsável/DPO' => $this->valueOr($privacy['dpo_name'] ?? null),
                    'E-mail do responsável' => $this->valueOr($privacy['dpo_email'] ?? null),
                    'Retenção de dados' => (string) ($privacy['retention_days'] ?? 365) . ' dia(s)',
                    'Permitir exportação' => $this->yesNo($privacy['allow_export_requests'] ?? 1),
                    'Permitir exclusão' => $this->yesNo($privacy['allow_delete_requests'] ?? 1),
                ], [
                    'Política de privacidade' => $this->valueOr($privacy['privacy_policy_text'] ?? null),
                    'Termos de uso' => $this->valueOr($privacy['terms_text'] ?? null),
                ]),
            ],
        ];

        return [
            'generated_at' => date('Y-m-d H:i:s'),
            'groups' => $groups,
            'record_count' => array_sum(array_map(static fn (array $group): int => count($group['records'] ?? []), $groups)),
            'secrets_notice' => 'Chaves de API, tokens e senhas permanecem ocultos. A tela mostra apenas se estão configurados.',
        ];
    }

    /** @return array<string,mixed> */
    private function configurationRecord(string $title, string $subtitle, string $tone, array $fields, array $longFields = []): array
    {
        $allowed = ['ok', 'active', 'connected', 'warning', 'critical', 'info', 'inactive', 'suspended', 'blocked'];
        $tone = strtolower(trim($tone));
        if (!in_array($tone, $allowed, true)) {
            $tone = 'info';
        }
        if (in_array($tone, ['active', 'connected'], true)) {
            $tone = 'ok';
        } elseif (in_array($tone, ['inactive', 'suspended', 'blocked'], true)) {
            $tone = 'warning';
        }
        return compact('title', 'subtitle', 'tone', 'fields') + ['long_fields' => $longFields];
    }

    /**
     * Resume conversas realmente aguardando resposta:
     * intervalo, falha da IA/Evolution ou execução interrompida, sempre sem
     * qualquer mensagem de saída posterior.
     *
     * @return array{conversation_count:int,message_count:int,oldest_pending_at:?string}
     */
    private function pendingAiResponses(int $tenantId, int $agentId, bool $replyToReactions): array
    {
        $row = $this->row(
            'SELECT
                COUNT(DISTINCT c.id) AS conversation_count,
                COUNT(cm.id) AS message_count,
                MIN(cm.sent_at) AS oldest_pending_at
             FROM conversations c
             INNER JOIN conversation_messages cm
                ON cm.conversation_id = c.id
               AND cm.tenant_id = c.tenant_id
             WHERE c.tenant_id = :tenant_id
               AND (
                    SELECT aa.id
                    FROM ai_agents aa
                    WHERE aa.tenant_id = c.tenant_id
                      AND aa.status = "active"
                      AND aa.auto_reply_enabled = 1
                      AND (
                            aa.instance_id = c.evolution_instance_id
                            OR aa.instance_id IS NULL
                            OR aa.is_default = 1
                      )
                    ORDER BY (aa.instance_id = c.evolution_instance_id) DESC,
                             aa.is_default DESC,
                             aa.id DESC
                    LIMIT 1
               ) = :selected_agent_id
               AND c.attendance_mode = "ai"
               AND c.status <> "closed"
               AND cm.direction = "incoming"
               AND (:reply_to_reactions = 1 OR cm.message_type <> "reaction")
               AND (
                    COALESCE((
                        SELECT al.event
                        FROM ai_automation_logs al
                        WHERE al.incoming_message_id = cm.id
                        ORDER BY al.id DESC
                        LIMIT 1
                    ), "") IN ("ai.cooldown", "ai.failed")
                    OR (
                        NOT EXISTS (
                            SELECT 1
                            FROM ai_automation_logs al_msg
                            WHERE al_msg.incoming_message_id = cm.id
                        )
                        AND cm.sent_at <= DATE_SUB(NOW(), INTERVAL 2 MINUTE)
                        AND (
                            COALESCE((
                                SELECT al_legacy.event
                                FROM ai_automation_logs al_legacy
                                WHERE al_legacy.tenant_id = c.tenant_id
                                  AND al_legacy.conversation_id = c.id
                                  AND al_legacy.agent_id = :legacy_agent_id
                                  AND al_legacy.created_at >= cm.sent_at
                                ORDER BY al_legacy.id DESC
                                LIMIT 1
                            ), "") IN ("ai.cooldown", "ai.failed")
                            OR NOT EXISTS (
                                SELECT 1
                                FROM ai_automation_logs al_missing
                                WHERE al_missing.tenant_id = c.tenant_id
                                  AND al_missing.conversation_id = c.id
                                  AND al_missing.agent_id = :legacy_agent_id_missing
                                  AND al_missing.created_at >= cm.sent_at
                            )
                        )
                    )
                    OR EXISTS (
                        SELECT 1
                        FROM conversation_messages failed_outgoing
                        WHERE failed_outgoing.conversation_id = cm.conversation_id
                          AND failed_outgoing.direction = "outgoing"
                          AND failed_outgoing.sender_type = "ai"
                          AND failed_outgoing.status IN ("failed", "pending")
                          AND COALESCE((
                                SELECT al_failed.event
                                FROM ai_automation_logs al_failed
                                WHERE al_failed.incoming_message_id = cm.id
                                ORDER BY al_failed.id DESC
                                LIMIT 1
                          ), "") IN ("", "ai.replied", "ai.failed")
                          AND (
                                failed_outgoing.sent_at > cm.sent_at
                                OR (failed_outgoing.sent_at = cm.sent_at AND failed_outgoing.id > cm.id)
                          )
                    )
               )
               AND NOT EXISTS (
                    SELECT 1
                    FROM conversation_messages outgoing
                    WHERE outgoing.conversation_id = c.id
                      AND outgoing.direction = "outgoing"
                      AND outgoing.status IN ("sent", "delivered", "read")
                      AND (
                            outgoing.sent_at > cm.sent_at
                            OR (outgoing.sent_at = cm.sent_at AND outgoing.id > cm.id)
                      )
               )',
            [
                'tenant_id' => $tenantId,
                'selected_agent_id' => $agentId,
                'reply_to_reactions' => $replyToReactions ? 1 : 0,
                'legacy_agent_id' => $agentId,
                'legacy_agent_id_missing' => $agentId,
            ]
        );

        return [
            'conversation_count' => (int) ($row['conversation_count'] ?? 0),
            'message_count' => (int) ($row['message_count'] ?? 0),
            'oldest_pending_at' => !empty($row['oldest_pending_at']) ? (string) $row['oldest_pending_at'] : null,
        ];
    }

    /**
     * Datas TIMESTAMP do banco são convertidas do fuso real da sessão MySQL
     * para APP_TIMEZONE. Isso evita exibir UTC como se fosse horário local.
     */
    private function formatDatabaseDate(mixed $value, string $fallback = 'Não informado'): string
    {
        if ($value === null) {
            return $fallback;
        }

        $text = trim((string) $value);
        if ($text === '') {
            return $fallback;
        }

        try {
            $offset = $this->databaseOffsetMinutes();
            $sign = $offset >= 0 ? '+' : '-';
            $absolute = abs($offset);
            $sourceTimezone = new DateTimeZone(sprintf('%s%02d:%02d', $sign, intdiv($absolute, 60), $absolute % 60));
            $targetTimezone = new DateTimeZone((string) Env::get('APP_TIMEZONE', 'America/Sao_Paulo'));

            return (new DateTimeImmutable($text, $sourceTimezone))
                ->setTimezone($targetTimezone)
                ->format('d/m/Y H:i:s');
        } catch (Throwable) {
            return $text;
        }
    }

    private function databaseOffsetMinutes(): int
    {
        if ($this->databaseOffsetMinutes !== null) {
            return $this->databaseOffsetMinutes;
        }

        try {
            $offset = (int) $this->value(
                'SELECT TIMESTAMPDIFF(MINUTE, UTC_TIMESTAMP(), NOW())',
                [],
                0
            );
            $this->databaseOffsetMinutes = max(-840, min(840, $offset));
        } catch (Throwable) {
            $this->databaseOffsetMinutes = 0;
        }

        return $this->databaseOffsetMinutes;
    }

    private function valueOr(mixed $value, string $fallback = 'Não informado'): string
    {
        if ($value === null) {
            return $fallback;
        }
        $text = trim((string) $value);
        return $text !== '' ? $text : $fallback;
    }

    private function yesNo(mixed $value): string
    {
        return filter_var($value, FILTER_VALIDATE_BOOL) || (int) $value === 1 ? 'Sim' : 'Não';
    }

    private function addressLabel(array $tenant): string
    {
        $parts = array_filter([
            trim((string) ($tenant['address_line'] ?? '')),
            trim((string) ($tenant['address_number'] ?? '')),
            trim((string) ($tenant['address_complement'] ?? '')),
            trim((string) ($tenant['district'] ?? '')),
            trim((string) ($tenant['city'] ?? '')),
            trim((string) ($tenant['state'] ?? '')),
            trim((string) ($tenant['postal_code'] ?? '')),
        ], static fn (string $value): bool => $value !== '');
        return $parts ? implode(' · ', $parts) : 'Não informado';
    }

    private function jsonReadable(mixed $value): string
    {
        if ($value === null || $value === '') {
            return 'Não configurado';
        }
        $decoded = is_array($value) ? $value : json_decode((string) $value, true);
        if (!is_array($decoded)) {
            return $this->valueOr($value, 'Não configurado');
        }
        if (array_is_list($decoded)) {
            return $decoded ? implode(', ', array_map(static fn (mixed $item): string => is_scalar($item) ? (string) $item : json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $decoded)) : 'Nenhum';
        }
        $lines = [];
        foreach ($decoded as $key => $item) {
            if (is_bool($item)) {
                $item = $item ? 'Sim' : 'Não';
            } elseif (is_array($item)) {
                $item = json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } elseif ($item === null) {
                $item = 'Sem limite';
            }
            $lines[] = (string) $key . ': ' . (string) $item;
        }
        return $lines ? implode("\n", $lines) : 'Nenhum';
    }

    private function workdaysLabel(mixed $value): string
    {
        $days = is_array($value) ? $value : json_decode((string) ($value ?? ''), true);
        if (!is_array($days) || !$days) {
            return 'Não configurado';
        }
        $labels = [0 => 'Domingo', 1 => 'Segunda', 2 => 'Terça', 3 => 'Quarta', 4 => 'Quinta', 5 => 'Sexta', 6 => 'Sábado'];
        $result = [];
        foreach ($days as $day) {
            $result[] = $labels[(int) $day] ?? (string) $day;
        }
        return implode(', ', $result);
    }

    private function workingHoursLabel(mixed $value): string
    {
        $hours = is_array($value) ? $value : json_decode((string) ($value ?? ''), true);
        if (!is_array($hours)) {
            return 'Não configurado';
        }
        if (isset($hours['start'], $hours['end'])) {
            return (string) $hours['start'] . ' às ' . (string) $hours['end'];
        }
        return $this->jsonReadable($hours);
    }

    private function maskedEncryptedEndpoint(mixed $encrypted): string
    {
        if ($encrypted === null || trim((string) $encrypted) === '') {
            return 'Não configurado';
        }
        try {
            return $this->maskEndpoint(Crypto::decrypt((string) $encrypted));
        } catch (Throwable) {
            return 'Configurado e protegido';
        }
    }

    private function maskEndpoint(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return 'Não configurado';
        }
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return preg_replace('/(token|apikey|api_key|secret|signature|key)=([^&\s]+)/i', '$1=OCULTO', $url) ?: $url;
        }
        $scheme = (string) ($parts['scheme'] ?? 'https');
        $result = $scheme . '://' . (string) $parts['host'];
        if (!empty($parts['port'])) {
            $result .= ':' . (int) $parts['port'];
        }
        $result .= (string) ($parts['path'] ?? '');
        if (!empty($parts['query'])) {
            parse_str((string) $parts['query'], $query);
            foreach ($query as $key => &$value) {
                if (preg_match('/token|apikey|api_key|secret|signature|auth|key/i', (string) $key)) {
                    $value = 'OCULTO';
                }
            }
            unset($value);
            $encoded = http_build_query($query);
            if ($encoded !== '') {
                $result .= '?' . $encoded;
            }
        }
        return $result;
    }

    /**
     * Falhas reais registradas nos módulos de IA e integração nos últimos 7 dias.
     * Essas são exatamente as ocorrências usadas pelos badges "ainda não revisadas"
     * da listagem de empresas.
     *
     * @return array<int,array<string,mixed>>
     */
    private function recentOperationalOccurrences(int $tenantId, ?string $acknowledgedAt, int $limit = 100): array
    {
        $limit = max(10, min(200, $limit));
        $items = [];

        $aiRows = $this->all(
            'SELECT l.id, l.created_at, l.event, l.error_message, l.response_preview,
                    l.conversation_id, l.agent_id,
                    a.name AS agent_name,
                    ct.name AS contact_name, ct.phone AS contact_phone
             FROM ai_automation_logs l
             LEFT JOIN ai_agents a ON a.id = l.agent_id
             LEFT JOIN conversations c ON c.id = l.conversation_id
             LEFT JOIN contacts ct ON ct.id = c.contact_id
             WHERE l.tenant_id = :tenant_id
               AND l.status = "error"
               AND l.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
             ORDER BY l.id DESC
             LIMIT ' . $limit,
            ['tenant_id' => $tenantId]
        );
        foreach ($aiRows as $row) {
            $createdAt = (string) ($row['created_at'] ?? '');
            $event = trim((string) ($row['event'] ?? 'ai.failed'));
            $conversationId = (int) ($row['conversation_id'] ?? 0);
            $agentId = (int) ($row['agent_id'] ?? 0);
            $items[] = [
                'source' => 'ai',
                'source_label' => 'Assistente de IA',
                'id' => (int) ($row['id'] ?? 0),
                'event' => $event,
                'title' => $this->friendlyOccurrenceTitle('ai', $event),
                'message' => $this->valueOr($row['error_message'] ?? null, 'A execução da IA terminou com erro.'),
                'created_at' => $createdAt,
                'created_at_display' => $this->formatDatabaseDate($createdAt),
                'reviewed' => $this->occurrenceWasReviewed($createdAt, $acknowledgedAt),
                'related_url' => $conversationId > 0
                    ? '/conversations?conversation_id=' . $conversationId
                    : ($agentId > 0 ? '/agents?tenant_id=' . $tenantId : '/automations'),
                'secondary_url' => $agentId > 0 ? '/agents?tenant_id=' . $tenantId : null,
                'details' => array_filter([
                    'Evento' => $event,
                    'Assistente' => trim((string) ($row['agent_name'] ?? '')) ?: 'Não identificado',
                    'Contato' => trim((string) ($row['contact_name'] ?? '')) ?: (trim((string) ($row['contact_phone'] ?? '')) ?: 'Não identificado'),
                    'Conversa' => $conversationId > 0 ? '#' . $conversationId : 'Não vinculada',
                    'Prévia da resposta' => trim((string) ($row['response_preview'] ?? '')) ?: null,
                ], static fn (mixed $value): bool => $value !== null && $value !== ''),
            ];
        }

        $n8nRows = $this->all(
            'SELECT l.id, l.created_at, l.event, l.error_message, l.http_status,
                    l.request_url_masked, l.response_preview, l.flow_id,
                    f.name AS flow_name
             FROM n8n_flow_logs l
             LEFT JOIN n8n_tenant_flows f ON f.id = l.flow_id
             WHERE l.tenant_id = :tenant_id
               AND l.status = "error"
               AND l.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
             ORDER BY l.id DESC
             LIMIT ' . $limit,
            ['tenant_id' => $tenantId]
        );
        foreach ($n8nRows as $row) {
            $createdAt = (string) ($row['created_at'] ?? '');
            $event = trim((string) ($row['event'] ?? 'n8n.failed'));
            $flowId = (int) ($row['flow_id'] ?? 0);
            $items[] = [
                'source' => 'integration',
                'source_label' => 'Integração',
                'id' => (int) ($row['id'] ?? 0),
                'event' => $event,
                'title' => $this->friendlyOccurrenceTitle('integration', $event),
                'message' => $this->valueOr($row['error_message'] ?? null, 'A integração terminou com erro.'),
                'created_at' => $createdAt,
                'created_at_display' => $this->formatDatabaseDate($createdAt),
                'reviewed' => $this->occurrenceWasReviewed($createdAt, $acknowledgedAt),
                'related_url' => '/n8n-flows' . ($flowId > 0 ? '?flow_id=' . $flowId : ''),
                'secondary_url' => null,
                'details' => array_filter([
                    'Evento' => $event,
                    'Fluxo' => trim((string) ($row['flow_name'] ?? '')) ?: 'Não identificado',
                    'HTTP' => !empty($row['http_status']) ? (string) $row['http_status'] : 'Não informado',
                    'Destino' => trim((string) ($row['request_url_masked'] ?? '')) ?: null,
                    'Retorno' => trim((string) ($row['response_preview'] ?? '')) ?: null,
                ], static fn (mixed $value): bool => $value !== null && $value !== ''),
            ];
        }

        usort($items, static fn (array $left, array $right): int => strcmp((string) ($right['created_at'] ?? ''), (string) ($left['created_at'] ?? '')));
        return array_slice($items, 0, $limit);
    }

    private function occurrenceWasReviewed(string $createdAt, ?string $acknowledgedAt): bool
    {
        if ($createdAt === '' || $acknowledgedAt === null || trim($acknowledgedAt) === '') {
            return false;
        }
        $created = strtotime($createdAt);
        $acknowledged = strtotime($acknowledgedAt);
        return $created !== false && $acknowledged !== false && $created <= $acknowledged;
    }

    private function friendlyOccurrenceTitle(string $source, string $event): string
    {
        $event = strtolower(trim($event));
        if ($source === 'ai') {
            return match (true) {
                str_contains($event, 'credential'), str_contains($event, 'auth') => 'Credencial da IA recusada',
                str_contains($event, 'quota'), str_contains($event, 'limit'), str_contains($event, '429') => 'Limite da IA atingido',
                str_contains($event, 'timeout') => 'A IA demorou para responder',
                str_contains($event, 'send') => 'Falha ao enviar a resposta',
                default => 'Falha no assistente de IA',
            };
        }
        return match (true) {
            str_contains($event, 'calendar'), str_contains($event, 'agenda') => 'Falha na integração da agenda',
            str_contains($event, 'callback') => 'Callback da integração falhou',
            str_contains($event, 'webhook') => 'Webhook da integração falhou',
            str_contains($event, 'timeout') => 'Integração sem resposta',
            default => 'Falha em integração externa',
        };
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
                    'seen_at' => $checkedAt,
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

    private function tableExists(string $table): bool
    {
        try {
            $statement = $this->pdo->prepare(
                'SELECT 1 FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_name = :table LIMIT 1'
            );
            $statement->execute(['table' => $table]);
            return (bool) $statement->fetchColumn();
        } catch (Throwable) {
            return false;
        }
    }

    private function hasColumn(string $table, string $column): bool
    {
        try {
            $statement = $this->pdo->prepare(
                'SELECT 1 FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = :table AND column_name = :column LIMIT 1'
            );
            $statement->execute(['table' => $table, 'column' => $column]);
            return (bool) $statement->fetchColumn();
        } catch (Throwable) {
            return false;
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
