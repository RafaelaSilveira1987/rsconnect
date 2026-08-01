<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Env;
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
        foreach ($this->admins() as $admin) {
            $userId = (int) $admin['id'];
            $preferences = $this->preferences($userId);
            $hours = max(1, (int) ($preferences['reminder_hours'] ?? 3));

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

    public function resolveIncident(int $incidentId): void
    {
        if ($incidentId < 1) {
            throw new RuntimeException('Incidente inválido.');
        }
        $statement = Database::connection()->prepare(
            'UPDATE system_incidents SET resolved_at = NOW(), last_seen_at = NOW()
             WHERE id = :id AND resolved_at IS NULL'
        );
        $statement->execute(['id' => $incidentId]);
        if ($statement->rowCount() < 1) {
            throw new RuntimeException('O incidente já foi resolvido ou não foi encontrado.');
        }
        $this->dispatchRecovered($incidentId);
    }

    /** @return array<string,array<string,mixed>> */
    public function testConfiguredChannels(int $userId): array
    {
        $preferences = $this->preferences($userId);
        $title = 'Teste de alertas — RS Connect';
        $message = 'Este é um teste manual dos canais operacionais. Nenhum incidente foi aberto.';
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
            $title = match ($kind) {
                'recovered' => 'Resolvido — ' . $this->label($diagnostic),
                'reminder' => 'Continua ativo — ' . $this->label($diagnostic),
                default => 'Alerta — ' . $this->label($diagnostic),
            };
            $message = $kind === 'recovered'
                ? 'O monitoramento confirmou a recuperação. ' . (string) $incident['message']
                : (string) $incident['message'];
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
        return $key;
    }

    private function label(string $key): string
    {
        return [
            'evolution' => 'WhatsApp / Evolution',
            'openai' => 'OpenAI / IA',
            'n8n' => 'n8n',
            'webhooks' => 'Webhooks',
            'backup' => 'Backup',
            'disk' => 'Espaço em disco',
            'message_queue' => 'Fila de mensagens',
            'billing_cron' => 'Cron de cobrança',
            'ai_reprocess' => 'Fila da IA',
            'after_hours_recovery' => 'Recuperação pós-horário',
            'reporting' => 'Relatórios',
            'database' => 'Banco de dados',
            'migrations' => 'Migrations',
            'calendar' => 'Google Agenda',
            'payments' => 'Pagamentos',
        ][$key] ?? 'Operação RS';
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

    /** @return array{ok:bool,configured:bool,message:string,provider_message_id?:string} */
    private function sendWhatsapp(string $destination, string $title, string $message, string $url): array
    {
        $baseUrl = trim((string) Env::get('OPERATIONS_ALERT_EVOLUTION_URL', Env::get('EVOLUTION_DEFAULT_URL', '')));
        $apiKey = trim((string) Env::get('OPERATIONS_ALERT_EVOLUTION_API_KEY', Env::get('EVOLUTION_DEFAULT_API_KEY', '')));
        $instance = trim((string) Env::get('OPERATIONS_ALERT_EVOLUTION_INSTANCE', ''));
        if ($destination === '' || $baseUrl === '' || $apiKey === '' || $instance === '') {
            return [
                'ok' => false,
                'configured' => false,
                'message' => 'Configure destino, URL, API Key e instância administrativa da Evolution.',
            ];
        }

        try {
            $service = new EvolutionService(
                $baseUrl,
                $apiKey,
                $instance,
                max(5, (int) Env::get('OPERATIONS_ALERT_TIMEOUT_SECONDS', 20)),
                filter_var(Env::get('EVOLUTION_SSL_VERIFY', true), FILTER_VALIDATE_BOOL),
                trim((string) Env::get('EVOLUTION_CA_BUNDLE', '')) ?: null,
            );
            $text = trim($title . "\n\n" . $message . ($url !== '' ? "\n\nAbrir: " . $url : ''));
            $response = $service->sendText($destination, $text);
            $body = is_array($response['body'] ?? null) ? $response['body'] : [];
            $providerId = trim((string) ($body['key']['id'] ?? $body['messageId'] ?? $body['id'] ?? ''));
            return [
                'ok' => true,
                'configured' => true,
                'message' => 'WhatsApp enviado.',
                'provider_message_id' => $providerId,
            ];
        } catch (Throwable $exception) {
            return ['ok' => false, 'configured' => true, 'message' => $exception->getMessage()];
        }
    }

    /** @return array{ok:bool,configured:bool,message:string,provider_message_id?:string} */
    private function sendEmail(
        string $destination,
        string $title,
        string $message,
        string $url,
        int $incidentId,
        string $kind
    ): array {
        if ($destination === '') {
            return ['ok' => false, 'configured' => false, 'message' => 'Destinatário de e-mail não configurado.'];
        }
        if (!filter_var($destination, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'configured' => true, 'message' => 'Destinatário de e-mail inválido.'];
        }
        $safeTitle = trim((string) preg_replace('/[\r\n]+/', ' ', $title));

        $webhookUrl = trim((string) Env::get('OPERATIONS_ALERT_EMAIL_WEBHOOK_URL', ''));
        if ($webhookUrl !== '') {
            if (!preg_match('#^https?://#i', $webhookUrl)) {
                return ['ok' => false, 'configured' => true, 'message' => 'URL do webhook de e-mail inválida.'];
            }
            try {
                $payload = [
                    'to' => $destination,
                    'subject' => $safeTitle,
                    'text' => trim($message . ($url !== '' ? "\n\nAbrir: " . $url : '')),
                    'incident_id' => $incidentId > 0 ? $incidentId : null,
                    'notification_kind' => $kind,
                    'source' => 'rs_connect_operations',
                ];
                $response = $this->postJson(
                    $webhookUrl,
                    $payload,
                    trim((string) Env::get('OPERATIONS_ALERT_EMAIL_WEBHOOK_TOKEN', ''))
                );
                return [
                    'ok' => true,
                    'configured' => true,
                    'message' => 'E-mail encaminhado ao transportador.',
                    'provider_message_id' => trim((string) ($response['id'] ?? $response['message_id'] ?? '')),
                ];
            } catch (Throwable $exception) {
                return ['ok' => false, 'configured' => true, 'message' => $exception->getMessage()];
            }
        }

        $nativeEnabled = filter_var(Env::get('OPERATIONS_ALERT_EMAIL_NATIVE', false), FILTER_VALIDATE_BOOL);
        $from = trim((string) preg_replace('/[\r\n]+/', '', (string) Env::get('OPERATIONS_ALERT_EMAIL_FROM', '')));
        if (!$nativeEnabled || $from === '' || !filter_var($from, FILTER_VALIDATE_EMAIL) || !function_exists('mail')) {
            return [
                'ok' => false,
                'configured' => false,
                'message' => 'Configure OPERATIONS_ALERT_EMAIL_WEBHOOK_URL ou o envio nativo de e-mail.',
            ];
        }

        $headers = [
            'From: ' . $from,
            'Content-Type: text/plain; charset=UTF-8',
            'X-Mailer: RS-Connect',
        ];
        $body = trim($message . ($url !== '' ? "\n\nAbrir: " . $url : ''));
        $ok = @mail($destination, $safeTitle, $body, implode("\r\n", $headers));
        return [
            'ok' => $ok,
            'configured' => true,
            'message' => $ok ? 'E-mail enviado.' : 'O transportador nativo recusou o envio.',
        ];
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function postJson(string $url, array $payload, string $token = ''): array
    {
        $curl = curl_init($url);
        if ($curl === false) {
            throw new RuntimeException('Não foi possível iniciar o transportador HTTP.');
        }
        $headers = ['Content-Type: application/json', 'Accept: application/json'];
        if ($token !== '') {
            $headers[] = 'Authorization: Bearer ' . $token;
            $headers[] = 'X-RS-Connect-Token: ' . $token;
        }
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => max(5, (int) Env::get('OPERATIONS_ALERT_TIMEOUT_SECONDS', 20)),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            CURLOPT_SSL_VERIFYPEER => filter_var(Env::get('OPERATIONS_ALERT_SSL_VERIFY', true), FILTER_VALIDATE_BOOL),
        ]);
        $raw = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        if ($raw === false) {
            throw new RuntimeException('Falha no transportador de e-mail: ' . $error);
        }
        $decoded = json_decode((string) $raw, true);
        $body = is_array($decoded) ? $decoded : ['raw' => (string) $raw];
        if ($status < 200 || $status >= 300) {
            $detail = $body['message'] ?? $body['error'] ?? $body['raw'] ?? 'Resposta recusada.';
            throw new RuntimeException('Transportador de e-mail HTTP ' . $status . ': ' . mb_substr((string) $detail, 0, 400));
        }
        return $body;
    }

    /** @return array<string,mixed>|null */
    private function incident(int $id): ?array
    {
        try {
            $statement = Database::connection()->prepare('SELECT * FROM system_incidents WHERE id = :id LIMIT 1');
            $statement->execute(['id' => $id]);
            return $statement->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @return list<array<string,mixed>> */
    private function admins(): array
    {
        try {
            return Database::connection()->query(
                "SELECT id,name,email FROM users WHERE role='super_admin' AND status='active'"
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return [];
        }
    }

    /** @return list<array<string,mixed>> */
    private function notifications(int $userId): array
    {
        try {
            $statement = Database::connection()->prepare(
                'SELECT * FROM admin_operational_notifications WHERE user_id = :user ORDER BY id DESC LIMIT 80'
            );
            $statement->execute(['user' => $userId]);
            return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return [];
        }
    }

    /** @return list<array<string,mixed>> */
    private function deliveries(int $userId): array
    {
        try {
            $statement = Database::connection()->prepare(
                'SELECT d.*, i.event, i.message AS incident_message
                 FROM operational_alert_deliveries d
                 LEFT JOIN system_incidents i ON i.id = d.incident_id
                 WHERE d.user_id = :user
                 ORDER BY d.id DESC LIMIT 80'
            );
            $statement->execute(['user' => $userId]);
            return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return [];
        }
    }

    /** @return list<array<string,mixed>> */
    private function activeIncidents(): array
    {
        try {
            return Database::connection()->query(
                'SELECT i.*, t.name AS tenant_name, u.name AS acknowledged_by_name
                 FROM system_incidents i
                 LEFT JOIN tenants t ON t.id = i.tenant_id
                 LEFT JOIN users u ON u.id = i.acknowledged_by
                 WHERE i.resolved_at IS NULL AND i.severity IN ("warning","error","critical")
                 ORDER BY FIELD(i.severity,"critical","error","warning"), i.last_seen_at DESC, i.id DESC
                 LIMIT 60'
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return [];
        }
    }

    /** @return list<array<string,mixed>> */
    private function monitorRuns(): array
    {
        try {
            return Database::connection()->query(
                'SELECT * FROM operational_monitor_runs ORDER BY id DESC LIMIT 10'
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return [];
        }
    }

    /** @return array<string,array<string,mixed>> */
    private function channelStatus(): array
    {
        $whatsappReady = trim((string) Env::get('OPERATIONS_ALERT_EVOLUTION_INSTANCE', '')) !== ''
            && trim((string) Env::get('OPERATIONS_ALERT_EVOLUTION_URL', Env::get('EVOLUTION_DEFAULT_URL', ''))) !== ''
            && trim((string) Env::get('OPERATIONS_ALERT_EVOLUTION_API_KEY', Env::get('EVOLUTION_DEFAULT_API_KEY', ''))) !== '';
        $emailWebhook = trim((string) Env::get('OPERATIONS_ALERT_EMAIL_WEBHOOK_URL', '')) !== '';
        $emailNative = filter_var(Env::get('OPERATIONS_ALERT_EMAIL_NATIVE', false), FILTER_VALIDATE_BOOL)
            && trim((string) Env::get('OPERATIONS_ALERT_EMAIL_FROM', '')) !== '';
        return [
            'platform' => ['ready' => true, 'label' => 'Disponível'],
            'whatsapp' => ['ready' => $whatsappReady, 'label' => $whatsappReady ? 'Configurado' : 'Configuração pendente'],
            'email' => ['ready' => $emailWebhook || $emailNative, 'label' => ($emailWebhook || $emailNative) ? 'Configurado' : 'Configuração pendente'],
        ];
    }

    public function unreadCount(int $userId): int
    {
        try {
            $statement = Database::connection()->prepare(
                "SELECT COUNT(*) FROM admin_operational_notifications WHERE user_id = :user AND status = 'unread'"
            );
            $statement->execute(['user' => $userId]);
            return (int) $statement->fetchColumn();
        } catch (Throwable) {
            return 0;
        }
    }

    public function markAllRead(int $userId): void
    {
        try {
            Database::connection()->prepare(
                "UPDATE admin_operational_notifications SET status = 'read', read_at = NOW()
                 WHERE user_id = :user AND status = 'unread'"
            )->execute(['user' => $userId]);
        } catch (Throwable) {
        }
    }

    private function deliveryKey(string $kind): string
    {
        if ($kind !== 'reminder') {
            return $kind;
        }
        return 'reminder-' . gmdate('Ymd-H');
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
