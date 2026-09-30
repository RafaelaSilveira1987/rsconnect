<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Clock;
use App\Core\Crypto;
use App\Core\Database;
use App\Core\Env;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Comunicação operacional da Agenda com o próprio cliente/paciente.
 *
 * Mantém uma fila separada das notificações internas da equipe para que confirmação,
 * lembrete e pedido de presença usem sempre o contato vinculado ao compromisso.
 */
final class CalendarClientCommunicationService
{
    public const EVENT_CREATED = 'appointment.created';
    public const EVENT_CONFIRMED = 'appointment.confirmed';
    public const EVENT_CANCELLED = 'appointment.cancelled';
    public const EVENT_RESCHEDULED = 'appointment.rescheduled';
    public const EVENT_REMINDER = 'appointment.reminder';
    public const EVENT_PRESENCE_REQUEST = 'appointment.presence_request';

    /** @return array<string,mixed> */
    public function settings(int $tenantId): array
    {
        $defaults = [
            'ready' => false,
            'lookup_enabled' => 1,
            'lookup_outside_hours' => 0,
            'send_created_enabled' => 0,
            'send_confirmed_enabled' => 1,
            'send_cancelled_enabled' => 1,
            'send_rescheduled_enabled' => 1,
            'reminder_enabled' => 0,
            'reminder_minutes' => 120,
            'presence_request_enabled' => 0,
            'presence_request_minutes' => 1440,
            'created_message' => 'Seu agendamento foi registrado para {{data}} às {{hora}}. {{local}}',
            'confirmed_message' => 'Seu agendamento foi confirmado para {{data}} às {{hora}}. {{local}}',
            'cancelled_message' => 'Seu agendamento de {{data}} às {{hora}} foi cancelado. Se precisar, podemos verificar uma nova data.',
            'rescheduled_message' => 'Recebemos a solicitação de ajuste do seu agendamento. A equipe seguirá com você por aqui para definir uma nova data.',
            'reminder_message' => 'Lembrete: seu atendimento está marcado para {{data}} às {{hora}}. {{local}}',
            'presence_request_message' => 'Seu atendimento está marcado para {{data}} às {{hora}}. Você poderá comparecer? Responda sim para confirmar ou informe se precisa cancelar/remarcar.',
            'lookup_no_appointment_message' => 'Não encontrei um agendamento futuro ativo para este contato. Se quiser marcar um novo horário, me diga sua preferência.',
        ];

        if ($tenantId < 1 || !$this->tableExists('tenant_calendar_client_settings')) {
            // Compatibilidade com instalações ainda sem a migration 122.
            try {
                $legacy = (new PreSchedulingService())->settings($tenantId);
                $defaults['send_confirmed_enabled'] = !empty($legacy['send_approval_message']) ? 1 : 0;
                if (trim((string) ($legacy['approved_message'] ?? '')) !== '') {
                    $defaults['confirmed_message'] = (string) $legacy['approved_message'];
                }
                if (trim((string) ($legacy['reschedule_message'] ?? '')) !== '') {
                    $defaults['rescheduled_message'] = (string) $legacy['reschedule_message'];
                }
            } catch (Throwable) {
            }
            return $defaults;
        }

        try {
            $statement = Database::connection()->prepare(
                'SELECT * FROM tenant_calendar_client_settings WHERE tenant_id = :tenant_id LIMIT 1'
            );
            $statement->execute(['tenant_id' => $tenantId]);
            $row = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
            if ($row === []) {
                $this->ensureSettingsRow($tenantId);
                $statement->execute(['tenant_id' => $tenantId]);
                $row = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
            }
            return array_merge($defaults, $row, ['ready' => true]);
        } catch (Throwable) {
            return $defaults;
        }
    }

    /** @param array<string,mixed> $data */
    public function saveSettings(int $tenantId, array $data, ?int $userId): void
    {
        if ($tenantId < 1) {
            throw new RuntimeException('Empresa inválida.');
        }
        if (!$this->tableExists('tenant_calendar_client_settings')) {
            throw new RuntimeException('Execute a migration 122_calendar_client_communications.sql.');
        }

        $messages = [
            'created_message' => $this->messageOrDefault((string) ($data['client_created_message'] ?? ''), 'Seu agendamento foi registrado para {{data}} às {{hora}}. {{local}}'),
            'confirmed_message' => $this->messageOrDefault((string) ($data['client_confirmed_message'] ?? ''), 'Seu agendamento foi confirmado para {{data}} às {{hora}}. {{local}}'),
            'cancelled_message' => $this->messageOrDefault((string) ($data['client_cancelled_message'] ?? ''), 'Seu agendamento de {{data}} às {{hora}} foi cancelado. Se precisar, podemos verificar uma nova data.'),
            'rescheduled_message' => $this->messageOrDefault((string) ($data['client_rescheduled_message'] ?? ''), 'Recebemos a solicitação de ajuste do seu agendamento. A equipe seguirá com você por aqui para definir uma nova data.'),
            'reminder_message' => $this->messageOrDefault((string) ($data['client_reminder_message'] ?? ''), 'Lembrete: seu atendimento está marcado para {{data}} às {{hora}}. {{local}}'),
            'presence_request_message' => $this->messageOrDefault((string) ($data['client_presence_request_message'] ?? ''), 'Seu atendimento está marcado para {{data}} às {{hora}}. Você poderá comparecer? Responda sim para confirmar ou informe se precisa cancelar/remarcar.'),
            'lookup_no_appointment_message' => $this->messageOrDefault((string) ($data['client_lookup_no_appointment_message'] ?? ''), 'Não encontrei um agendamento futuro ativo para este contato. Se quiser marcar um novo horário, me diga sua preferência.'),
        ];

        $statement = Database::connection()->prepare(
            'INSERT INTO tenant_calendar_client_settings
                (tenant_id, lookup_enabled, lookup_outside_hours, send_created_enabled, send_confirmed_enabled,
                 send_cancelled_enabled, send_rescheduled_enabled, reminder_enabled, reminder_minutes,
                 presence_request_enabled, presence_request_minutes, created_message, confirmed_message,
                 cancelled_message, rescheduled_message, reminder_message, presence_request_message,
                 lookup_no_appointment_message, updated_by_user_id)
             VALUES
                (:tenant_id, :lookup_enabled, :lookup_outside_hours, :send_created_enabled, :send_confirmed_enabled,
                 :send_cancelled_enabled, :send_rescheduled_enabled, :reminder_enabled, :reminder_minutes,
                 :presence_request_enabled, :presence_request_minutes, :created_message, :confirmed_message,
                 :cancelled_message, :rescheduled_message, :reminder_message, :presence_request_message,
                 :lookup_no_appointment_message, :updated_by_user_id)
             ON DUPLICATE KEY UPDATE
                lookup_enabled = VALUES(lookup_enabled),
                lookup_outside_hours = VALUES(lookup_outside_hours),
                send_created_enabled = VALUES(send_created_enabled),
                send_confirmed_enabled = VALUES(send_confirmed_enabled),
                send_cancelled_enabled = VALUES(send_cancelled_enabled),
                send_rescheduled_enabled = VALUES(send_rescheduled_enabled),
                reminder_enabled = VALUES(reminder_enabled),
                reminder_minutes = VALUES(reminder_minutes),
                presence_request_enabled = VALUES(presence_request_enabled),
                presence_request_minutes = VALUES(presence_request_minutes),
                created_message = VALUES(created_message),
                confirmed_message = VALUES(confirmed_message),
                cancelled_message = VALUES(cancelled_message),
                rescheduled_message = VALUES(rescheduled_message),
                reminder_message = VALUES(reminder_message),
                presence_request_message = VALUES(presence_request_message),
                lookup_no_appointment_message = VALUES(lookup_no_appointment_message),
                updated_by_user_id = VALUES(updated_by_user_id),
                updated_at = CURRENT_TIMESTAMP'
        );
        $reminderMinutes = $this->leadTimeMinutes(
            $data,
            'client_reminder',
            (int) ($data['client_reminder_minutes'] ?? 120),
            5,
            10080
        );
        $presenceRequestMinutes = $this->leadTimeMinutes(
            $data,
            'client_presence_request',
            (int) ($data['client_presence_request_minutes'] ?? 1440),
            15,
            20160
        );

        $statement->execute([
            'tenant_id' => $tenantId,
            'lookup_enabled' => !empty($data['client_lookup_enabled']) ? 1 : 0,
            'lookup_outside_hours' => !empty($data['client_lookup_outside_hours']) ? 1 : 0,
            'send_created_enabled' => !empty($data['client_send_created_enabled']) ? 1 : 0,
            'send_confirmed_enabled' => !empty($data['client_send_confirmed_enabled']) ? 1 : 0,
            'send_cancelled_enabled' => !empty($data['client_send_cancelled_enabled']) ? 1 : 0,
            'send_rescheduled_enabled' => !empty($data['client_send_rescheduled_enabled']) ? 1 : 0,
            'reminder_enabled' => !empty($data['client_reminder_enabled']) ? 1 : 0,
            'reminder_minutes' => $reminderMinutes,
            'presence_request_enabled' => !empty($data['client_presence_request_enabled']) ? 1 : 0,
            'presence_request_minutes' => $presenceRequestMinutes,
            'updated_by_user_id' => $userId && $userId > 0 ? $userId : null,
        ] + $messages);

        // Ao habilitar/alterar lembretes, aplica a nova política também aos compromissos
        // futuros que já estavam confirmados. Dedupe e cancelamento da fila evitam duplicidade.
        $this->rescheduleUpcomingConfirmedJobs($tenantId);
    }

    /** @return array<string,mixed> */
    public function handleAppointmentCreated(int $tenantId, int $appointmentId): array
    {
        $appointment = $this->appointmentForMessaging($tenantId, $appointmentId);
        if (!$appointment) {
            return ['attempted' => false, 'queued' => 0, 'reason' => 'appointment_not_found'];
        }

        $settings = $this->settings($tenantId);
        $queued = 0;
        if (!empty($settings['send_created_enabled'])) {
            $queued += $this->enqueue($appointment, self::EVENT_CREATED, Clock::nowUtc());
        }
        if ((string) ($appointment['status'] ?? '') === 'confirmed') {
            $result = $this->handleStatusChange($tenantId, $appointmentId, '', 'confirmed');
            $queued += (int) ($result['queued'] ?? 0);
        }
        if ($queued > 0) {
            $this->processDueJobs(20, $tenantId);
        }
        return ['attempted' => true, 'queued' => $queued, 'reason' => 'created'];
    }

    /** @return array<string,mixed> */
    public function handleStatusChange(int $tenantId, int $appointmentId, string $previousStatus, string $status): array
    {
        $appointment = $this->appointmentForMessaging($tenantId, $appointmentId);
        if (!$appointment) {
            return ['attempted' => false, 'queued' => 0, 'reason' => 'appointment_not_found'];
        }
        $settings = $this->settings($tenantId);
        if (empty($settings['ready'])) {
            return ['attempted' => false, 'queued' => 0, 'reason' => 'migration_required'];
        }

        $queued = 0;
        if ($status === 'confirmed') {
            if ($previousStatus !== 'confirmed') {
                $this->resetClientConfirmationState($tenantId, $appointmentId);
                $appointment['client_confirmation_status'] = 'not_requested';
                $appointment['client_confirmation_requested_at'] = null;
                $appointment['client_confirmation_responded_at'] = null;
            }
            $this->cancelPendingFutureJobs($tenantId, $appointmentId, [self::EVENT_REMINDER, self::EVENT_PRESENCE_REQUEST]);
            if ($previousStatus !== 'confirmed' && !empty($settings['send_confirmed_enabled'])) {
                $queued += $this->enqueue($appointment, self::EVENT_CONFIRMED, Clock::nowUtc());
            }
            $queued += $this->scheduleConfirmedJobs($appointment, $settings);
        } elseif (in_array($status, ['cancelled', 'rejected'], true)) {
            $this->cancelPendingFutureJobs($tenantId, $appointmentId);
            if (!empty($settings['send_cancelled_enabled'])) {
                $queued += $this->enqueue($appointment, self::EVENT_CANCELLED, Clock::nowUtc(), $status);
            }
        } elseif ($status === 'rescheduled') {
            $this->cancelPendingFutureJobs($tenantId, $appointmentId);
            if (!empty($settings['send_rescheduled_enabled'])) {
                $queued += $this->enqueue($appointment, self::EVENT_RESCHEDULED, Clock::nowUtc());
            }
        } elseif (in_array($status, ['completed', 'no_show'], true)) {
            $this->cancelPendingFutureJobs($tenantId, $appointmentId);
        }

        $delivery = $queued > 0 ? $this->processDueJobs(30, $tenantId) : ['sent' => 0, 'failed' => 0, 'retry' => 0];
        return ['attempted' => true, 'queued' => $queued, 'delivery' => $delivery, 'reason' => 'status_change'];
    }

    /**
     * Agenda somente os disparos futuros de um compromisso já confirmado.
     * Útil quando a própria conversa acabou de enviar a confirmação transacional
     * e não deve gerar uma segunda mensagem imediata.
     *
     * @return array{attempted:bool,queued:int,reason:string}
     */
    public function scheduleConfirmedAutomation(int $tenantId, int $appointmentId): array
    {
        $appointment = $this->appointmentForMessaging($tenantId, $appointmentId);
        if (!$appointment) {
            return ['attempted' => false, 'queued' => 0, 'reason' => 'appointment_not_found'];
        }
        $settings = $this->settings($tenantId);
        if (empty($settings['ready'])) {
            return ['attempted' => false, 'queued' => 0, 'reason' => 'migration_required'];
        }
        if ((string) ($appointment['status'] ?? '') !== 'confirmed') {
            return ['attempted' => false, 'queued' => 0, 'reason' => 'appointment_not_confirmed'];
        }

        $this->resetClientConfirmationState($tenantId, $appointmentId);
        $appointment['client_confirmation_status'] = 'not_requested';
        $appointment['client_confirmation_requested_at'] = null;
        $appointment['client_confirmation_responded_at'] = null;
        $this->cancelPendingFutureJobs($tenantId, $appointmentId, [self::EVENT_REMINDER, self::EVENT_PRESENCE_REQUEST]);
        return [
            'attempted' => true,
            'queued' => $this->scheduleConfirmedJobs($appointment, $settings),
            'reason' => 'confirmed_future_automation',
        ];
    }

    /**
     * Recalcula apenas lembrete e pedido de presença para compromissos futuros já confirmados.
     * Usado ao salvar as configurações para que a política passe a valer sem exigir nova confirmação.
     *
     * @return array{appointments:int,queued:int}
     */
    public function rescheduleUpcomingConfirmedJobs(int $tenantId, int $limit = 500): array
    {
        if ($tenantId < 1 || !$this->tableExists('calendar_client_message_jobs')) {
            return ['appointments' => 0, 'queued' => 0];
        }
        $settings = $this->settings($tenantId);
        if (empty($settings['ready'])) {
            return ['appointments' => 0, 'queued' => 0];
        }

        try {
            Database::connection()->prepare(
                'UPDATE calendar_client_message_jobs
                 SET status = "skipped", last_error = "Recalculado após alteração da configuração.", locked_at = NULL
                 WHERE tenant_id = :tenant_id
                   AND event_key IN ("appointment.reminder", "appointment.presence_request")
                   AND status IN ("pending", "retry")'
            )->execute(['tenant_id' => $tenantId]);

            $statement = Database::connection()->prepare(
                'SELECT id FROM calendar_appointments
                 WHERE tenant_id = :tenant_id
                   AND status = "confirmed"
                   AND starts_at > NOW()
                 ORDER BY starts_at ASC
                 LIMIT :limit'
            );
            $statement->bindValue('tenant_id', $tenantId, PDO::PARAM_INT);
            $statement->bindValue('limit', max(1, min(2000, $limit)), PDO::PARAM_INT);
            $statement->execute();
            $ids = array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
            $queued = 0;
            foreach ($ids as $appointmentId) {
                $appointment = $this->appointmentForMessaging($tenantId, $appointmentId);
                if ($appointment) {
                    $queued += $this->scheduleConfirmedJobs($appointment, $settings);
                }
            }
            return ['appointments' => count($ids), 'queued' => $queued];
        } catch (Throwable) {
            return ['appointments' => 0, 'queued' => 0];
        }
    }

    /** @return array<string,int> */
    public function processDueJobs(int $limit = 50, ?int $tenantId = null): array
    {
        $summary = ['selected' => 0, 'sent' => 0, 'skipped' => 0, 'retry' => 0, 'failed' => 0];
        if (!$this->tableExists('calendar_client_message_jobs')) {
            return $summary;
        }

        $pdo = Database::connection();
        $pdo->prepare(
            'UPDATE calendar_client_message_jobs
             SET status = "retry", locked_at = NULL, next_attempt_at = UTC_TIMESTAMP()
             WHERE status = "processing" AND locked_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 10 MINUTE)'
        )->execute();

        $sql = 'SELECT id FROM calendar_client_message_jobs
                WHERE status IN ("pending", "retry")
                  AND scheduled_at <= UTC_TIMESTAMP()
                  AND next_attempt_at <= UTC_TIMESTAMP()';
        if ($tenantId !== null && $tenantId > 0) {
            $sql .= ' AND tenant_id = :tenant_id';
        }
        $sql .= ' ORDER BY next_attempt_at ASC, id ASC LIMIT :limit';
        $statement = $pdo->prepare($sql);
        if ($tenantId !== null && $tenantId > 0) {
            $statement->bindValue('tenant_id', $tenantId, PDO::PARAM_INT);
        }
        $statement->bindValue('limit', max(1, min(200, $limit)), PDO::PARAM_INT);
        $statement->execute();
        $ids = array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
        $summary['selected'] = count($ids);

        foreach ($ids as $id) {
            if (!$this->claimJob($id)) {
                continue;
            }
            $job = $this->job($id);
            if (!$job) {
                continue;
            }
            try {
                $appointment = $this->appointmentForMessaging((int) $job['tenant_id'], (int) $job['appointment_id']);
                if (!$appointment || $this->shouldSkipJob($job, $appointment)) {
                    $this->finishJob($id, 'skipped');
                    $summary['skipped']++;
                    continue;
                }

                $message = $this->renderEventMessage((string) $job['event_key'], $appointment, $this->settings((int) $job['tenant_id']));
                if ($message === '') {
                    $this->finishJob($id, 'skipped');
                    $summary['skipped']++;
                    continue;
                }

                $send = $this->sendAppointmentMessage($appointment, $message, 'calendar.client_' . str_replace('.', '_', (string) $job['event_key']), [
                    'calendar_client_message_job_id' => $id,
                    'event_key' => (string) $job['event_key'],
                ]);
                if (empty($send['ok'])) {
                    throw new RuntimeException((string) ($send['error'] ?? 'Falha no envio da mensagem de agenda.'));
                }

                if ((string) $job['event_key'] === self::EVENT_PRESENCE_REQUEST && $this->hasColumn('calendar_appointments', 'client_confirmation_status')) {
                    $pdo->prepare(
                        'UPDATE calendar_appointments
                         SET client_confirmation_status = "pending",
                             client_confirmation_requested_at = NOW(),
                             client_confirmation_responded_at = NULL
                         WHERE id = :id AND tenant_id = :tenant_id'
                    )->execute(['id' => (int) $appointment['id'], 'tenant_id' => (int) $appointment['tenant_id']]);
                }

                $this->finishJob($id, 'sent');
                $summary['sent']++;
            } catch (Throwable $exception) {
                $result = $this->failJob($job, $exception->getMessage());
                $summary[$result]++;
            }
        }

        return $summary;
    }

    /**
     * Envio direto usado quando o contato pergunta sobre um compromisso existente.
     * Não depende da fila porque a resposta pertence ao turno atual da conversa.
     *
     * @param array<string,mixed> $appointment
     * @param array<string,mixed> $metadata
     * @return array{ok:bool,error:?string,external_id:?string}
     */
    public function sendAppointmentMessage(array $appointment, string $message, string $eventType, array $metadata = []): array
    {
        $message = trim($message);
        $tenantId = (int) ($appointment['tenant_id'] ?? 0);
        $conversationId = (int) ($appointment['conversation_id'] ?? 0);
        $phoneSource = trim((string) (
            ($appointment['phone'] ?? '')
            ?: ($appointment['remote_jid'] ?? '')
            ?: ($appointment['conversation_remote_jid'] ?? '')
        ));
        $phone = preg_replace('/\D+/', '', $phoneSource) ?: '';
        if ($tenantId < 1 || $phone === '' || $message === '') {
            return ['ok' => false, 'error' => 'Contato ou mensagem inválida.', 'external_id' => null];
        }

        if ($conversationId > 0 && $this->recentOutgoingSameMessage($conversationId, $message)) {
            return ['ok' => true, 'error' => null, 'external_id' => null];
        }

        try {
            $instance = $this->instanceForMessaging($appointment, $tenantId);
            if (!$instance) {
                throw new RuntimeException('Nenhuma instância Evolution conectada foi encontrada para a empresa.');
            }

            $ownPhone = preg_replace('/\D+/', '', (string) ($instance['profile_phone'] ?? '')) ?: '';
            if ($ownPhone !== '' && $ownPhone === $phone) {
                throw new RuntimeException('O destinatário da mensagem não pode ser o próprio número conectado.');
            }

            $verifySsl = filter_var(Env::get('EVOLUTION_SSL_VERIFY', true), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
            $caBundle = trim((string) Env::get('EVOLUTION_CA_BUNDLE', ''));
            $service = new EvolutionService(
                (string) $instance['base_url'],
                Crypto::decrypt((string) $instance['api_key_encrypted']),
                (string) $instance['instance_name'],
                24,
                $verifySsl ?? true,
                $caBundle !== '' ? $caBundle : null
            );
            $response = $service->sendText($phone, $message);
            $externalId = $this->extractMessageId(is_array($response['body'] ?? null) ? $response['body'] : []);
            $this->recordOutgoing($appointment, $message, $externalId, is_array($response['body'] ?? null) ? $response['body'] : [], $eventType, $metadata);
            Audit::log($eventType, ['appointment_id' => (int) ($appointment['id'] ?? 0)] + $metadata, $tenantId);
            return ['ok' => true, 'error' => null, 'external_id' => $externalId];
        } catch (Throwable $exception) {
            Audit::log($eventType . '.failed', [
                'appointment_id' => (int) ($appointment['id'] ?? 0),
                'error' => $exception->getMessage(),
            ] + $metadata, $tenantId);
            return ['ok' => false, 'error' => $exception->getMessage(), 'external_id' => null];
        }
    }

    /** @return array<string,mixed>|null */
    public function appointmentForMessaging(int $tenantId, int $appointmentId): ?array
    {
        if ($tenantId < 1 || $appointmentId < 1) {
            return null;
        }
        $statement = Database::connection()->prepare(
            'SELECT a.*, ct.name AS contact_name, ct.phone, ct.remote_jid,
                    ct.evolution_instance_id AS contact_instance_id,
                    c.evolution_instance_id AS conversation_instance_id,
                    c.remote_jid AS conversation_remote_jid,
                    COALESCE(a.contact_id, c.contact_id) AS resolved_contact_id,
                    u.name AS owner_name
             FROM calendar_appointments a
             LEFT JOIN conversations c ON c.id = a.conversation_id AND c.tenant_id = a.tenant_id
             LEFT JOIN contacts ct ON ct.id = COALESCE(a.contact_id, c.contact_id) AND ct.tenant_id = a.tenant_id
             LEFT JOIN users u ON u.id = a.owner_user_id AND u.tenant_id = a.tenant_id
             WHERE a.id = :id AND a.tenant_id = :tenant_id
             LIMIT 1'
        );
        $statement->execute(['id' => $appointmentId, 'tenant_id' => $tenantId]);
        $appointment = $statement->fetch(PDO::FETCH_ASSOC);
        return $appointment ?: null;
    }

    /** @param array<string,mixed> $appointment */
    public function renderAppointmentTemplate(string $template, array $appointment): string
    {
        $rendered = (new PreSchedulingService())->renderMessage($template, $appointment);
        $replacements = [
            '{{profissional}}' => trim((string) ($appointment['owner_name'] ?? '')),
            '{{status_cliente}}' => trim((string) ($appointment['client_confirmation_status'] ?? '')),
        ];
        $rendered = strtr($rendered, $replacements);
        return trim(preg_replace('/[ \t]+\n/u', "\n", $rendered) ?? $rendered);
    }

    private function resetClientConfirmationState(int $tenantId, int $appointmentId): void
    {
        if (!$this->hasColumn('calendar_appointments', 'client_confirmation_status')) {
            return;
        }
        try {
            Database::connection()->prepare(
                'UPDATE calendar_appointments
                 SET client_confirmation_status = "not_requested",
                     client_confirmation_requested_at = NULL,
                     client_confirmation_responded_at = NULL
                 WHERE id = :id AND tenant_id = :tenant_id'
            )->execute(['id' => $appointmentId, 'tenant_id' => $tenantId]);
        } catch (Throwable) {
        }
    }

    private function ensureSettingsRow(int $tenantId): void
    {
        Database::connection()->prepare(
            'INSERT IGNORE INTO tenant_calendar_client_settings (tenant_id) VALUES (:tenant_id)'
        )->execute(['tenant_id' => $tenantId]);
    }

    private function scheduleConfirmedJobs(array $appointment, array $settings): int
    {
        $startsAt = trim((string) ($appointment['starts_at'] ?? ''));
        if ($startsAt === '') {
            return 0;
        }
        $timezone = Clock::safeTimezone((string) ($appointment['timezone'] ?? Clock::appTimezone()));
        try {
            // calendar_appointments.starts_at é tratado pelo módulo de agenda como horário local.
            $startLocal = new DateTimeImmutable($startsAt, new DateTimeZone($timezone));
            $startUtc = $startLocal->setTimezone(new DateTimeZone('UTC'));
        } catch (Throwable) {
            return 0;
        }

        $queued = 0;
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $clientConfirmationStatus = trim((string) ($appointment['client_confirmation_status'] ?? 'not_requested'));
        $presenceEligible = !empty($settings['presence_request_enabled'])
            && in_array($clientConfirmationStatus, ['', 'not_requested'], true);
        $reminderMinutes = max(5, (int) ($settings['reminder_minutes'] ?? 120));
        $presenceMinutes = max(15, (int) ($settings['presence_request_minutes'] ?? 1440));

        // Se as duas automações forem configuradas para o mesmo instante, o pedido
        // de presença já funciona como lembrete e tem prioridade para evitar duas
        // mensagens automáticas consecutivas para o mesmo compromisso.
        $presenceSupersedesReminder = $presenceEligible
            && !empty($settings['reminder_enabled'])
            && $reminderMinutes === $presenceMinutes;

        if (!empty($settings['reminder_enabled']) && !$presenceSupersedesReminder) {
            $scheduled = $startUtc->sub(new DateInterval('PT' . $reminderMinutes . 'M'));
            if ($scheduled > $now) {
                $queued += $this->enqueue(
                    $appointment,
                    self::EVENT_REMINDER,
                    $scheduled->format('Y-m-d H:i:s'),
                    'm' . $reminderMinutes
                );
            }
        }

        if ($presenceEligible) {
            $scheduled = $startUtc->sub(new DateInterval('PT' . $presenceMinutes . 'M'));
            if ($scheduled > $now) {
                $queued += $this->enqueue(
                    $appointment,
                    self::EVENT_PRESENCE_REQUEST,
                    $scheduled->format('Y-m-d H:i:s'),
                    'm' . $presenceMinutes
                );
            }
        }
        return $queued;
    }

    private function enqueue(array $appointment, string $eventKey, string $scheduledAt, string $suffix = ''): int
    {
        if (!$this->tableExists('calendar_client_message_jobs')) {
            return 0;
        }
        $tenantId = (int) ($appointment['tenant_id'] ?? 0);
        $appointmentId = (int) ($appointment['id'] ?? 0);
        if ($tenantId < 1 || $appointmentId < 1) {
            return 0;
        }
        $expectedStartsAt = trim((string) ($appointment['starts_at'] ?? ''));
        $dedupe = hash('sha256', implode('|', [
            $tenantId,
            $appointmentId,
            $eventKey,
            $expectedStartsAt,
            $suffix,
        ]));
        $statement = Database::connection()->prepare(
            'INSERT INTO calendar_client_message_jobs
                (tenant_id, appointment_id, event_key, expected_starts_at, scheduled_at, next_attempt_at, deduplication_key)
             VALUES
                (:tenant_id, :appointment_id, :event_key, :expected_starts_at, :scheduled_at, :next_attempt_at, :deduplication_key)
             ON DUPLICATE KEY UPDATE
                scheduled_at = IF(status IN ("skipped", "failed"), VALUES(scheduled_at), scheduled_at),
                next_attempt_at = IF(status IN ("skipped", "failed"), VALUES(next_attempt_at), next_attempt_at),
                expected_starts_at = IF(status IN ("skipped", "failed"), VALUES(expected_starts_at), expected_starts_at),
                attempts = IF(status IN ("skipped", "failed"), 0, attempts),
                locked_at = IF(status IN ("skipped", "failed"), NULL, locked_at),
                failed_at = IF(status IN ("skipped", "failed"), NULL, failed_at),
                last_error = IF(status IN ("skipped", "failed"), NULL, last_error),
                status = IF(status IN ("skipped", "failed"), "pending", status)'
        );
        $statement->execute([
            'tenant_id' => $tenantId,
            'appointment_id' => $appointmentId,
            'event_key' => $eventKey,
            'expected_starts_at' => $expectedStartsAt !== '' ? $expectedStartsAt : null,
            'scheduled_at' => $scheduledAt,
            'next_attempt_at' => $scheduledAt,
            'deduplication_key' => $dedupe,
        ]);
        return $statement->rowCount() > 0 ? 1 : 0;
    }

    private function renderEventMessage(string $eventKey, array $appointment, array $settings): string
    {
        $template = match ($eventKey) {
            self::EVENT_CREATED => (string) ($settings['created_message'] ?? ''),
            self::EVENT_CONFIRMED => (string) ($settings['confirmed_message'] ?? ''),
            self::EVENT_CANCELLED => (string) ($settings['cancelled_message'] ?? ''),
            self::EVENT_RESCHEDULED => (string) ($settings['rescheduled_message'] ?? ''),
            self::EVENT_REMINDER => (string) ($settings['reminder_message'] ?? ''),
            self::EVENT_PRESENCE_REQUEST => (string) ($settings['presence_request_message'] ?? ''),
            default => '',
        };
        return $template !== '' ? $this->renderAppointmentTemplate($template, $appointment) : '';
    }

    private function shouldSkipJob(array $job, array $appointment): bool
    {
        $event = (string) ($job['event_key'] ?? '');
        $status = (string) ($appointment['status'] ?? '');
        $expectedStart = trim((string) ($job['expected_starts_at'] ?? ''));
        $currentStart = trim((string) ($appointment['starts_at'] ?? ''));

        if ($expectedStart !== '' && $currentStart !== '' && $expectedStart !== $currentStart) {
            return true;
        }
        if (in_array($event, [self::EVENT_REMINDER, self::EVENT_PRESENCE_REQUEST, self::EVENT_CONFIRMED], true)) {
            if ($status !== 'confirmed') {
                return true;
            }
            $clientConfirmationStatus = trim((string) ($appointment['client_confirmation_status'] ?? 'not_requested'));
            if ($event === self::EVENT_PRESENCE_REQUEST
                && !in_array($clientConfirmationStatus, ['', 'not_requested'], true)) {
                return true;
            }
            if ($event === self::EVENT_REMINDER
                && in_array($clientConfirmationStatus, ['declined', 'cancel_requested', 'reschedule_requested'], true)) {
                return true;
            }
            return false;
        }
        if ($event === self::EVENT_CANCELLED) {
            return !in_array($status, ['cancelled', 'rejected'], true);
        }
        if ($event === self::EVENT_RESCHEDULED) {
            return $status !== 'rescheduled';
        }
        if ($event === self::EVENT_CREATED) {
            return in_array($status, ['cancelled', 'rejected'], true);
        }
        return false;
    }

    private function cancelPendingFutureJobs(int $tenantId, int $appointmentId, array $eventKeys = []): void
    {
        if (!$this->tableExists('calendar_client_message_jobs')) {
            return;
        }
        $sql = 'UPDATE calendar_client_message_jobs
                SET status = "skipped", last_error = "Substituído por uma alteração do agendamento."
                WHERE tenant_id = :tenant_id AND appointment_id = :appointment_id
                  AND status IN ("pending", "retry")';
        $params = ['tenant_id' => $tenantId, 'appointment_id' => $appointmentId];
        if ($eventKeys !== []) {
            $placeholders = [];
            foreach (array_values($eventKeys) as $index => $eventKey) {
                $key = 'event_' . $index;
                $placeholders[] = ':' . $key;
                $params[$key] = $eventKey;
            }
            $sql .= ' AND event_key IN (' . implode(',', $placeholders) . ')';
        }
        Database::connection()->prepare($sql)->execute($params);
    }

    private function claimJob(int $id): bool
    {
        $statement = Database::connection()->prepare(
            'UPDATE calendar_client_message_jobs
             SET status = "processing", locked_at = UTC_TIMESTAMP()
             WHERE id = :id AND status IN ("pending", "retry")'
        );
        $statement->execute(['id' => $id]);
        return $statement->rowCount() === 1;
    }

    private function job(int $id): ?array
    {
        $statement = Database::connection()->prepare('SELECT * FROM calendar_client_message_jobs WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function finishJob(int $id, string $status): void
    {
        Database::connection()->prepare(
            'UPDATE calendar_client_message_jobs
             SET status = :status,
                 attempts = attempts + 1,
                 locked_at = NULL,
                 sent_at = CASE WHEN :sent_status = "sent" THEN UTC_TIMESTAMP() ELSE sent_at END,
                 last_error = NULL
             WHERE id = :id'
        )->execute(['status' => $status, 'sent_status' => $status, 'id' => $id]);
    }

    private function failJob(array $job, string $error): string
    {
        $attempts = (int) ($job['attempts'] ?? 0) + 1;
        $maxAttempts = max(1, (int) ($job['max_attempts'] ?? 4));
        $id = (int) ($job['id'] ?? 0);
        $error = mb_substr(trim($error), 0, 1000);
        if ($attempts >= $maxAttempts) {
            Database::connection()->prepare(
                'UPDATE calendar_client_message_jobs
                 SET status = "failed", attempts = :attempts, failed_at = UTC_TIMESTAMP(), locked_at = NULL, last_error = :error
                 WHERE id = :id'
            )->execute(['attempts' => $attempts, 'error' => $error, 'id' => $id]);
            (new NotificationService())->createIfEnabled(
                (int) ($job['tenant_id'] ?? 0),
                'automation_errors',
                'Falha em mensagem automática de agendamento',
                'Uma comunicação automática com o cliente não foi entregue após ' . $attempts . ' tentativas.',
                'warning',
                '/calendar?section=availability&tab=settings',
                'automation',
                'calendar.client_message.failed',
                'appointment',
                (int) ($job['appointment_id'] ?? 0),
                ['error' => $error, 'event_key' => (string) ($job['event_key'] ?? '')],
                300
            );
            return 'failed';
        }

        $delays = [1 => 1, 2 => 5, 3 => 15, 4 => 60];
        $delay = $delays[$attempts] ?? 60;
        Database::connection()->prepare(
            'UPDATE calendar_client_message_jobs
             SET status = "retry", attempts = :attempts, locked_at = NULL,
                 next_attempt_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL ' . $delay . ' MINUTE),
                 last_error = :error
             WHERE id = :id'
        )->execute(['attempts' => $attempts, 'error' => $error, 'id' => $id]);
        return 'retry';
    }

    private function instanceForMessaging(array $appointment, int $tenantId): ?array
    {
        $instanceId = (int) (($appointment['conversation_instance_id'] ?? 0) ?: ($appointment['contact_instance_id'] ?? 0));
        if ($instanceId > 0) {
            $statement = Database::connection()->prepare(
                'SELECT * FROM evolution_instances
                 WHERE id = :id AND tenant_id = :tenant_id AND status = "connected" LIMIT 1'
            );
            $statement->execute(['id' => $instanceId, 'tenant_id' => $tenantId]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                return $row;
            }
        }

        $statement = Database::connection()->prepare(
            'SELECT * FROM evolution_instances
             WHERE tenant_id = :tenant_id AND status = "connected"
             ORDER BY is_default DESC, id DESC LIMIT 1'
        );
        $statement->execute(['tenant_id' => $tenantId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function recordOutgoing(array $appointment, string $message, ?string $externalId, array $rawPayload, string $eventType, array $metadata): void
    {
        $conversationId = (int) ($appointment['conversation_id'] ?? 0);
        if ($conversationId < 1) {
            return;
        }
        $tenantId = (int) ($appointment['tenant_id'] ?? 0);
        $sentAt = Clock::nowUtc();
        $pdo = Database::connection();
        $pdo->prepare(
            'INSERT INTO conversation_messages
                (tenant_id, conversation_id, evolution_message_id, direction, sender_type,
                 message_type, content, status, raw_payload_json, sent_at)
             VALUES
                (:tenant_id, :conversation_id, :external_id, "outgoing", "system",
                 "text", :content, "sent", :raw_payload, :sent_at)'
        )->execute([
            'tenant_id' => $tenantId,
            'conversation_id' => $conversationId,
            'external_id' => $externalId,
            'content' => $message,
            'raw_payload' => json_encode($rawPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'sent_at' => $sentAt,
        ]);
        $pdo->prepare(
            'UPDATE conversations
             SET last_message_at = :sent_at,
                 last_message_preview = :preview,
                 unread_count = 0,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND tenant_id = :tenant_id'
        )->execute([
            'sent_at' => $sentAt,
            'preview' => mb_substr($message, 0, 255),
            'id' => $conversationId,
            'tenant_id' => $tenantId,
        ]);
        try {
            $pdo->prepare(
                'INSERT INTO conversation_events (tenant_id, conversation_id, event_type, description, metadata_json)
                 VALUES (:tenant_id, :conversation_id, :event_type, :description, :metadata_json)'
            )->execute([
                'tenant_id' => $tenantId,
                'conversation_id' => $conversationId,
                'event_type' => mb_substr($eventType, 0, 120),
                'description' => 'Mensagem operacional da Agenda enviada automaticamente ao contato.',
                'metadata_json' => json_encode(['appointment_id' => (int) ($appointment['id'] ?? 0)] + $metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        } catch (Throwable) {
        }
    }

    private function recentOutgoingSameMessage(int $conversationId, string $message): bool
    {
        try {
            $statement = Database::connection()->prepare(
                'SELECT 1 FROM conversation_messages
                 WHERE conversation_id = :conversation_id
                   AND direction = "outgoing"
                   AND content = :content
                   AND sent_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 90 SECOND)
                 LIMIT 1'
            );
            $statement->execute(['conversation_id' => $conversationId, 'content' => $message]);
            return (bool) $statement->fetchColumn();
        } catch (Throwable) {
            return false;
        }
    }

    private function extractMessageId(array $body): ?string
    {
        $id = $body['key']['id'] ?? $body['messageId'] ?? $body['id'] ?? $body['data']['key']['id'] ?? null;
        return is_scalar($id) && trim((string) $id) !== '' ? trim((string) $id) : null;
    }

    /** @param array<string,mixed> $data */
    private function leadTimeMinutes(array $data, string $prefix, int $fallbackMinutes, int $minimum, int $maximum): int
    {
        $valueKey = $prefix . '_lead_value';
        $unitKey = $prefix . '_lead_unit';
        if (!array_key_exists($valueKey, $data)) {
            return max($minimum, min($maximum, $fallbackMinutes));
        }

        $value = max(1, (int) ($data[$valueKey] ?? 1));
        $unit = strtolower(trim((string) ($data[$unitKey] ?? 'minutes')));
        $factor = match ($unit) {
            'days' => 1440,
            'hours' => 60,
            default => 1,
        };

        return max($minimum, min($maximum, $value * $factor));
    }

    private function messageOrDefault(string $message, string $default): string
    {
        $message = trim($message);
        return mb_substr($message !== '' ? $message : $default, 0, 4000);
    }

    private function tableExists(string $table): bool
    {
        try {
            $statement = Database::connection()->prepare(
                'SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_name = :table_name'
            );
            $statement->execute(['table_name' => $table]);
            return (int) $statement->fetchColumn() > 0;
        } catch (Throwable) {
            return false;
        }
    }

    private function hasColumn(string $table, string $column): bool
    {
        try {
            $statement = Database::connection()->prepare(
                'SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = :table_name AND column_name = :column_name'
            );
            $statement->execute(['table_name' => $table, 'column_name' => $column]);
            return (int) $statement->fetchColumn() > 0;
        } catch (Throwable) {
            return false;
        }
    }
}
