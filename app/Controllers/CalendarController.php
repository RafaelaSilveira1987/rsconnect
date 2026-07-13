<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Env;
use App\Core\Flash;
use App\Core\Router;
use App\Core\View;
use App\Services\AutomationWebhookService;
use App\Services\SubscriptionService;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;

final class CalendarController
{
    public function index(): void
    {
        $pdo = Database::connection();
        $tenantId = $this->resolveTenantFromQuery();
        $today = new DateTimeImmutable('today', new DateTimeZone((string) Env::get('APP_TIMEZONE', 'America/Sao_Paulo')));
        $filters = [
            'tenant_id' => $tenantId,
            'status' => (string) ($_GET['status'] ?? ''),
            'owner_user_id' => (int) ($_GET['owner_user_id'] ?? 0),
            'date_from' => trim((string) ($_GET['date_from'] ?? $today->format('Y-m-d'))),
            'date_to' => trim((string) ($_GET['date_to'] ?? $today->modify('+14 days')->format('Y-m-d'))),
        ];

        $tenants = Auth::isSuperAdmin()
            ? $pdo->query('SELECT id, name FROM tenants WHERE status = "active" ORDER BY name')->fetchAll(PDO::FETCH_ASSOC)
            : [];

        $appointments = [];
        $contacts = [];
        $leads = [];
        $conversations = [];
        $team = [];
        $metrics = ['today_count' => 0, 'upcoming_count' => 0, 'pending_sync' => 0, 'completed_count' => 0];

        if ($tenantId > 0) {
            $conditions = ['a.tenant_id = :tenant_id'];
            $params = ['tenant_id' => $tenantId];

            if (in_array($filters['status'], ['pre_scheduled', 'awaiting_approval', 'scheduled', 'confirmed', 'completed', 'cancelled', 'rejected', 'rescheduled', 'no_show'], true)) {
                $conditions[] = 'a.status = :status';
                $params['status'] = $filters['status'];
            }
            if ($filters['owner_user_id'] > 0) {
                $conditions[] = 'a.owner_user_id = :owner_user_id';
                $params['owner_user_id'] = $filters['owner_user_id'];
            }
            if ($filters['date_from'] !== '') {
                $conditions[] = 'a.starts_at >= :date_from';
                $params['date_from'] = $filters['date_from'] . ' 00:00:00';
            }
            if ($filters['date_to'] !== '') {
                $conditions[] = 'a.starts_at <= :date_to';
                $params['date_to'] = $filters['date_to'] . ' 23:59:59';
            }

            $statement = $pdo->prepare(
                'SELECT a.*, ct.name AS contact_name, ct.phone, l.title AS lead_title,
                        c.remote_jid, u.name AS owner_name, creator.name AS creator_name
                 FROM calendar_appointments a
                 LEFT JOIN contacts ct ON ct.id = a.contact_id
                 LEFT JOIN crm_leads l ON l.id = a.crm_lead_id
                 LEFT JOIN conversations c ON c.id = a.conversation_id
                 LEFT JOIN users u ON u.id = a.owner_user_id
                 LEFT JOIN users creator ON creator.id = a.created_by_user_id
                 WHERE ' . implode(' AND ', $conditions) . '
                 ORDER BY a.starts_at ASC, a.created_at DESC
                 LIMIT 300'
            );
            $statement->execute($params);
            $appointments = $statement->fetchAll(PDO::FETCH_ASSOC);

            $contactStatement = $pdo->prepare(
                'SELECT id, name, phone FROM contacts WHERE tenant_id = :tenant_id AND status <> "inactive" ORDER BY COALESCE(name, phone)'
            );
            $contactStatement->execute(['tenant_id' => $tenantId]);
            $contacts = $contactStatement->fetchAll(PDO::FETCH_ASSOC);

            $leadStatement = $pdo->prepare(
                'SELECT l.id, l.title, ct.name AS contact_name, ct.phone
                 FROM crm_leads l
                 INNER JOIN contacts ct ON ct.id = l.contact_id
                 WHERE l.tenant_id = :tenant_id AND l.status = "open"
                 ORDER BY l.updated_at DESC'
            );
            $leadStatement->execute(['tenant_id' => $tenantId]);
            $leads = $leadStatement->fetchAll(PDO::FETCH_ASSOC);

            $conversationStatement = $pdo->prepare(
                'SELECT c.id, c.last_message_preview, ct.name AS contact_name, ct.phone
                 FROM conversations c
                 INNER JOIN contacts ct ON ct.id = c.contact_id
                 WHERE c.tenant_id = :tenant_id AND c.status <> "closed"
                 ORDER BY c.last_message_at DESC
                 LIMIT 100'
            );
            $conversationStatement->execute(['tenant_id' => $tenantId]);
            $conversations = $conversationStatement->fetchAll(PDO::FETCH_ASSOC);

            $teamStatement = $pdo->prepare(
                'SELECT id, name FROM users WHERE tenant_id = :tenant_id AND status = "active" ORDER BY name'
            );
            $teamStatement->execute(['tenant_id' => $tenantId]);
            $team = $teamStatement->fetchAll(PDO::FETCH_ASSOC);

            $metricStatement = $pdo->prepare(
                'SELECT
                    COALESCE(SUM(status IN ("scheduled", "confirmed") AND DATE(starts_at) = CURDATE()), 0) AS today_count,
                    COALESCE(SUM(status IN ("scheduled", "confirmed") AND starts_at >= NOW()), 0) AS upcoming_count,
                    COALESCE(SUM(status IN ("pre_scheduled", "awaiting_approval")), 0) AS pre_schedule_pending,
                    COALESCE(SUM(sync_status IN ("pending", "failed")), 0) AS pending_sync,
                    COALESCE(SUM(status = "completed" AND starts_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)), 0) AS completed_count
                 FROM calendar_appointments
                 WHERE tenant_id = :tenant_id'
            );
            $metricStatement->execute(['tenant_id' => $tenantId]);
            $metrics = $metricStatement->fetch(PDO::FETCH_ASSOC) ?: $metrics;
        }

        View::render('calendar.index', [
            'title' => 'Agenda',
            'tenants' => $tenants,
            'appointments' => $appointments,
            'contacts' => $contacts,
            'leads' => $leads,
            'conversations' => $conversations,
            'team' => $team,
            'metrics' => $metrics,
            'filters' => $filters,
            'canManage' => Auth::can('calendar.manage'),
        ]);
    }

    public function store(): void
    {
        $tenantId = $this->resolveTenantFromPost();
        $title = trim((string) ($_POST['title'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $startsAt = trim((string) ($_POST['starts_at'] ?? ''));
        $endsAt = trim((string) ($_POST['ends_at'] ?? ''));
        $timezone = trim((string) ($_POST['timezone'] ?? (string) Env::get('APP_TIMEZONE', 'America/Sao_Paulo')));
        $contactId = (int) ($_POST['contact_id'] ?? 0);
        $leadId = (int) ($_POST['crm_lead_id'] ?? 0);
        $conversationId = (int) ($_POST['conversation_id'] ?? 0);
        $ownerUserId = (int) ($_POST['owner_user_id'] ?? 0);
        $locationType = (string) ($_POST['location_type'] ?? 'online');
        $location = trim((string) ($_POST['location'] ?? ''));
        $meetingUrl = trim((string) ($_POST['meeting_url'] ?? ''));
        $reminderMinutes = (int) ($_POST['reminder_minutes'] ?? 60);
        $isPreSchedule = isset($_POST['is_pre_schedule']) ? 1 : 0;
        $preferredDayText = trim((string) ($_POST['preferred_day_text'] ?? ''));
        $preferredTimeText = trim((string) ($_POST['preferred_time_text'] ?? ''));
        $initialStatus = $isPreSchedule === 1 ? 'pre_scheduled' : 'scheduled';

        if ($tenantId < 1 || $title === '' || $startsAt === '' || $endsAt === '') {
            Flash::set('error', 'Informe empresa, título, início e fim do agendamento.');
            $this->redirect('/calendar');
        }
        $limit = (new SubscriptionService())->ensureCanCreate($tenantId, 'appointments_month');
        if (empty($limit['ok'])) {
            Flash::set('error', $limit['message']);
            $this->redirect('/calendar');
        }

        if (!in_array($locationType, ['online', 'presencial', 'telefone'], true)) {
            $locationType = 'online';
        }
        if ($reminderMinutes < 0 || $reminderMinutes > 10080) {
            $reminderMinutes = 60;
        }

        $normalized = $this->normalizePeriod($startsAt, $endsAt);
        if ($normalized === null) {
            Flash::set('error', 'Confira as datas do agendamento. O fim precisa ser depois do início.');
            $this->redirect('/calendar?tenant_id=' . $tenantId);
        }

        if ($leadId > 0) {
            $lead = $this->findLead($leadId, $tenantId);
            if (!$lead) {
                Flash::set('error', 'O negócio selecionado não pertence à empresa.');
                $this->redirect('/calendar?tenant_id=' . $tenantId);
            }
            $contactId = (int) $lead['contact_id'];
        }
        if ($conversationId > 0) {
            $conversation = $this->findConversation($conversationId, $tenantId);
            if (!$conversation) {
                Flash::set('error', 'A conversa selecionada não pertence à empresa.');
                $this->redirect('/calendar?tenant_id=' . $tenantId);
            }
            $contactId = (int) $conversation['contact_id'];
        }
        if ($contactId > 0 && !$this->contactBelongsToTenant($contactId, $tenantId)) {
            Flash::set('error', 'O contato selecionado não pertence à empresa.');
            $this->redirect('/calendar?tenant_id=' . $tenantId);
        }
        if ($ownerUserId > 0 && !$this->userBelongsToTenant($ownerUserId, $tenantId)) {
            Flash::set('error', 'O responsável selecionado não pertence à empresa.');
            $this->redirect('/calendar?tenant_id=' . $tenantId);
        }

        $statement = Database::connection()->prepare(
            'INSERT INTO calendar_appointments
                (tenant_id, contact_id, crm_lead_id, conversation_id, owner_user_id, created_by_user_id,
                 title, description, starts_at, ends_at, timezone, status, location_type,
                 location, meeting_url, reminder_minutes, sync_status, is_pre_schedule, pre_schedule_source,
                 preferred_day_text, preferred_time_text, approval_status)
             VALUES
                (:tenant_id, :contact_id, :crm_lead_id, :conversation_id, :owner_user_id, :created_by_user_id,
                 :title, :description, :starts_at, :ends_at, :timezone, :status, :location_type,
                 :location, :meeting_url, :reminder_minutes, "pending", :is_pre_schedule, :pre_schedule_source,
                 :preferred_day_text, :preferred_time_text, :approval_status)'
        );
        $statement->execute([
            'tenant_id' => $tenantId,
            'contact_id' => $contactId > 0 ? $contactId : null,
            'crm_lead_id' => $leadId > 0 ? $leadId : null,
            'conversation_id' => $conversationId > 0 ? $conversationId : null,
            'owner_user_id' => $ownerUserId > 0 ? $ownerUserId : null,
            'created_by_user_id' => Auth::id(),
            'title' => $title,
            'description' => $description !== '' ? $description : null,
            'starts_at' => $normalized['starts_at'],
            'ends_at' => $normalized['ends_at'],
            'timezone' => $timezone !== '' ? $timezone : 'America/Sao_Paulo',
            'status' => $initialStatus,
            'location_type' => $locationType,
            'location' => $location !== '' ? $location : null,
            'meeting_url' => $meetingUrl !== '' ? $meetingUrl : null,
            'reminder_minutes' => $reminderMinutes,
            'is_pre_schedule' => $isPreSchedule,
            'pre_schedule_source' => $isPreSchedule === 1 ? 'manual' : null,
            'preferred_day_text' => $preferredDayText !== '' ? $preferredDayText : null,
            'preferred_time_text' => $preferredTimeText !== '' ? $preferredTimeText : null,
            'approval_status' => $isPreSchedule === 1 ? 'pending' : null,
        ]);
        $appointmentId = (int) Database::connection()->lastInsertId();
        Audit::log('calendar.appointment_created', ['appointment_id' => $appointmentId], $tenantId);
        $this->trySyncToN8n($appointmentId, $tenantId, 'created');
        Flash::set('success', $isPreSchedule === 1 ? 'Pré-agendamento criado para aprovação.' : 'Agendamento criado.');
        $this->redirect('/calendar?tenant_id=' . $tenantId);
    }

    public function updateStatus(): void
    {
        $tenantId = $this->resolveTenantFromPost();
        $appointmentId = (int) ($_POST['appointment_id'] ?? 0);
        $status = (string) ($_POST['status'] ?? 'scheduled');

        if (!in_array($status, ['pre_scheduled', 'awaiting_approval', 'scheduled', 'confirmed', 'completed', 'cancelled', 'rejected', 'rescheduled', 'no_show'], true)) {
            Flash::set('error', 'Status de agendamento inválido.');
            $this->redirect('/calendar?tenant_id=' . $tenantId);
        }

        $approvalStatus = match ($status) {
            'confirmed' => 'approved',
            'rejected', 'cancelled' => 'rejected',
            'rescheduled', 'pre_scheduled', 'awaiting_approval' => 'pending',
            default => null,
        };
        $approvalSet = '';
        $params = ['status' => $status, 'id' => $appointmentId, 'tenant_id' => $tenantId];
        if ($this->hasColumn('calendar_appointments', 'approval_status') && $approvalStatus !== null) {
            $approvalSet = ', approval_status = :approval_status';
            $params['approval_status'] = $approvalStatus;
            if ($approvalStatus === 'approved' && $this->hasColumn('calendar_appointments', 'approved_at')) {
                $approvalSet .= ', approved_at = NOW(), approved_by_user_id = :approved_by_user_id';
                $params['approved_by_user_id'] = Auth::id();
            }
        }

        $statement = Database::connection()->prepare(
            'UPDATE calendar_appointments
             SET status = :status' . $approvalSet . ', updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND tenant_id = :tenant_id'
        );
        $statement->execute($params);

        if ($statement->rowCount() === 0) {
            Flash::set('error', 'Agendamento não encontrado.');
        } else {
            Audit::log('calendar.appointment_status_updated', ['appointment_id' => $appointmentId, 'status' => $status], $tenantId);
            $this->trySyncToN8n($appointmentId, $tenantId, 'status_updated');
            Flash::set('success', 'Status do agendamento atualizado.');
        }

        $return = trim((string) ($_POST['return_to'] ?? ''));
        if ($return !== '' && str_starts_with($return, '/')) {
            $this->redirect($return);
        }
        $this->redirect('/calendar?tenant_id=' . $tenantId);
    }

    public function ics(): void
    {
        $appointmentId = (int) ($_GET['id'] ?? 0);
        $tenantId = Auth::isSuperAdmin() ? (int) ($_GET['tenant_id'] ?? 0) : (int) Auth::tenantId();
        $appointment = $this->findAppointment($appointmentId, $tenantId);
        if (!$appointment) {
            http_response_code(404);
            echo 'Agendamento não encontrado.';
            return;
        }

        $uid = 'rsconnect-' . $appointment['id'] . '@rsconnect.local';
        $start = gmdate('Ymd\THis\Z', strtotime((string) $appointment['starts_at']));
        $end = gmdate('Ymd\THis\Z', strtotime((string) $appointment['ends_at']));
        $summary = $this->escapeIcs((string) $appointment['title']);
        $description = $this->escapeIcs((string) ($appointment['description'] ?? ''));
        $location = $this->escapeIcs((string) (($appointment['meeting_url'] ?? '') ?: ($appointment['location'] ?? '')));
        $now = gmdate('Ymd\THis\Z');

        $ics = implode("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//RS Connect//Agenda//PT-BR',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            'UID:' . $uid,
            'DTSTAMP:' . $now,
            'DTSTART:' . $start,
            'DTEND:' . $end,
            'SUMMARY:' . $summary,
            'DESCRIPTION:' . $description,
            'LOCATION:' . $location,
            'END:VEVENT',
            'END:VCALENDAR',
            '',
        ]);

        header('Content-Type: text/calendar; charset=UTF-8');
        header('Content-Disposition: attachment; filename="agendamento-rs-connect-' . $appointment['id'] . '.ics"');
        echo $ics;
    }

    private function resolveTenantFromQuery(): int
    {
        if (!Auth::isSuperAdmin()) {
            return (int) Auth::tenantId();
        }
        $requested = (int) ($_GET['tenant_id'] ?? 0);
        if ($requested > 0) {
            return $requested;
        }
        $first = Database::connection()->query('SELECT id FROM tenants WHERE status = "active" ORDER BY name LIMIT 1')->fetchColumn();
        return $first ? (int) $first : 0;
    }

    private function resolveTenantFromPost(): int
    {
        return Auth::isSuperAdmin()
            ? (int) ($_POST['tenant_id'] ?? 0)
            : (int) Auth::tenantId();
    }

    private function normalizePeriod(string $startsAt, string $endsAt): ?array
    {
        $startTimestamp = strtotime($startsAt);
        $endTimestamp = strtotime($endsAt);
        if ($startTimestamp === false || $endTimestamp === false || $endTimestamp <= $startTimestamp) {
            return null;
        }
        return [
            'starts_at' => date('Y-m-d H:i:s', $startTimestamp),
            'ends_at' => date('Y-m-d H:i:s', $endTimestamp),
        ];
    }

    private function findLead(int $leadId, int $tenantId): ?array
    {
        $statement = Database::connection()->prepare('SELECT id, contact_id FROM crm_leads WHERE id = :id AND tenant_id = :tenant_id LIMIT 1');
        $statement->execute(['id' => $leadId, 'tenant_id' => $tenantId]);
        $lead = $statement->fetch(PDO::FETCH_ASSOC);
        return $lead ?: null;
    }

    private function findConversation(int $conversationId, int $tenantId): ?array
    {
        $statement = Database::connection()->prepare('SELECT id, contact_id FROM conversations WHERE id = :id AND tenant_id = :tenant_id LIMIT 1');
        $statement->execute(['id' => $conversationId, 'tenant_id' => $tenantId]);
        $conversation = $statement->fetch(PDO::FETCH_ASSOC);
        return $conversation ?: null;
    }

    private function findAppointment(int $appointmentId, int $tenantId): ?array
    {
        if ($appointmentId < 1 || $tenantId < 1) {
            return null;
        }
        $statement = Database::connection()->prepare('SELECT * FROM calendar_appointments WHERE id = :id AND tenant_id = :tenant_id LIMIT 1');
        $statement->execute(['id' => $appointmentId, 'tenant_id' => $tenantId]);
        $appointment = $statement->fetch(PDO::FETCH_ASSOC);
        return $appointment ?: null;
    }

    private function contactBelongsToTenant(int $contactId, int $tenantId): bool
    {
        $statement = Database::connection()->prepare('SELECT COUNT(*) FROM contacts WHERE id = :id AND tenant_id = :tenant_id');
        $statement->execute(['id' => $contactId, 'tenant_id' => $tenantId]);
        return (int) $statement->fetchColumn() > 0;
    }

    private function userBelongsToTenant(int $userId, int $tenantId): bool
    {
        $statement = Database::connection()->prepare('SELECT COUNT(*) FROM users WHERE id = :id AND tenant_id = :tenant_id AND status = "active"');
        $statement->execute(['id' => $userId, 'tenant_id' => $tenantId]);
        return (int) $statement->fetchColumn() > 0;
    }

    private function trySyncToN8n(int $appointmentId, int $tenantId, string $event): void
    {
        $appointment = $this->findAppointment($appointmentId, $tenantId);
        if (!$appointment) {
            return;
        }

        $results = (new AutomationWebhookService())->dispatch('calendar.appointment.' . $event, [
            'tenant_id' => $tenantId,
            'appointment' => $appointment,
        ], null, $tenantId);

        if ($results === []) {
            Database::connection()->prepare(
                'UPDATE calendar_appointments SET sync_status = "not_configured", sync_error = NULL WHERE id = :id AND tenant_id = :tenant_id'
            )->execute(['id' => $appointmentId, 'tenant_id' => $tenantId]);
            return;
        }

        $success = false;
        $errors = [];
        foreach ($results as $result) {
            if (!empty($result['ok'])) {
                $success = true;
            } elseif (!empty($result['error'])) {
                $errors[] = (string) $result['error'];
            }
        }

        if ($success) {
            Database::connection()->prepare(
                'UPDATE calendar_appointments SET sync_status = "synced", sync_error = NULL, synced_at = NOW() WHERE id = :id AND tenant_id = :tenant_id'
            )->execute(['id' => $appointmentId, 'tenant_id' => $tenantId]);
            return;
        }

        Database::connection()->prepare(
            'UPDATE calendar_appointments SET sync_status = "failed", sync_error = :error WHERE id = :id AND tenant_id = :tenant_id'
        )->execute([
            'error' => mb_substr(implode(' | ', $errors) ?: 'Nenhum fluxo n8n respondeu com sucesso.', 0, 500),
            'id' => $appointmentId,
            'tenant_id' => $tenantId,
        ]);
    }

    private function hasColumn(string $table, string $column): bool
    {
        try {
            $statement = Database::connection()->prepare(
                'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column'
            );
            $statement->execute(['table' => $table, 'column' => $column]);
            return (int) $statement->fetchColumn() > 0;
        } catch (Throwable) {
            return false;
        }
    }

    private function escapeIcs(string $value): string
    {
        return str_replace(["\\", ";", ",", "\r\n", "\n", "\r"], ["\\\\", "\\;", "\\,", "\\n", "\\n", "\\n"], $value);
    }

    private function redirect(string $path): never
    {
        header('Location: ' . Router::url($path));
        exit;
    }
}
