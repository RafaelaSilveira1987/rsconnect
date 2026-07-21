<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Database;
use App\Core\Env;
use App\Core\Router;
use PDO;
use Throwable;

/**
 * Mantém o ciclo de vida do compromisso confirmado no Google Agenda.
 *
 * O serviço é intencionalmente separado da busca de disponibilidade:
 * - CalendarAvailabilityService encontra e seleciona o horário;
 * - CalendarGoogleLifecycleService cria, atualiza ou remove o evento confirmado;
 * - o callback confirma a alteração antes de o RS Connect considerar a sincronização concluída.
 */
final class CalendarGoogleLifecycleService
{
    public function syncConfirmedAppointment(int $tenantId, int $appointmentId, bool $force = false): array
    {
        $appointment = $this->appointment($tenantId, $appointmentId);
        if (!$appointment) {
            return ['attempted' => false, 'ok' => false, 'message' => 'Agendamento não encontrado.'];
        }
        if (!$this->hasColumn('calendar_appointments', 'google_sync_key')) {
            return ['attempted' => true, 'ok' => false, 'message' => 'Execute a migration 041 para ativar o ciclo completo do Google Agenda.'];
        }

        $source = trim((string) ($appointment['availability_source'] ?? ''));
        if (!in_array($source, ['google_free_slots', 'internal_fallback'], true)) {
            return ['attempted' => false, 'ok' => true, 'message' => null];
        }

        $settings = (new CalendarAvailabilityService())->settings($tenantId);
        if (empty($settings['enabled']) || empty($settings['create_google_event_on_confirm'])) {
            return ['attempted' => false, 'ok' => true, 'message' => null];
        }
        $samePeriod = (string) ($appointment['google_synced_starts_at'] ?? '') === (string) ($appointment['starts_at'] ?? '')
            && (string) ($appointment['google_synced_ends_at'] ?? '') === (string) ($appointment['ends_at'] ?? '');
        if (!$force
            && $samePeriod
            && trim((string) ($appointment['google_event_id'] ?? '')) !== ''
            && in_array((string) ($appointment['google_event_state'] ?? ''), ['created', 'updated'], true)
            && (string) ($appointment['sync_status'] ?? '') === 'synced') {
            return ['attempted' => false, 'ok' => true, 'message' => 'Evento já sincronizado no Google Agenda.'];
        }

        $url = trim((string) ($settings['calendar_event_webhook_url'] ?? ''));
        if ($url === '') {
            return [
                'attempted' => true,
                'ok' => false,
                'message' => 'Configure a URL do fluxo “Ciclo do Google Agenda” para criar o evento confirmado.',
            ];
        }

        if (!$force && $this->hasRecentPendingRequest($tenantId, $appointmentId)) {
            return [
                'attempted' => true,
                'ok' => false,
                'pending' => true,
                'message' => 'A sincronização anterior ainda aguarda o callback do n8n. Aguarde alguns segundos e tente novamente.',
            ];
        }

        $operation = $this->shouldCreate($appointment) ? 'create' : 'update';
        if ($operation === 'update' && empty($settings['update_google_event_on_reschedule'])) {
            return ['attempted' => false, 'ok' => true, 'message' => null];
        }

        $localConflict = $this->localConflict($tenantId, $appointmentId, (string) $appointment['starts_at'], (string) $appointment['ends_at']);
        if ($localConflict !== null) {
            return [
                'attempted' => true,
                'ok' => false,
                'message' => 'O horário conflita com “' . $localConflict . '” no RS Connect. Escolha outro horário antes de confirmar.',
            ];
        }

        return $this->dispatchLifecycle($operation, $tenantId, $appointment, $settings);
    }

    public function cancelAppointment(int $tenantId, int $appointmentId, bool $force = false): array
    {
        $appointment = $this->appointment($tenantId, $appointmentId);
        if (!$appointment) {
            return ['attempted' => false, 'ok' => false, 'message' => 'Agendamento não encontrado.'];
        }
        if (!$this->hasColumn('calendar_appointments', 'google_sync_key')) {
            return ['attempted' => true, 'ok' => false, 'message' => 'Execute a migration 041 para ativar o ciclo completo do Google Agenda.'];
        }

        $source = trim((string) ($appointment['availability_source'] ?? ''));
        $eventId = trim((string) ($appointment['google_event_id'] ?? ''));
        $state = trim((string) ($appointment['google_event_state'] ?? ''));
        if (!in_array($source, ['google_free_slots', 'internal_fallback'], true) || $eventId === '' || $state === 'deleted') {
            return ['attempted' => false, 'ok' => true, 'message' => null];
        }

        $settings = (new CalendarAvailabilityService())->settings($tenantId);
        if (!$force && empty($settings['delete_google_event_on_cancel'])) {
            return ['attempted' => false, 'ok' => true, 'message' => null];
        }

        $url = trim((string) ($settings['calendar_event_webhook_url'] ?? ''));
        if ($url === '') {
            return ['attempted' => true, 'ok' => false, 'message' => 'Configure a URL do fluxo “Ciclo do Google Agenda” para remover o evento.'];
        }

        return $this->dispatchLifecycle('delete', $tenantId, $appointment, $settings);
    }

    public function handleCallback(array $payload, ?string $token = null): array
    {
        if (!$this->hasColumn('calendar_appointments', 'google_sync_key')) {
            return ['ok' => false, 'message' => 'Execute a migration 041 para ativar o callback do ciclo Google.'];
        }
        $requestId = (int) ($payload['request_id'] ?? $payload['availability_request_id'] ?? 0);
        $requestToken = trim((string) ($payload['request_token'] ?? $payload['callback_token'] ?? $token ?? ''));
        $request = $this->findRequest($requestId, $requestToken);
        if (!$request || (string) ($request['availability_mode'] ?? '') !== 'free_slots') {
            return ['ok' => false, 'message' => 'Solicitação do ciclo Google não encontrada ou token inválido.'];
        }

        $tenantId = (int) ($request['tenant_id'] ?? 0);
        $appointmentId = (int) ($payload['appointment_id'] ?? $request['appointment_id'] ?? 0);
        $appointment = $this->appointment($tenantId, $appointmentId);
        if (!$appointment) {
            return ['ok' => false, 'message' => 'Agendamento do callback não encontrado.'];
        }

        $action = trim((string) ($payload['action'] ?? $request['action_name'] ?? 'create'));
        $state = trim((string) ($payload['state'] ?? $payload['status'] ?? ''));
        if ($state === 'success') {
            $state = $action === 'delete' ? 'deleted' : ($action === 'update' ? 'updated' : 'created');
        }
        if ($state === 'exists') {
            $state = 'created';
        }
        if (!in_array($state, ['created', 'updated', 'deleted', 'failed'], true)) {
            return ['ok' => false, 'message' => 'Callback do ciclo Google sem estado reconhecido.'];
        }

        $googleEvent = isset($payload['google_event']) && is_array($payload['google_event']) ? $payload['google_event'] : [];
        $googleEventId = trim((string) (
            $payload['google_event_id']
            ?? $payload['external_id']
            ?? $googleEvent['id']
            ?? $appointment['google_event_id']
            ?? ''
        ));
        $calendarId = trim((string) ($payload['google_calendar_id'] ?? $payload['calendar_id'] ?? $appointment['google_calendar_id'] ?? 'primary')) ?: 'primary';
        $summary = mb_substr(trim((string) ($payload['summary'] ?? $payload['current_summary'] ?? $googleEvent['summary'] ?? $appointment['title'] ?? '')), 0, 255);
        $error = mb_substr(trim((string) ($payload['error'] ?? $payload['message'] ?? '')), 0, 700);

        if (in_array($state, ['created', 'updated'], true) && $googleEventId === '') {
            return ['ok' => false, 'message' => 'O callback confirmou a operação, mas não informou google_event_id.'];
        }

        $pdo = Database::connection();
        if ($state === 'failed') {
            $pdo->prepare(
                'UPDATE calendar_appointments
                 SET google_event_state = "error",
                     sync_status = "failed",
                     sync_error = :error,
                     google_last_operation = :operation,
                     google_last_sync_at = NOW(),
                     google_last_sync_error = :error_2,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id AND tenant_id = :tenant_id'
            )->execute([
                'error' => $error !== '' ? $error : 'O Google Agenda não concluiu a operação.',
                'operation' => $action,
                'error_2' => $error !== '' ? $error : 'O Google Agenda não concluiu a operação.',
                'id' => $appointmentId,
                'tenant_id' => $tenantId,
            ]);
            $this->finishRequest((int) $request['id'], false, $payload, $error !== '' ? $error : 'Falha no ciclo Google.');
            $this->logSync($tenantId, $appointmentId, (int) $request['id'], $action, 'error', $calendarId, $googleEventId, null, $payload, $error);
            return ['ok' => false, 'message' => $error !== '' ? $error : 'O Google Agenda não concluiu a operação.'];
        }

        if ($state === 'deleted') {
            $pdo->prepare(
                'UPDATE calendar_appointments
                 SET google_calendar_id = :calendar_id,
                     google_event_id = NULL,
                     google_event_state = "deleted",
                     google_sync_key = CONCAT("rsconnect-", tenant_id, "-", id, "-r", UNIX_TIMESTAMP()),
                     sync_status = "synced",
                     sync_error = NULL,
                     synced_at = NOW(),
                     google_last_operation = "delete",
                     google_last_sync_at = NOW(),
                     google_last_sync_error = NULL,
                     google_event_cancelled_at = NOW(),
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id AND tenant_id = :tenant_id'
            )->execute([
                'calendar_id' => $calendarId,
                'id' => $appointmentId,
                'tenant_id' => $tenantId,
            ]);
        } else {
            $createdField = $state === 'created' ? ', google_event_created_at = COALESCE(google_event_created_at, NOW())' : '';
            $pdo->prepare(
                'UPDATE calendar_appointments
                 SET google_calendar_id = :calendar_id,
                     google_event_id = :google_event_id,
                     google_event_state = :event_state,
                     google_event_summary = :summary,
                     sync_status = "synced",
                     sync_error = NULL,
                     synced_at = NOW(),
                     google_last_operation = :operation,
                     google_last_sync_at = NOW(),
                     google_last_sync_error = NULL,
                     google_synced_starts_at = starts_at,
                     google_synced_ends_at = ends_at,
                     google_event_updated_at = NOW()' . $createdField . ',
                     google_event_cancelled_at = NULL,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id AND tenant_id = :tenant_id'
            )->execute([
                'calendar_id' => $calendarId,
                'google_event_id' => $googleEventId,
                'event_state' => $state,
                'summary' => $summary !== '' ? $summary : null,
                'operation' => $action,
                'id' => $appointmentId,
                'tenant_id' => $tenantId,
            ]);
        }

        $this->finishRequest((int) $request['id'], true, $payload, null);
        $this->logSync($tenantId, $appointmentId, (int) $request['id'], 'lifecycle_callback', 'success', $calendarId, $googleEventId, null, $payload, null);
        Audit::log('calendar.google_free_slot.' . $state, [
            'appointment_id' => $appointmentId,
            'google_event_id' => $googleEventId,
            'action' => $action,
        ], $tenantId);

        return ['ok' => true, 'message' => 'Ciclo do Google Agenda atualizado.', 'state' => $state, 'google_event_id' => $googleEventId];
    }

    /**
     * Executa manutenção de uma empresa ou de todas as empresas habilitadas.
     * Mantém lotes pequenos para não transformar o cron em uma requisição longa.
     */
    public function runMaintenance(?int $tenantId = null, string $origin = 'cron'): array
    {
        if (!$this->hasColumn('calendar_appointments', 'google_sync_key')) {
            return ['ok' => false, 'status' => 'failed', 'message' => 'Execute a migration 041 antes de rodar a manutenção da agenda.'];
        }
        $runId = $this->startRun($tenantId, $origin);
        $result = [
            'expired_holds_found' => 0,
            'expired_holds_released' => 0,
            'syncs_retried' => 0,
            'google_events_created' => 0,
            'google_events_updated' => 0,
            'google_events_deleted' => 0,
            'stale_requests_closed' => 0,
            'errors' => [],
        ];

        try {
            $tenantIds = $this->maintenanceTenantIds($tenantId);
            foreach ($tenantIds as $currentTenantId) {
                $this->closeStaleRequests($currentTenantId, $result);
                $this->releaseExpiredMarkedHolds($currentTenantId, $result);
                $this->retryMissingGoogleEvents($currentTenantId, $result);
                $this->deleteCancelledGoogleEvents($currentTenantId, $result);
                $this->touchMaintenance($currentTenantId);
            }

            $status = $result['errors'] === [] ? 'success' : 'partial';
            $this->finishRun($runId, $status, $result, null);
            return ['ok' => $status !== 'failed', 'run_id' => $runId, 'status' => $status, 'result' => $result];
        } catch (Throwable $exception) {
            $result['errors'][] = $exception->getMessage();
            $this->finishRun($runId, 'failed', $result, $exception->getMessage());
            return ['ok' => false, 'run_id' => $runId, 'status' => 'failed', 'result' => $result, 'message' => $exception->getMessage()];
        }
    }

    public function maintenanceSummary(int $tenantId): array
    {
        $summary = [
            'enabled' => false,
            'expired_holds' => 0,
            'confirmed_without_event' => 0,
            'failed_syncs' => 0,
            'stale_requests' => 0,
            'last_run' => null,
        ];
        if ($tenantId < 1 || !$this->tableExists('tenant_calendar_availability_settings')) {
            return $summary;
        }

        $settings = (new CalendarAvailabilityService())->settings($tenantId);
        $summary['enabled'] = !empty($settings['maintenance_enabled']);
        try {
            $pdo = Database::connection();
            $statement = $pdo->prepare(
                'SELECT
                    COALESCE(SUM(availability_source = "google_marked_slots" AND google_event_state = "held" AND google_hold_expires_at IS NOT NULL AND google_hold_expires_at <= NOW()), 0) AS expired_holds,
                    COALESCE(SUM(availability_source IN ("google_free_slots", "internal_fallback") AND status IN ("scheduled", "confirmed") AND (google_event_id IS NULL OR google_event_id = "") AND starts_at >= NOW()), 0) AS confirmed_without_event,
                    COALESCE(SUM(sync_status = "failed" OR google_event_state = "error"), 0) AS failed_syncs
                 FROM calendar_appointments
                 WHERE tenant_id = :tenant_id'
            );
            $statement->execute(['tenant_id' => $tenantId]);
            $row = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
            $summary = array_merge($summary, [
                'expired_holds' => (int) ($row['expired_holds'] ?? 0),
                'confirmed_without_event' => (int) ($row['confirmed_without_event'] ?? 0),
                'failed_syncs' => (int) ($row['failed_syncs'] ?? 0),
            ]);

            if ($this->tableExists('calendar_availability_requests')) {
                $stale = $pdo->prepare(
                    'SELECT COUNT(*) FROM calendar_availability_requests
                     WHERE tenant_id = :tenant_id AND status IN ("pending", "sent")
                       AND COALESCE(sent_at, requested_at, created_at) < DATE_SUB(NOW(), INTERVAL 30 MINUTE)'
                );
                $stale->execute(['tenant_id' => $tenantId]);
                $summary['stale_requests'] = (int) $stale->fetchColumn();
            }

            if ($this->tableExists('calendar_maintenance_runs')) {
                $last = $pdo->prepare('SELECT * FROM calendar_maintenance_runs WHERE tenant_id = :tenant_id OR tenant_id IS NULL ORDER BY id DESC LIMIT 1');
                $last->execute(['tenant_id' => $tenantId]);
                $summary['last_run'] = $last->fetch(PDO::FETCH_ASSOC) ?: null;
            }
        } catch (Throwable) {
        }
        return $summary;
    }

    private function dispatchLifecycle(string $operation, int $tenantId, array $appointment, array $settings): array
    {
        $request = $this->createLifecycleRequest($tenantId, (int) $appointment['id'], $operation, $appointment, $settings);
        $syncKey = trim((string) ($appointment['google_sync_key'] ?? '')) ?: 'rsconnect-' . $tenantId . '-' . (int) $appointment['id'];
        $calendarId = trim((string) ($appointment['google_calendar_id'] ?? $settings['google_calendar_id'] ?? 'primary')) ?: 'primary';
        $payload = [
            'tenant_id' => $tenantId,
            'event' => 'calendar.free_slot.action',
            'action' => $operation,
            'availability_mode' => 'free_slots',
            'request_id' => (int) $request['id'],
            'request_token' => (string) $request['request_token'],
            'appointment_id' => (int) $appointment['id'],
            'idempotency_key' => $syncKey,
            'calendar_id' => $calendarId,
            'google_event_id' => trim((string) ($appointment['google_event_id'] ?? '')) ?: null,
            'title' => trim((string) ($appointment['title'] ?? 'Agendamento')) ?: 'Agendamento',
            'description' => trim((string) ($appointment['description'] ?? '')),
            'start' => (string) ($appointment['starts_at'] ?? ''),
            'end' => (string) ($appointment['ends_at'] ?? ''),
            'timezone' => trim((string) ($appointment['timezone'] ?? $settings['timezone'] ?? 'America/Sao_Paulo')) ?: 'America/Sao_Paulo',
            'location_type' => (string) ($appointment['location_type'] ?? 'online'),
            'location' => trim((string) ($appointment['location'] ?? '')),
            'meeting_url' => trim((string) ($appointment['meeting_url'] ?? '')),
            'customer' => [
                'name' => trim((string) ($appointment['contact_name'] ?? 'Cliente')) ?: 'Cliente',
                'phone' => trim((string) ($appointment['phone'] ?? '')),
                'email' => trim((string) ($appointment['email'] ?? '')),
            ],
            'rules' => [
                'revalidate_before_create' => true,
                'avoid_duplicates' => true,
                'idempotency_key' => $syncKey,
            ],
            'callback' => [
                'url' => Router::url('/webhooks/calendar/availability'),
                'token' => (string) $request['request_token'],
            ],
            'requested_at' => date('c'),
        ];

        Database::connection()->prepare(
            'UPDATE calendar_availability_requests SET requested_payload_json = :payload WHERE id = :id'
        )->execute([
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'id' => (int) $request['id'],
        ]);

        Database::connection()->prepare(
            'UPDATE calendar_appointments
             SET google_sync_key = :sync_key,
                 google_event_state = :state,
                 sync_status = "pending",
                 sync_error = NULL,
                 google_last_operation = :operation,
                 google_sync_attempts = google_sync_attempts + 1,
                 google_last_sync_at = NOW(),
                 google_last_sync_error = NULL,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND tenant_id = :tenant_id'
        )->execute([
            'sync_key' => $syncKey,
            'state' => $operation . '_requested',
            'operation' => $operation,
            'id' => (int) $appointment['id'],
            'tenant_id' => $tenantId,
        ]);

        $results = (new AutomationWebhookService())->dispatch(
            'calendar.free_slot.' . $operation,
            $payload,
            trim((string) ($settings['calendar_event_webhook_url'] ?? '')),
            $tenantId,
            trim((string) ($settings['secret_token'] ?? '')) ?: null
        );
        $sent = false;
        $errors = [];
        foreach ($results as $result) {
            if (!empty($result['ok'])) {
                $sent = true;
            } elseif (!empty($result['error'])) {
                $errors[] = (string) $result['error'];
            }
        }

        if (!$sent) {
            $error = mb_substr(implode(' | ', $errors) ?: 'O fluxo n8n não respondeu com sucesso.', 0, 700);
            $this->finishRequest((int) $request['id'], false, $results, $error);
            Database::connection()->prepare(
                'UPDATE calendar_appointments
                 SET google_event_state = "error", sync_status = "failed", sync_error = :error,
                     google_last_sync_error = :error_2, updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id AND tenant_id = :tenant_id'
            )->execute([
                'error' => $error,
                'error_2' => $error,
                'id' => (int) $appointment['id'],
                'tenant_id' => $tenantId,
            ]);
            $this->logSync($tenantId, (int) $appointment['id'], (int) $request['id'], $operation, 'error', $calendarId, (string) ($appointment['google_event_id'] ?? ''), $payload, $results, $error);
            return ['attempted' => true, 'ok' => false, 'message' => $error];
        }

        Database::connection()->prepare(
            'UPDATE calendar_availability_requests SET status = "sent", sent_at = NOW(), error_message = NULL WHERE id = :id'
        )->execute(['id' => (int) $request['id']]);
        $this->logSync($tenantId, (int) $appointment['id'], (int) $request['id'], $operation, 'success', $calendarId, (string) ($appointment['google_event_id'] ?? ''), $payload, $results, null);

        // O template oficial envia o callback antes de responder ao webhook.
        $updated = $this->appointment($tenantId, (int) $appointment['id']);
        $expected = $operation === 'delete' ? ['deleted'] : ($operation === 'update' ? ['updated', 'created'] : ['created', 'updated']);
        if (!$updated || !in_array((string) ($updated['google_event_state'] ?? ''), $expected, true)) {
            return [
                'attempted' => true,
                'ok' => false,
                'pending' => true,
                'message' => 'O n8n recebeu a ação, mas o callback ainda não confirmou a alteração no Google Agenda.',
            ];
        }

        return [
            'attempted' => true,
            'ok' => true,
            'operation' => $operation,
            'message' => match ($operation) {
                'create' => 'Evento criado no Google Agenda.',
                'update' => 'Evento atualizado no Google Agenda.',
                'delete' => 'Evento removido do Google Agenda.',
                default => 'Google Agenda sincronizado.',
            },
        ];
    }

    private function createLifecycleRequest(int $tenantId, int $appointmentId, string $operation, array $appointment, array $settings): array
    {
        $token = bin2hex(random_bytes(16));
        $pdo = Database::connection();
        $pdo->prepare(
            'INSERT INTO calendar_availability_requests
                (tenant_id, appointment_id, request_token, origin, availability_mode, action_name, status,
                 preferred_day_text, preferred_time_text, search_start_at, search_end_at, duration_minutes,
                 timezone, requested_at)
             VALUES
                (:tenant_id, :appointment_id, :request_token, "google_lifecycle", "free_slots", :action_name, "pending",
                 :preferred_day_text, :preferred_time_text, :search_start_at, :search_end_at, :duration_minutes,
                 :timezone, NOW())'
        )->execute([
            'tenant_id' => $tenantId,
            'appointment_id' => $appointmentId,
            'request_token' => $token,
            'action_name' => $operation,
            'preferred_day_text' => $appointment['preferred_day_text'] ?? null,
            'preferred_time_text' => $appointment['preferred_time_text'] ?? null,
            'search_start_at' => $appointment['starts_at'] ?? null,
            'search_end_at' => $appointment['ends_at'] ?? null,
            'duration_minutes' => max(1, (int) round((strtotime((string) $appointment['ends_at']) - strtotime((string) $appointment['starts_at'])) / 60)),
            'timezone' => (string) ($appointment['timezone'] ?? $settings['timezone'] ?? 'America/Sao_Paulo'),
        ]);
        return ['id' => (int) $pdo->lastInsertId(), 'request_token' => $token];
    }

    private function finishRequest(int $requestId, bool $ok, mixed $response, ?string $error): void
    {
        Database::connection()->prepare(
            'UPDATE calendar_availability_requests
             SET status = :status,
                 response_payload_json = :response_payload_json,
                 error_message = :error_message,
                 responded_at = NOW(),
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        )->execute([
            'status' => $ok ? 'received' : 'failed',
            'response_payload_json' => json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'error_message' => $error !== null ? mb_substr($error, 0, 700) : null,
            'id' => $requestId,
        ]);
    }

    private function hasRecentPendingRequest(int $tenantId, int $appointmentId): bool
    {
        $statement = Database::connection()->prepare(
            'SELECT COUNT(*) FROM calendar_availability_requests
             WHERE tenant_id = :tenant_id AND appointment_id = :appointment_id
               AND origin = "google_lifecycle" AND status IN ("pending", "sent")
               AND COALESCE(sent_at, requested_at, created_at) >= DATE_SUB(NOW(), INTERVAL 3 MINUTE)'
        );
        $statement->execute(['tenant_id' => $tenantId, 'appointment_id' => $appointmentId]);
        return (int) $statement->fetchColumn() > 0;
    }

    private function shouldCreate(array $appointment): bool
    {
        $eventId = trim((string) ($appointment['google_event_id'] ?? ''));
        $state = trim((string) ($appointment['google_event_state'] ?? ''));
        return $eventId === '' || in_array($state, ['', 'released', 'deleted', 'error'], true);
    }

    private function localConflict(int $tenantId, int $appointmentId, string $startsAt, string $endsAt): ?string
    {
        if ($startsAt === '' || $endsAt === '') {
            return 'período inválido';
        }
        $statement = Database::connection()->prepare(
            'SELECT title FROM calendar_appointments
             WHERE tenant_id = :tenant_id AND id <> :id
               AND status IN ("scheduled", "confirmed")
               AND starts_at < :ends_at AND ends_at > :starts_at
             ORDER BY starts_at ASC LIMIT 1'
        );
        $statement->execute([
            'tenant_id' => $tenantId,
            'id' => $appointmentId,
            'ends_at' => $endsAt,
            'starts_at' => $startsAt,
        ]);
        $title = $statement->fetchColumn();
        return $title !== false ? (string) $title : null;
    }

    private function releaseExpiredMarkedHolds(int $tenantId, array &$result): void
    {
        $statement = Database::connection()->prepare(
            'SELECT id FROM calendar_appointments
             WHERE tenant_id = :tenant_id
               AND availability_source = "google_marked_slots"
               AND google_event_state = "held"
               AND google_hold_expires_at IS NOT NULL
               AND google_hold_expires_at <= NOW()
             ORDER BY google_hold_expires_at ASC LIMIT 30'
        );
        $statement->execute(['tenant_id' => $tenantId]);
        $ids = array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
        $result['expired_holds_found'] += count($ids);
        $availability = new CalendarAvailabilityService();
        foreach ($ids as $appointmentId) {
            $release = $availability->releaseMarkedAppointment($tenantId, $appointmentId, true);
            if (!empty($release['ok'])) {
                $result['expired_holds_released']++;
                $this->logSync($tenantId, $appointmentId, null, 'maintenance_release', 'success', '', '', null, $release, null);
            } else {
                $result['errors'][] = 'Pré-reserva #' . $appointmentId . ': ' . (string) ($release['message'] ?? 'falha ao liberar');
            }
        }
    }

    private function retryMissingGoogleEvents(int $tenantId, array &$result): void
    {
        $settings = (new CalendarAvailabilityService())->settings($tenantId);
        if (empty($settings['create_google_event_on_confirm'])) {
            return;
        }
        $maxAttempts = max(1, min(10, (int) ($settings['max_sync_attempts'] ?? 3)));
        $statement = Database::connection()->prepare(
            'SELECT id FROM calendar_appointments
             WHERE tenant_id = :tenant_id
               AND availability_source IN ("google_free_slots", "internal_fallback")
               AND status IN ("scheduled", "confirmed")
               AND starts_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
               AND (google_event_id IS NULL OR google_event_id = "" OR google_event_state = "error")
               AND google_sync_attempts < :max_attempts
             ORDER BY starts_at ASC LIMIT 20'
        );
        $statement->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
        $statement->bindValue(':max_attempts', $maxAttempts, PDO::PARAM_INT);
        $statement->execute();
        foreach (array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN)) as $appointmentId) {
            $sync = $this->syncConfirmedAppointment($tenantId, $appointmentId, true);
            $result['syncs_retried']++;
            if (!empty($sync['ok'])) {
                $operation = (string) ($sync['operation'] ?? 'create');
                if ($operation === 'update') {
                    $result['google_events_updated']++;
                } else {
                    $result['google_events_created']++;
                }
            } else {
                $result['errors'][] = 'Sincronização #' . $appointmentId . ': ' . (string) ($sync['message'] ?? 'falha');
            }
        }
    }

    private function deleteCancelledGoogleEvents(int $tenantId, array &$result): void
    {
        $settings = (new CalendarAvailabilityService())->settings($tenantId);
        if (empty($settings['delete_google_event_on_cancel'])) {
            return;
        }
        $statement = Database::connection()->prepare(
            'SELECT id FROM calendar_appointments
             WHERE tenant_id = :tenant_id
               AND availability_source IN ("google_free_slots", "internal_fallback")
               AND status IN ("cancelled", "rejected")
               AND google_event_id IS NOT NULL AND google_event_id <> ""
               AND COALESCE(google_event_state, "") <> "deleted"
             ORDER BY updated_at ASC LIMIT 20'
        );
        $statement->execute(['tenant_id' => $tenantId]);
        foreach (array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN)) as $appointmentId) {
            $delete = $this->cancelAppointment($tenantId, $appointmentId, true);
            if (!empty($delete['ok'])) {
                $result['google_events_deleted']++;
            } else {
                $result['errors'][] = 'Remoção #' . $appointmentId . ': ' . (string) ($delete['message'] ?? 'falha');
            }
        }
    }

    private function closeStaleRequests(int $tenantId, array &$result): void
    {
        $pdo = Database::connection();
        $select = $pdo->prepare(
            'SELECT id, appointment_id FROM calendar_availability_requests
             WHERE tenant_id = :tenant_id AND status IN ("pending", "sent")
               AND responded_at IS NULL
               AND COALESCE(sent_at, requested_at, created_at) < DATE_SUB(NOW(), INTERVAL 30 MINUTE)
             ORDER BY id ASC LIMIT 100'
        );
        $select->execute(['tenant_id' => $tenantId]);
        $rows = $select->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) {
            return;
        }
        $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $update = $pdo->prepare(
            'UPDATE calendar_availability_requests
             SET status = "failed", error_message = "Tempo de resposta do fluxo excedido.", responded_at = NOW(), updated_at = CURRENT_TIMESTAMP
             WHERE responded_at IS NULL AND id IN (' . $placeholders . ')'
        );
        $update->execute($ids);
        $result['stale_requests_closed'] += $update->rowCount();
    }

    /** @return int[] */
    private function maintenanceTenantIds(?int $tenantId): array
    {
        if ($tenantId !== null && $tenantId > 0) {
            return [$tenantId];
        }
        $statement = Database::connection()->query(
            'SELECT tenant_id FROM tenant_calendar_availability_settings
             WHERE enabled = 1
               AND maintenance_enabled = 1
               AND (maintenance_last_run_at IS NULL
                    OR TIMESTAMPDIFF(MINUTE, maintenance_last_run_at, NOW()) >= maintenance_interval_minutes)
             ORDER BY tenant_id'
        );
        return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    private function touchMaintenance(int $tenantId): void
    {
        $pdo = Database::connection();
        $pdo->prepare(
            'UPDATE calendar_appointments SET maintenance_last_checked_at = NOW()
             WHERE tenant_id = :tenant_id
               AND (availability_source IN ("google_free_slots", "google_marked_slots", "internal_fallback") OR google_event_id IS NOT NULL)'
        )->execute(['tenant_id' => $tenantId]);
        $pdo->prepare(
            'UPDATE tenant_calendar_availability_settings
             SET maintenance_last_run_at = NOW(), updated_at = CURRENT_TIMESTAMP
             WHERE tenant_id = :tenant_id'
        )->execute(['tenant_id' => $tenantId]);
    }

    private function startRun(?int $tenantId, string $origin): int
    {
        if (!$this->tableExists('calendar_maintenance_runs')) {
            return 0;
        }
        $pdo = Database::connection();
        $pdo->prepare(
            'INSERT INTO calendar_maintenance_runs (tenant_id, origin, status, started_at)
             VALUES (:tenant_id, :origin, "running", NOW())'
        )->execute([
            'tenant_id' => $tenantId !== null && $tenantId > 0 ? $tenantId : null,
            'origin' => mb_substr($origin, 0, 30),
        ]);
        return (int) $pdo->lastInsertId();
    }

    private function finishRun(int $runId, string $status, array $result, ?string $error): void
    {
        if ($runId < 1 || !$this->tableExists('calendar_maintenance_runs')) {
            return;
        }
        Database::connection()->prepare(
            'UPDATE calendar_maintenance_runs
             SET status = :status,
                 expired_holds_found = :expired_holds_found,
                 expired_holds_released = :expired_holds_released,
                 syncs_retried = :syncs_retried,
                 google_events_created = :google_events_created,
                 google_events_updated = :google_events_updated,
                 google_events_deleted = :google_events_deleted,
                 stale_requests_closed = :stale_requests_closed,
                 errors_count = :errors_count,
                 result_json = :result_json,
                 error_message = :error_message,
                 finished_at = NOW()
             WHERE id = :id'
        )->execute([
            'status' => $status,
            'expired_holds_found' => (int) ($result['expired_holds_found'] ?? 0),
            'expired_holds_released' => (int) ($result['expired_holds_released'] ?? 0),
            'syncs_retried' => (int) ($result['syncs_retried'] ?? 0),
            'google_events_created' => (int) ($result['google_events_created'] ?? 0),
            'google_events_updated' => (int) ($result['google_events_updated'] ?? 0),
            'google_events_deleted' => (int) ($result['google_events_deleted'] ?? 0),
            'stale_requests_closed' => (int) ($result['stale_requests_closed'] ?? 0),
            'errors_count' => count((array) ($result['errors'] ?? [])),
            'result_json' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'error_message' => $error !== null ? mb_substr($error, 0, 700) : null,
            'id' => $runId,
        ]);
    }

    private function findRequest(int $requestId, string $requestToken): ?array
    {
        if ($requestToken === '') {
            return null;
        }
        if ($requestId > 0) {
            $statement = Database::connection()->prepare('SELECT * FROM calendar_availability_requests WHERE id = :id LIMIT 1');
            $statement->execute(['id' => $requestId]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            if ($row && hash_equals((string) $row['request_token'], $requestToken)) {
                return $row;
            }
        }
        $statement = Database::connection()->prepare('SELECT * FROM calendar_availability_requests WHERE request_token = :token LIMIT 1');
        $statement->execute(['token' => $requestToken]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function appointment(int $tenantId, int $appointmentId): ?array
    {
        $statement = Database::connection()->prepare(
            'SELECT a.*, c.name AS contact_name, c.phone, c.email
             FROM calendar_appointments a
             LEFT JOIN contacts c ON c.id = a.contact_id
             WHERE a.id = :id AND a.tenant_id = :tenant_id LIMIT 1'
        );
        $statement->execute(['id' => $appointmentId, 'tenant_id' => $tenantId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function logSync(
        int $tenantId,
        int $appointmentId,
        ?int $requestId,
        string $operation,
        string $status,
        string $calendarId,
        string $googleEventId,
        mixed $request,
        mixed $response,
        ?string $error
    ): void {
        if (!$this->tableExists('calendar_google_sync_logs')) {
            return;
        }
        try {
            Database::connection()->prepare(
                'INSERT INTO calendar_google_sync_logs
                    (tenant_id, appointment_id, request_id, slot_id, operation, status,
                     google_calendar_id, google_event_id, request_json, response_json, error_message)
                 VALUES
                    (:tenant_id, :appointment_id, :request_id, NULL, :operation, :status,
                     :google_calendar_id, :google_event_id, :request_json, :response_json, :error_message)'
            )->execute([
                'tenant_id' => $tenantId,
                'appointment_id' => $appointmentId,
                'request_id' => $requestId,
                'operation' => mb_substr($operation, 0, 40),
                'status' => mb_substr($status, 0, 30),
                'google_calendar_id' => $calendarId !== '' ? mb_substr($calendarId, 0, 255) : null,
                'google_event_id' => $googleEventId !== '' ? mb_substr($googleEventId, 0, 255) : null,
                'request_json' => $request !== null ? json_encode($request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                'response_json' => $response !== null ? json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                'error_message' => $error !== null ? mb_substr($error, 0, 700) : null,
            ]);
        } catch (Throwable) {
        }
    }

    private function hasColumn(string $table, string $column): bool
    {
        try {
            $statement = Database::connection()->prepare(
                'SELECT 1 FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = :table AND column_name = :column LIMIT 1'
            );
            $statement->execute(['table' => $table, 'column' => $column]);
            return (bool) $statement->fetchColumn();
        } catch (Throwable) {
            return false;
        }
    }

    private function tableExists(string $table): bool
    {
        try {
            $statement = Database::connection()->prepare(
                'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table LIMIT 1'
            );
            $statement->execute(['table' => $table]);
            return (bool) $statement->fetchColumn();
        } catch (Throwable) {
            return false;
        }
    }
}
