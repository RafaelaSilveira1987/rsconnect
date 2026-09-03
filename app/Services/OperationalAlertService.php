<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Clock;
use App\Core\Database;
use App\Core\Env;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;
use Throwable;

final class OperationalAlertService
{
    private const DEFAULTS = [
        'critical_enabled' => 1,
        'warning_enabled' => 1,
        'evolution_enabled' => 1,
        'ai_enabled' => 1,
        'n8n_enabled' => 1,
        'webhooks_enabled' => 1,
        'backup_enabled' => 1,
        'disk_enabled' => 1,
        'queue_enabled' => 1,
        'routines_enabled' => 1,
        'platform_enabled' => 1,
        'whatsapp_enabled' => 0,
        'email_enabled' => 0,
        'whatsapp_recipient' => '',
        'email_recipient' => '',
        'reminder_hours' => 3,
    ];

    public function dashboard(?int $userId = null): array
    {
        $userId = $userId ?: (int) Auth::id();
        return [
            'preferences' => $this->preferences($userId),
            'notifications' => $this->notifications($userId),
            'unread' => $this->unreadCount($userId),
            'deliveries' => $this->deliveries($userId),
            'incidents' => $this->activeIncidents(),
            'channels' => $this->channelStatus(),
            'monitor_runs' => $this->monitorRuns(),
        ];
    }

    public function preferences(int $userId): array
    {
        if ($userId < 1) {
            return self::DEFAULTS;
        }

        try {
            $statement = Database::connection()->prepare('SELECT * FROM operational_alert_preferences WHERE user_id = :id LIMIT 1');
            $statement->execute(['id' => $userId]);
            $row = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
            return array_merge(self::DEFAULTS, $row);
        } catch (Throwable) {
            return self::DEFAULTS;
        }
    }

    /** @param array<string,mixed> $data */
    public function savePreferences(int $userId, array $data): void
    {
        if ($userId < 1) {
            throw new RuntimeException('Usuário administrativo inválido.');
        }

        $email = trim((string) ($data['email_recipient'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('E-mail de alerta inválido.');
        }

        $phone = preg_replace('/\D+/', '', (string) ($data['whatsapp_recipient'] ?? '')) ?: '';
        if ($phone !== '' && strlen($phone) < 10) {
            throw new RuntimeException('WhatsApp de alerta inválido. Informe DDI, DDD e número.');
        }

        $hours = max(1, min(72, (int) ($data['reminder_hours'] ?? 3)));
        $flags = [
            'critical_enabled', 'warning_enabled', 'evolution_enabled', 'ai_enabled',
            'n8n_enabled', 'webhooks_enabled', 'backup_enabled', 'disk_enabled',
            'queue_enabled', 'routines_enabled', 'platform_enabled',
            'whatsapp_enabled', 'email_enabled',
        ];
        $values = [];
        foreach ($flags as $flag) {
            $values[$flag] = !empty($data[$flag]) ? 1 : 0;
        }

        $columns = implode(',', $flags);
        $params = implode(',', array_map(static fn (string $flag): string => ':' . $flag, $flags));
        $updates = implode(',', array_map(static fn (string $flag): string => $flag . '=VALUES(' . $flag . ')', $flags));

        $sql = 'INSERT INTO operational_alert_preferences '
            . '(user_id,' . $columns . ',whatsapp_recipient,email_recipient,reminder_hours) '
            . 'VALUES (:user_id,' . $params . ',:phone,:email,:hours) '
            . 'ON DUPLICATE KEY UPDATE ' . $updates . ', '
            . 'whatsapp_recipient=VALUES(whatsapp_recipient), '
            . 'email_recipient=VALUES(email_recipient), '
            . 'reminder_hours=VALUES(reminder_hours)';

        Database::connection()->prepare($sql)->execute(array_merge([
            'user_id' => $userId,
            'phone' => $phone !== '' ? $phone : null,
            'email' => $email !== '' ? $email : null,
            'hours' => $hours,
        ], $values));
    }

    public function dispatchOpened(int $incidentId): void
    {
        $this->dispatch($incidentId, 'opened');
    }

    public function dispatchRecovered(int $incidentId): void
    {
        $this->dispatch($incidentId, 'recovered');
    }

    public function dispatchReminderIfDue(int $incidentId): void
    {
        $incident = $this->incident($incidentId);
        $accessIncident = str_starts_with((string) ($incident['event'] ?? ''), 'operations.alert.access.tenant.');

        foreach ($this->admins() as $admin) {
            $userId = (int) $admin['id'];
            $preferences = $this->preferences($userId);
            $hours = max(1, (int) ($preferences['reminder_hours'] ?? 3));
            if ($accessIncident) {
                // Bloqueio comercial deve ser lembrado, mas não a cada execução do monitor.
                $hours = max(24, $hours);
            }

            try {
                $statement = Database::connection()->prepare(
                    "SELECT MAX(created_at) FROM operational_alert_deliveries
                     WHERE incident_id = :incident AND user_id = :user
                       AND notification_kind IN ('opened','reminder')"
                );
                $statement->execute(['incident' => $incidentId, 'user' => $userId]);
                $last = trim((string) $statement->fetchColumn());
                if ($last === '') {
                    $this->dispatch($incidentId, 'opened', $userId);
                    continue;
                }
                if ((strtotime($last) ?: 0) > time() - ($hours * 3600)) {
                    continue;
                }
            } catch (Throwable) {
                // Compatibilidade: se não houver histórico, tenta entregar o lembrete.
            }

            $this->dispatch($incidentId, 'reminder', $userId);
        }
    }

    /**
     * Envia um resumo operacional periódico mesmo quando nenhum incidente mudou de estado.
     * O monitor pode rodar a cada 15 minutos; a deduplicação abaixo limita cada canal a
     * uma entrega por dia e somente após o horário configurado.
     *
     * @param array<string,mixed> $summary
     * @param array<string,mixed> $accessSummary
     * @return array{sent:int,skipped:int,errors:list<string>}
     */
    public function dispatchHealthDigest(array $summary, array $accessSummary = []): array
    {
        $result = ['sent' => 0, 'skipped' => 0, 'errors' => []];
        if (!filter_var(Env::get('OPERATIONS_HEALTH_DIGEST_ENABLED', true), FILTER_VALIDATE_BOOL)) {
            $result['skipped']++;
            return $result;
        }

        $timezone = Clock::appTimezone();
        $now = new DateTimeImmutable('now', new DateTimeZone($timezone));
        $configuredTime = trim((string) Env::get('OPERATIONS_HEALTH_DIGEST_TIME', '08:00'));
        if (preg_match('/^(?:[01]\\d|2[0-3]):[0-5]\\d$/', $configuredTime) !== 1) {
            $configuredTime = '08:00';
        }
        [$hour, $minute] = array_map('intval', explode(':', $configuredTime, 2));
        $notBefore = $now->setTime($hour, $minute, 0);
        if ($now < $notBefore) {
            $result['skipped']++;
            return $result;
        }

        $presentation = $this->healthDigestPresentation($summary, $accessSummary, $now);
        $relativeUrl = '/central-operacao?tab=status';
        $absoluteUrl = $this->absoluteUrl($relativeUrl);

        foreach ($this->admins() as $admin) {
            $userId = (int) ($admin['id'] ?? 0);
            if ($userId < 1) {
                continue;
            }
            $preferences = $this->preferences($userId);
            if (empty($preferences['routines_enabled'])) {
                $result['skipped']++;
                continue;
            }

            if (!empty($preferences['platform_enabled']) && !$this->healthDigestSentToday($userId, 'platform', $timezone)) {
                try {
                    Database::connection()->prepare(
                        'INSERT INTO admin_operational_notifications
                         (user_id, incident_id, notification_kind, severity, title, message, action_url)
                         VALUES (:user, NULL, "manual", :severity, :title, :message, :url)'
                    )->execute([
                        'user' => $userId,
                        'severity' => (string) $presentation['severity'],
                        'title' => (string) $presentation['title'],
                        'message' => (string) $presentation['message'],
                        'url' => $relativeUrl,
                    ]);
                    $this->markHealthDigestSent($userId, 'platform', (string) $presentation['state'], (string) $presentation['message']);
                    $result['sent']++;
                } catch (Throwable $exception) {
                    $result['errors'][] = 'Painel: ' . $exception->getMessage();
                }
            }

            if (!empty($preferences['whatsapp_enabled']) && !$this->healthDigestSentToday($userId, 'whatsapp', $timezone)) {
                $destination = trim((string) ($preferences['whatsapp_recipient'] ?? ''));
                $delivery = $this->sendWhatsapp(
                    $destination,
                    (string) $presentation['title'],
                    (string) $presentation['message'],
                    $absoluteUrl
                );
                if (!empty($delivery['ok'])) {
                    $this->markHealthDigestSent($userId, 'whatsapp', (string) $presentation['state'], (string) $presentation['message']);
                    $result['sent']++;
                } else {
                    $result['errors'][] = 'WhatsApp: ' . (string) ($delivery['message'] ?? 'Falha não identificada.');
                }
            }

            if (!empty($preferences['email_enabled']) && !$this->healthDigestSentToday($userId, 'email', $timezone)) {
                $destination = trim((string) ($preferences['email_recipient'] ?? ''));
                $delivery = $this->sendEmail(
                    $destination,
                    (string) $presentation['title'],
                    (string) $presentation['message'],
                    $absoluteUrl,
                    0,
                    'manual'
                );
                if (!empty($delivery['ok'])) {
                    $this->markHealthDigestSent($userId, 'email', (string) $presentation['state'], (string) $presentation['message']);
                    $result['sent']++;
                } else {
                    $result['errors'][] = 'E-mail: ' . (string) ($delivery['message'] ?? 'Falha não identificada.');
                }
            }
        }

        return $result;
    }

    public function acknowledgeIncident(int $incidentId, int $userId, string $note = ''): void
    {
        if ($incidentId < 1 || $userId < 1) {
            throw new RuntimeException('Incidente inválido.');
        }
        $note = mb_substr(trim($note), 0, 500);
        $statement = Database::connection()->prepare(
            'UPDATE system_incidents
             SET acknowledged_at = NOW(), acknowledged_by = :user, acknowledgement_note = :note
             WHERE id = :id AND resolved_at IS NULL'
        );
        $statement->execute([
            'user' => $userId,
            'note' => $note !== '' ? $note : null,
            'id' => $incidentId,
        ]);
        if ($statement->rowCount() < 1) {
            throw new RuntimeException('O incidente não está aberto ou não foi encontrado.');
        }
    }

    /** @return array<string,int|bool> */
    public function resolveIncident(int $incidentId, bool $releaseQueue = false): array
    {
        return (new OperationsService())->resolveIncident($incidentId, $releaseQueue);
    }

    /** @return array<string,array<string,mixed>> */
    public function testConfiguredChannels(int $userId): array
    {
        $preferences = $this->preferences($userId);
        $title = 'Teste de avisos — RS Connect';
        $message = "Este é um teste dos avisos da RS Connect.\n\nO que aconteceu:\nOs canais habilitados estão sendo verificados.\n\nO que fazer agora:\nConfirme se este aviso apareceu na RS Connect e no WhatsApp.\n\nSituação: Teste dos avisos";
        $url = $this->absoluteUrl('/operacao-alertas');
        $result = [];

        if (!empty($preferences['platform_enabled'])) {
            try {
                Database::connection()->prepare(
                    'INSERT INTO admin_operational_notifications
                     (user_id, incident_id, notification_kind, severity, title, message, action_url)
                     VALUES (:user, NULL, "manual", "info", :title, :message, :url)'
                )->execute([
                    'user' => $userId,
                    'title' => $title,
                    'message' => $message,
                    'url' => '/operacao-alertas',
                ]);
                $result['platform'] = ['ok' => true, 'message' => 'Alerta interno criado.'];
            } catch (Throwable $exception) {
                $result['platform'] = ['ok' => false, 'message' => $exception->getMessage()];
            }
        }

        if (!empty($preferences['whatsapp_enabled'])) {
            $result['whatsapp'] = $this->sendWhatsapp(
                trim((string) ($preferences['whatsapp_recipient'] ?? '')),
                $title,
                $message,
                $url
            );
        }

        if (!empty($preferences['email_enabled'])) {
            $result['email'] = $this->sendEmail(
                trim((string) ($preferences['email_recipient'] ?? '')),
                $title,
                $message,
                $url,
                0,
                'manual'
            );
        }

        if ($result === []) {
            $result['none'] = ['ok' => false, 'message' => 'Nenhum canal está habilitado.'];
        }
        return $result;
    }

    /** @return array{ok:bool,configured:bool,message:string,provider_message_id?:string} */
    public function sendExternalWhatsapp(string $destination, string $title, string $message, string $url = ''): array
    {
        return $this->sendWhatsapp($destination, $title, $message, $url);
    }

    /** @return array{ok:bool,configured:bool,message:string,provider_message_id?:string} */
    public function sendExternalEmail(
        string $destination,
        string $title,
        string $message,
        string $url = '',
        int $incidentId = 0,
        string $kind = 'client_communication'
    ): array {
        return $this->sendEmail($destination, $title, $message, $url, $incidentId, $kind);
    }

    /** @return array<string,array<string,mixed>> */
    public function externalChannelStatus(): array
    {
        return $this->channelStatus();
    }

    private function dispatch(int $incidentId, string $kind, ?int $onlyUser = null): void
    {
        $incident = $this->incident($incidentId);
        if (!$incident) {
            return;
        }

        foreach ($this->admins() as $admin) {
            $userId = (int) $admin['id'];
            if ($onlyUser !== null && $userId !== $onlyUser) {
                continue;
            }

            $preferences = $this->preferences($userId);
            if (!$this->enabled($incident, $preferences, $kind)) {
                continue;
            }

            $diagnostic = $this->diagnosticKey((string) $incident['event']);
            $relativeUrl = (new OperationalPlaybookService())->centralUrl($diagnostic, (int) ($incident['tenant_id'] ?? 0));
            $absoluteUrl = $this->absoluteUrl($relativeUrl);
            $presentation = OperationalLanguageService::incident($incident, false, $kind);
            $title = match ($kind) {
                'recovered' => 'Tudo normal novamente — ' . (string) $presentation['label'],
                'reminder' => 'Ainda precisa de atenção — ' . (string) $presentation['title'],
                default => (string) $presentation['title'],
            };
            $message = OperationalLanguageService::alertMessage(
                $presentation,
                trim((string) ($incident['tenant_name'] ?? '')),
                $kind
            );
            $severity = $kind === 'recovered'
                ? 'success'
                : (in_array((string) $incident['severity'], ['critical', 'error'], true) ? 'danger' : 'warning');
            $deliveryKey = $this->deliveryKey($kind);

            if (!empty($preferences['platform_enabled'])) {
                $this->platform($userId, $incidentId, $kind, $severity, $title, $message, $relativeUrl);
                $this->saveDelivery($incidentId, $userId, $kind, 'platform', $deliveryKey, [
                    'status' => 'sent',
                    'destination' => 'RS Connect',
                    'sent_at' => date('Y-m-d H:i:s'),
                ]);
            }

            if (!empty($preferences['whatsapp_enabled'])) {
                $destination = trim((string) ($preferences['whatsapp_recipient'] ?? ''));
                $delivery = $this->sendWhatsapp($destination, $title, $message, $absoluteUrl);
                $this->saveDelivery($incidentId, $userId, $kind, 'whatsapp', $deliveryKey, [
                    'status' => $delivery['ok'] ? 'sent' : ($delivery['configured'] ? 'error' : 'pending_configuration'),
                    'destination' => $destination !== '' ? $destination : null,
                    'provider_message_id' => $delivery['provider_message_id'] ?? null,
                    'error_message' => $delivery['ok'] ? null : ($delivery['message'] ?? 'Falha não identificada.'),
                    'sent_at' => $delivery['ok'] ? date('Y-m-d H:i:s') : null,
                ]);
            }

            if (!empty($preferences['email_enabled'])) {
                $destination = trim((string) ($preferences['email_recipient'] ?? ''));
                $delivery = $this->sendEmail($destination, $title, $message, $absoluteUrl, $incidentId, $kind);
                $this->saveDelivery($incidentId, $userId, $kind, 'email', $deliveryKey, [
                    'status' => $delivery['ok'] ? 'sent' : ($delivery['configured'] ? 'error' : 'pending_configuration'),
                    'destination' => $destination !== '' ? $destination : null,
                    'provider_message_id' => $delivery['provider_message_id'] ?? null,
                    'error_message' => $delivery['ok'] ? null : ($delivery['message'] ?? 'Falha não identificada.'),
                    'sent_at' => $delivery['ok'] ? date('Y-m-d H:i:s') : null,
                ]);
            }
        }
    }

    /** @param array<string,mixed> $incident @param array<string,mixed> $preferences */
    private function enabled(array $incident, array $preferences, string $kind): bool
    {
        if ($kind === 'recovered') {
            return true;
        }

        $severity = (string) ($incident['severity'] ?? 'warning');
        if (in_array($severity, ['critical', 'error'], true) && empty($preferences['critical_enabled'])) {
            return false;
        }
        if ($severity === 'warning' && empty($preferences['warning_enabled'])) {
            return false;
        }

        $key = $this->diagnosticKey((string) ($incident['event'] ?? ''));
        $map = [
            'evolution' => 'evolution_enabled',
            'openai' => 'ai_enabled',
            'ai_reprocess' => 'ai_enabled',
            'after_hours_recovery' => 'ai_enabled',
            'n8n' => 'n8n_enabled',
            'webhooks' => 'webhooks_enabled',
            'backup' => 'backup_enabled',
            'disk' => 'disk_enabled',
            'message_queue' => 'queue_enabled',
            'billing_cron' => 'routines_enabled',
            'reporting' => 'routines_enabled',
            'access' => 'routines_enabled',
        ];
        return empty($map[$key]) || !empty($preferences[$map[$key]]);
    }

    private function diagnosticKey(string $event): string
    {
        $key = str_starts_with($event, 'operations.alert.')
            ? substr($event, strlen('operations.alert.'))
            : (str_starts_with($event, 'backup.') ? 'backup' : 'generic');
        if (str_starts_with($key, 'evolution.')) {
            return 'evolution';
        }
        if (str_starts_with($key, 'access.')) {
            return 'access';
        }
        return $key;
    }

    private function label(string $key): string
    {
        return (string) (OperationalLanguageService::service($key)['label'] ?? 'Funcionamento do sistema');
    }

    private function platform(
        int $userId,
        int $incidentId,
        string $kind,
        string $severity,
        string $title,
        string $message,
        string $url
    ): void {
        try {
            Database::connection()->prepare(
                'INSERT INTO admin_operational_notifications
                    (user_id, incident_id, notification_kind, severity, title, message, action_url)
                 VALUES (:user, :incident, :kind, :severity, :title, :message, :url)
                 ON DUPLICATE KEY UPDATE
                    severity = VALUES(severity), title = VALUES(title), message = VALUES(message),
                    action_url = VALUES(action_url), status = "unread", read_at = NULL, created_at = CURRENT_TIMESTAMP'
            )->execute([
                'user' => $userId,
                'incident' => $incidentId,
                'kind' => $kind,
                'severity' => $severity,
                'title' => $title,
                'message' => $message,
                'url' => $url,
            ]);
        } catch (Throwable) {
            // O canal interno não pode interromper o monitor.
        }
    }

    /** @param array<string,mixed> $payload */
    private function saveDelivery(
        int $incidentId,
        int $userId,
        string $kind,
        string $channel,
        string $deliveryKey,
        array $payload
    ): void {
        try {
            Database::connection()->prepare(
                'INSERT INTO operational_alert_deliveries
                    (incident_id, user_id, notification_kind, channel, delivery_key, status,
                     attempt_count, destination, provider_message_id, error_message, last_attempt_at, sent_at)
                 VALUES
                    (:incident, :user, :kind, :channel, :delivery_key, :status,
                     1, :destination, :provider_message_id, :error_message, NOW(), :sent_at)
                 ON DUPLICATE KEY UPDATE
                    status = VALUES(status), attempt_count = attempt_count + 1,
                    destination = VALUES(destination), provider_message_id = VALUES(provider_message_id),
                    error_message = VALUES(error_message), last_attempt_at = NOW(),
                    sent_at = COALESCE(VALUES(sent_at), sent_at)'
            )->execute([
                'incident' => $incidentId,
                'user' => $userId,
                'kind' => $kind,
                'channel' => $channel,
                'delivery_key' => $deliveryKey,
                'status' => (string) ($payload['status'] ?? 'error'),
                'destination' => $payload['destination'] ?? null,
                'provider_message_id' => $payload['provider_message_id'] ?? null,
                'error_message' => $payload['error_message'] ?? null,
                'sent_at' => $payload['sent_at'] ?? null,
            ]);
        } catch (Throwable) {
            // Compatibilidade com instalações ainda sem a migration 073.
            try {
                Database::connection()->prepare(
                    'INSERT IGNORE INTO operational_alert_deliveries
                     (incident_id,user_id,notification_kind,channel,status,destination,error_message)
                     VALUES (:incident,:user,:kind,:channel,:status,:destination,:error_message)'
                )->execute([
                    'incident' => $incidentId,
                    'user' => $userId,
                    'kind' => $kind,
                    'channel' => $channel,
                    'status' => (string) ($payload['status'] ?? 'error'),
                    'destination' => $payload['destination'] ?? null,
                    'error_message' => $payload['error_message'] ?? null,
                ]);
            } catch (Throwable) {
            }
        }
    }

    /**
     * @param array<string,mixed> $summary
     * @param array<string,mixed> $accessSummary
     * @return array{title:string,message:string,severity:string,state:string}
     */
    private function healthDigestPresentation(array $summary, array $accessSummary, DateTimeImmutable $now): array
    {
        $state = strtolower(trim((string) ($summary['state'] ?? 'unknown')));
        $blockedTenants = is_array($accessSummary['blocked_tenants'] ?? null)
            ? $accessSummary['blocked_tenants']
            : [];
        $blockedAvailable = ($accessSummary['blocked_tenants_available'] ?? true) === true;
        $blockedCount = count($blockedTenants);
        $tenantCounts = is_array($accessSummary['tenant_counts'] ?? null)
            ? $accessSummary['tenant_counts']
            : [];
        $tenantCountsAvailable = ($tenantCounts['available'] ?? false) === true;

        $healthy = $state === 'operational'
            && $tenantCountsAvailable
            && $blockedAvailable
            && $blockedCount === 0;
        $title = $healthy
            ? '✅ RS Connect — tudo normal'
            : '⚠️ RS Connect — atenção necessária';
        $severity = $healthy ? 'success' : 'warning';

        $servicesTotal = max(0, (int) ($summary['services_total'] ?? 0));
        $available = max(0, (int) ($summary['available'] ?? 0));
        $attention = max(0, (int) ($summary['attention'] ?? 0));
        $critical = max(0, (int) ($summary['critical'] ?? 0));
        $externalBlocked = max(0, (int) ($summary['blocked'] ?? 0));
        $affected = max(0, (int) ($summary['affected_companies'] ?? 0));

        $lines = [
            'Resumo automático de ' . $now->format('d/m/Y H:i') . '.',
            '',
            'Sistema: ' . (trim((string) ($summary['label'] ?? '')) ?: ($healthy ? 'Operando normalmente' : 'Revisão necessária')) . '.',
            'Verificações operacionais: ' . $available . '/' . $servicesTotal . ' aprovadas.',
            'Pontos de atenção: ' . $attention . ' · críticos: ' . $critical . ' · bloqueios externos: ' . $externalBlocked . '.',
        ];
        if ($affected > 0) {
            $lines[] = 'Empresas com impacto operacional: ' . $affected . '.';
        }

        $lines[] = '';
        $lines[] = 'Empresas:';
        if ($tenantCountsAvailable) {
            $lines[] = '• Cadastradas: ' . max(0, (int) ($tenantCounts['total'] ?? 0));
            $lines[] = '• Ativas: ' . max(0, (int) ($tenantCounts['active'] ?? 0));
            $lines[] = '• Inativas / não ativas: ' . max(0, (int) ($tenantCounts['non_active'] ?? 0));
        } else {
            $lines[] = '• Contagem cadastral: indisponível nesta verificação.';
        }
        $lines[] = $blockedAvailable
            ? '• Com bloqueio comercial: ' . $blockedCount
            : '• Com bloqueio comercial: indisponível nesta verificação.';

        if ($blockedAvailable && $blockedCount > 0) {
            $commercialTypes = [
                'validity' => 0,
                'overdue' => 0,
                'subscription_status' => 0,
            ];
            foreach ($blockedTenants as $tenant) {
                $code = trim((string) ($tenant['access_code'] ?? ''));
                if (in_array($code, ['trial_expired', 'subscription_period_expired'], true)) {
                    $commercialTypes['validity']++;
                } elseif ($code === 'invoice_overdue_grace_exceeded') {
                    $commercialTypes['overdue']++;
                } elseif (in_array($code, ['subscription_suspended', 'subscription_canceled'], true)) {
                    $commercialTypes['subscription_status']++;
                }
            }

            if ($commercialTypes['validity'] > 0) {
                $lines[] = '  ↳ Vigência/teste encerrado: ' . $commercialTypes['validity'];
            }
            if ($commercialTypes['overdue'] > 0) {
                $lines[] = '  ↳ Inadimplência além da tolerância: ' . $commercialTypes['overdue'];
            }
            if ($commercialTypes['subscription_status'] > 0) {
                $lines[] = '  ↳ Assinatura suspensa/cancelada: ' . $commercialTypes['subscription_status'];
            }

            $max = max(1, min(20, (int) Env::get('OPERATIONS_HEALTH_DIGEST_MAX_BLOCKED_COMPANIES', 8)));
            $shown = 0;
            foreach ($blockedTenants as $tenant) {
                if ($shown >= $max) {
                    break;
                }
                $name = trim((string) ($tenant['name'] ?? 'Empresa')) ?: 'Empresa';
                $reason = trim((string) ($tenant['access_title'] ?? 'acesso bloqueado')) ?: 'acesso bloqueado';
                $lines[] = '• ' . $name . ': ' . $reason . '.';
                $shown++;
            }
            if ($blockedCount > $shown) {
                $lines[] = '• +' . ($blockedCount - $shown) . ' empresa(s) com bloqueio comercial.';
            }
        }

        $lines[] = '';
        $lines[] = $healthy
            ? 'Nenhuma ação necessária neste momento.'
            : 'Abra a Central de Monitoramento para revisar os pontos acima.';

        return [
            'title' => $title,
            'message' => implode("\n", $lines),
            'severity' => $severity,
            'state' => $healthy ? 'operational' : 'attention',
        ];
    }

    private function absoluteUrl(string $path): string
    {
        if ($path === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }
        $base = rtrim(trim((string) Env::get('APP_URL', '')), '/');
        return $base !== '' ? $base . '/' . ltrim($path, '/') : $path;
    }
}
