<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use DateInterval;
use DatePeriod;
use DateTimeImmutable;
use PDO;
use Throwable;

/**
 * Consolida atendimento, responsabilidade, agenda e carteira preferencial por
 * profissional. A base histórica é fornecida pelas migrations 067 e 068.
 */
final class TeamProfessionalReportService
{
    public const VERSION = '36.10.6-team-professional-reports-audit';

    private PDO $pdo;
    private TeamMetricsFoundationService $foundation;
    private array $warnings = [];

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::connection();
        $this->foundation = new TeamMetricsFoundationService($this->pdo);
    }

    public function build(array $filters): array
    {
        $this->warnings = [];
        $tenantId = (int) ($filters['tenant_id'] ?? 0);
        $scope = $this->foundation->assertMayView($tenantId);
        $readiness = $this->foundation->readiness();
        $selectedUserId = $this->selectedUserId($tenantId, $scope, (int) ($filters['user_id'] ?? 0));
        $tenant = $this->row(
            'SELECT t.id, t.name, t.professional_assignment_enabled, t.professional_calendar_enabled,
                    COALESCE(NULLIF(os.business_timezone, ""), NULLIF(cas.timezone, ""), "America/Sao_Paulo") AS timezone
             FROM tenants t
             LEFT JOIN tenant_onboarding_settings os ON os.tenant_id = t.id
             LEFT JOIN tenant_calendar_availability_settings cas ON cas.tenant_id = t.id
             WHERE t.id = :tenant_id LIMIT 1',
            ['tenant_id' => $tenantId]
        );
        $users = $this->users($tenantId, $scope);

        $base = [
            'version' => self::VERSION,
            'scope' => $scope,
            'readiness' => $readiness,
            'tenant' => $tenant,
            'users' => $users,
            'selected_user_id' => $selectedUserId,
            'overview' => $this->emptyOverview(),
            'professionals' => [],
            'dailySeries' => [],
            'recentActivities' => [],
            'responseAudit' => [],
            'dataQuality' => $this->emptyDataQuality(),
            'warnings' => [],
        ];

        if (!$readiness['ready']) {
            $this->warnings[] = 'As migrations históricas até a 071 ainda não estão completas. Aplique o contrato UTC antes de usar este relatório.';
            $base['warnings'] = $this->warnings;
            return $base;
        }

        $professionals = $this->professionalBase($users, $selectedUserId);
        $date = $this->dateParams($filters, (string) ($tenant['timezone'] ?? 'America/Sao_Paulo'));

        $this->mergeRows($professionals, $this->humanMessages($tenantId, $date, $selectedUserId));
        $this->mergeRows($professionals, $this->firstResponses($tenantId, $date, $selectedUserId));
        $this->mergeRows($professionals, $this->assignmentIncoming($tenantId, $date, $selectedUserId));
        $this->mergeRows($professionals, $this->assignmentOutgoing($tenantId, $date, $selectedUserId));
        $this->mergeRows($professionals, $this->conversationClosures($tenantId, $date, $selectedUserId));
        $this->mergeRows($professionals, $this->openConversations($tenantId, $selectedUserId));
        $this->mergeRows($professionals, $this->appointments($tenantId, $date, $selectedUserId));
        $this->mergeRows($professionals, $this->appointmentTransfers($tenantId, $date, $selectedUserId));
        $this->mergeRows($professionals, $this->preferredClients($tenantId, $selectedUserId));

        foreach ($professionals as &$professional) {
            $appointments = (int) ($professional['appointments'] ?? 0);
            $completed = (int) ($professional['appointments_completed'] ?? 0);
            $confirmed = (int) ($professional['appointments_confirmed'] ?? 0);
            $cancelled = (int) ($professional['appointments_cancelled'] ?? 0);
            $noShow = (int) ($professional['appointments_no_show'] ?? 0);
            $finishedBase = $completed + $cancelled + $noShow;
            $professional['appointment_success_rate'] = $appointments > 0
                ? (($completed + $confirmed) / $appointments) * 100
                : 0.0;
            $professional['attendance_rate'] = $finishedBase > 0
                ? ($completed / $finishedBase) * 100
                : 0.0;
            $professional['avg_first_response_seconds'] = (int) round((float) ($professional['avg_first_response_seconds'] ?? 0));
            $professional['activity_score'] = (int) ($professional['human_messages'] ?? 0)
                + ((int) ($professional['appointments_completed'] ?? 0) * 3)
                + ((int) ($professional['closed_conversations'] ?? 0) * 2);
        }
        unset($professional);

        uasort($professionals, static function (array $a, array $b): int {
            $score = ((int) ($b['activity_score'] ?? 0)) <=> ((int) ($a['activity_score'] ?? 0));
            return $score !== 0 ? $score : strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
        });

        $professionalRows = array_values($professionals);
        $dataQuality = $this->responseDataQuality($tenantId, $date, $selectedUserId);
        $overview = $this->overview($professionalRows, $scope, $selectedUserId);
        // Use the raw cycle aggregate instead of an average of rounded professional averages.
        $overview['first_responses'] = (int) ($dataQuality['measured_responses'] ?? 0);
        $overview['avg_first_response_seconds'] = (int) ($dataQuality['avg_response_seconds'] ?? 0);
        $dailySeries = $this->dailySeries($tenantId, $filters, $selectedUserId);
        $activities = $this->recentActivities($tenantId, $date, $selectedUserId);
        $responseAudit = $this->firstResponseAudit($tenantId, $date, $selectedUserId, 50);

        return array_merge($base, [
            'overview' => $overview,
            'professionals' => $professionalRows,
            'dailySeries' => $dailySeries,
            'recentActivities' => $activities,
            'responseAudit' => $responseAudit,
            'dataQuality' => $dataQuality,
            'warnings' => array_values(array_unique($this->warnings)),
        ]);
    }

    /**
     * Returns auditable first-response cycles for CSV export. Public identifiers
     * are used so internal numeric IDs never leave the application.
     */
    public function firstResponseExport(array $filters): array
    {
        $this->warnings = [];
        $tenantId = (int) ($filters['tenant_id'] ?? 0);
        $scope = $this->foundation->assertMayView($tenantId);
        $selectedUserId = $this->selectedUserId($tenantId, $scope, (int) ($filters['user_id'] ?? 0));
        $timezone = $this->tenantTimezone($tenantId);
        $date = $this->dateParams($filters, $timezone);
        return $this->firstResponseAudit($tenantId, $date, $selectedUserId, 5000);
    }

    private function selectedUserId(int $tenantId, array $scope, int $requested): int
    {
        if (($scope['mode'] ?? '') === 'own') {
            return (int) ($scope['user_id'] ?? 0);
        }
        if ($requested < 1) {
            return 0;
        }
        $exists = $this->scalar(
            'SELECT COUNT(*) FROM users WHERE id = :user_id AND tenant_id = :tenant_id',
            ['user_id' => $requested, 'tenant_id' => $tenantId]
        );
        return $exists > 0 ? $requested : 0;
    }

    private function users(int $tenantId, array $scope): array
    {
        $params = ['tenant_id' => $tenantId];
        $where = '';
        if (($scope['mode'] ?? '') === 'own') {
            $where = ' AND id = :scope_user_id';
            $params['scope_user_id'] = (int) ($scope['user_id'] ?? 0);
        }
        return $this->rows(
            'SELECT id, name, whatsapp_display_name, whatsapp_role_label, role, status
             FROM users
             WHERE tenant_id = :tenant_id' . $where . '
             ORDER BY status = "active" DESC, name',
            $params
        );
    }

    private function professionalBase(array $users, int $selectedUserId): array
    {
        $result = [];
        foreach ($users as $user) {
            $id = (int) ($user['id'] ?? 0);
            if ($id < 1 || ($selectedUserId > 0 && $id !== $selectedUserId)) {
                continue;
            }
            $result[$id] = [
                'user_id' => $id,
                'name' => (string) ($user['whatsapp_display_name'] ?: $user['name'] ?: 'Usuário'),
                'account_name' => (string) ($user['name'] ?? ''),
                'role_label' => (string) ($user['whatsapp_role_label'] ?: $this->roleLabel((string) ($user['role'] ?? 'client_user'))),
                'role' => (string) ($user['role'] ?? 'client_user'),
                'status' => (string) ($user['status'] ?? 'active'),
                'human_messages' => 0,
                'conversations_replied' => 0,
                'first_responses' => 0,
                'avg_first_response_seconds' => 0,
                'assignments' => 0,
                'assigned_conversations' => 0,
                'transfers_received' => 0,
                'transfers_out' => 0,
                'releases' => 0,
                'closed_conversations' => 0,
                'open_conversations' => 0,
                'appointments' => 0,
                'appointments_scheduled' => 0,
                'appointments_confirmed' => 0,
                'appointments_completed' => 0,
                'appointments_cancelled' => 0,
                'appointments_no_show' => 0,
                'appointments_upcoming' => 0,
                'appointment_transfers_received' => 0,
                'appointment_transfers_out' => 0,
                'preferred_clients' => 0,
            ];
        }
        return $result;
    }

    private function humanMessages(int $tenantId, array $date, int $userId): array
    {
        [$filter, $params] = $this->userFilter('sender_user_id', $userId);
        return $this->rows(
            'SELECT sender_user_id AS user_id,
                    COUNT(*) AS human_messages,
                    COUNT(DISTINCT conversation_id) AS conversations_replied
             FROM conversation_messages
             WHERE tenant_id = :tenant_id
               AND direction = "outgoing"
               AND sender_type = "user"
               AND sender_user_id IS NOT NULL
               AND sent_at BETWEEN :start_at AND :end_at' . $filter . '
             GROUP BY sender_user_id',
            ['tenant_id' => $tenantId, 'start_at' => $date['utc_start'], 'end_at' => $date['utc_end']] + $params
        );
    }

    private function firstResponses(int $tenantId, array $date, int $userId): array
    {
        [$filter, $params] = $this->userFilter('first_response_user_id', $userId);
        return $this->rows(
            'SELECT first_response_user_id AS user_id,
                    COUNT(*) AS first_responses,
                    AVG(GREATEST(0, TIMESTAMPDIFF(SECOND, first_incoming_at, first_response_at))) AS avg_first_response_seconds
             FROM conversation_service_cycles
             WHERE tenant_id = :tenant_id
               AND first_response_user_id IS NOT NULL
               AND first_response_at BETWEEN :start_at AND :end_at' . $filter . '
             GROUP BY first_response_user_id',
            ['tenant_id' => $tenantId, 'start_at' => $date['utc_start'], 'end_at' => $date['utc_end']] + $params
        );
    }

    private function assignmentIncoming(int $tenantId, array $date, int $userId): array
    {
        [$filter, $params] = $this->userFilter('assigned_user_id', $userId);
        return $this->rows(
            'SELECT assigned_user_id AS user_id,
                    SUM(action = "assign") AS assignments,
                    COUNT(DISTINCT conversation_id) AS assigned_conversations,
                    SUM(action = "transfer") AS transfers_received
             FROM conversation_assignment_history
             WHERE tenant_id = :tenant_id
               AND assigned_user_id IS NOT NULL
               AND occurred_at BETWEEN :start_at AND :end_at' . $filter . '
             GROUP BY assigned_user_id',
            ['tenant_id' => $tenantId, 'start_at' => $date['utc_start'], 'end_at' => $date['utc_end']] + $params
        );
    }

    private function assignmentOutgoing(int $tenantId, array $date, int $userId): array
    {
        [$filter, $params] = $this->userFilter('previous_user_id', $userId);
        return $this->rows(
            'SELECT previous_user_id AS user_id,
                    SUM(action = "transfer") AS transfers_out,
                    SUM(action = "release") AS releases
             FROM conversation_assignment_history
             WHERE tenant_id = :tenant_id
               AND previous_user_id IS NOT NULL
               AND occurred_at BETWEEN :start_at AND :end_at' . $filter . '
             GROUP BY previous_user_id',
            ['tenant_id' => $tenantId, 'start_at' => $date['utc_start'], 'end_at' => $date['utc_end']] + $params
        );
    }

    private function conversationClosures(int $tenantId, array $date, int $userId): array
    {
        [$filter, $params] = $this->userFilter('closed_by_user_id', $userId);
        return $this->rows(
            'SELECT closed_by_user_id AS user_id,
                    COUNT(*) AS closed_conversations
             FROM conversation_service_cycles
             WHERE tenant_id = :tenant_id
               AND cycle_status = "closed"
               AND closed_by_user_id IS NOT NULL
               AND closed_at BETWEEN :start_at AND :end_at' . $filter . '
             GROUP BY closed_by_user_id',
            ['tenant_id' => $tenantId, 'start_at' => $date['utc_start'], 'end_at' => $date['utc_end']] + $params
        );
    }

    private function openConversations(int $tenantId, int $userId): array
    {
        [$filter, $params] = $this->userFilter('assigned_user_id', $userId);
        return $this->rows(
            'SELECT assigned_user_id AS user_id, COUNT(*) AS open_conversations
             FROM conversations
             WHERE tenant_id = :tenant_id
               AND status = "open"
               AND assigned_user_id IS NOT NULL' . $filter . '
             GROUP BY assigned_user_id',
            ['tenant_id' => $tenantId] + $params
        );
    }

    private function appointments(int $tenantId, array $date, int $userId): array
    {
        [$filter, $params] = $this->userFilter('owner_user_id', $userId);
        return $this->rows(
            'SELECT owner_user_id AS user_id,
                    COUNT(*) AS appointments,
                    SUM(status IN ("scheduled", "pre_scheduled", "awaiting_approval", "rescheduled")) AS appointments_scheduled,
                    SUM(status = "confirmed") AS appointments_confirmed,
                    SUM(status = "completed") AS appointments_completed,
                    SUM(status IN ("cancelled", "rejected")) AS appointments_cancelled,
                    SUM(status = "no_show") AS appointments_no_show,
                    SUM(starts_at >= :now_local AND status NOT IN ("cancelled", "rejected", "completed", "no_show")) AS appointments_upcoming
             FROM calendar_appointments
             WHERE tenant_id = :tenant_id
               AND owner_user_id IS NOT NULL
               AND starts_at BETWEEN :start_at AND :end_at' . $filter . '
             GROUP BY owner_user_id',
            ['tenant_id' => $tenantId, 'start_at' => $date['local_start'], 'end_at' => $date['local_end'], 'now_local' => $date['now_local']] + $params
        );
    }

    private function appointmentTransfers(int $tenantId, array $date, int $userId): array
    {
        $rows = [];
        [$inFilter, $inParams] = $this->userFilter('owner_user_id', $userId);
        foreach ($this->rows(
            'SELECT owner_user_id AS user_id, COUNT(*) AS appointment_transfers_received
             FROM calendar_appointment_history
             WHERE tenant_id = :tenant_id
               AND event_type = "owner_changed"
               AND owner_user_id IS NOT NULL
               AND occurred_at BETWEEN :start_at AND :end_at' . $inFilter . '
             GROUP BY owner_user_id',
            ['tenant_id' => $tenantId, 'start_at' => $date['utc_start'], 'end_at' => $date['utc_end']] + $inParams
        ) as $row) {
            $rows[(int) $row['user_id']] = $row;
        }

        [$outFilter, $outParams] = $this->userFilter('previous_owner_user_id', $userId);
        foreach ($this->rows(
            'SELECT previous_owner_user_id AS user_id, COUNT(*) AS appointment_transfers_out
             FROM calendar_appointment_history
             WHERE tenant_id = :tenant_id
               AND event_type = "owner_changed"
               AND previous_owner_user_id IS NOT NULL
               AND occurred_at BETWEEN :start_at AND :end_at' . $outFilter . '
             GROUP BY previous_owner_user_id',
            ['tenant_id' => $tenantId, 'start_at' => $date['utc_start'], 'end_at' => $date['utc_end']] + $outParams
        ) as $row) {
            $id = (int) $row['user_id'];
            $rows[$id] = ($rows[$id] ?? ['user_id' => $id]) + $row;
        }
        return array_values($rows);
    }

    private function preferredClients(int $tenantId, int $userId): array
    {
        [$filter, $params] = $this->userFilter('preferred_user_id', $userId);
        return $this->rows(
            'SELECT preferred_user_id AS user_id, COUNT(*) AS preferred_clients
             FROM contacts
             WHERE tenant_id = :tenant_id
               AND preferred_user_id IS NOT NULL' . $filter . '
             GROUP BY preferred_user_id',
            ['tenant_id' => $tenantId] + $params
        );
    }

    private function overview(array $professionals, array $scope, int $selectedUserId): array
    {
        $overview = $this->emptyOverview();
        $overview['team_members'] = count($professionals);
        foreach ($professionals as $row) {
            foreach ([
                'human_messages', 'conversations_replied', 'first_responses', 'assignments',
                'transfers_received', 'transfers_out', 'releases', 'closed_conversations',
                'open_conversations', 'appointments', 'appointments_confirmed',
                'appointments_completed', 'appointments_cancelled', 'appointments_no_show',
                'appointments_upcoming', 'preferred_clients',
            ] as $key) {
                $overview[$key] += (int) ($row[$key] ?? 0);
            }
        }

        $weightedSeconds = 0;
        foreach ($professionals as $row) {
            $weightedSeconds += ((int) ($row['first_responses'] ?? 0)) * ((int) ($row['avg_first_response_seconds'] ?? 0));
        }
        $overview['avg_first_response_seconds'] = $overview['first_responses'] > 0
            ? (int) round($weightedSeconds / $overview['first_responses'])
            : 0;
        $overview['appointment_success_rate'] = $overview['appointments'] > 0
            ? (($overview['appointments_completed'] + $overview['appointments_confirmed']) / $overview['appointments']) * 100
            : 0.0;
        $attendanceBase = $overview['appointments_completed'] + $overview['appointments_cancelled'] + $overview['appointments_no_show'];
        $overview['attendance_rate'] = $attendanceBase > 0
            ? ($overview['appointments_completed'] / $attendanceBase) * 100
            : 0.0;
        $overview['scope_label'] = ($scope['mode'] ?? '') === 'own'
            ? 'Meus indicadores'
            : ($selectedUserId > 0 ? 'Profissional selecionado' : 'Toda a equipe');
        return $overview;
    }

    private function dailySeries(int $tenantId, array $filters, int $userId): array
    {
        $timezone = $this->tenantTimezone($tenantId);
        $date = $this->dateParams($filters, $timezone);
        $series = [];
        $start = new DateTimeImmutable((string) $filters['start']);
        $end = new DateTimeImmutable((string) $filters['end']);
        foreach (new DatePeriod($start, new DateInterval('P1D'), $end->modify('+1 day')) as $day) {
            $key = $day->format('Y-m-d');
            $series[$key] = [
                'date' => $key,
                'label' => $day->format('d/m'),
                'human_messages' => 0,
                'first_responses' => 0,
                'appointments' => 0,
                'completed' => 0,
            ];
        }

        [$messageFilter, $messageParams] = $this->userFilter('sender_user_id', $userId);
        foreach ($this->rows(
            'SELECT sent_at
             FROM conversation_messages
             WHERE tenant_id = :tenant_id
               AND direction = "outgoing" AND sender_type = "user"
               AND sent_at BETWEEN :start_at AND :end_at' . $messageFilter,
            ['tenant_id' => $tenantId, 'start_at' => $date['utc_start'], 'end_at' => $date['utc_end']] + $messageParams
        ) as $row) {
            $key = \App\Core\Clock::localDateKey((string) ($row['sent_at'] ?? ''), $timezone);
            if (isset($series[$key])) $series[$key]['human_messages']++;
        }

        [$responseFilter, $responseParams] = $this->userFilter('first_response_user_id', $userId);
        foreach ($this->rows(
            'SELECT first_response_at
             FROM conversation_service_cycles
             WHERE tenant_id = :tenant_id
               AND first_response_at BETWEEN :start_at AND :end_at' . $responseFilter,
            ['tenant_id' => $tenantId, 'start_at' => $date['utc_start'], 'end_at' => $date['utc_end']] + $responseParams
        ) as $row) {
            $key = \App\Core\Clock::localDateKey((string) ($row['first_response_at'] ?? ''), $timezone);
            if (isset($series[$key])) $series[$key]['first_responses']++;
        }

        [$appointmentFilter, $appointmentParams] = $this->userFilter('owner_user_id', $userId);
        foreach ($this->rows(
            'SELECT DATE(starts_at) AS metric_date, COUNT(*) AS appointments,
                    SUM(status = "completed") AS completed
             FROM calendar_appointments
             WHERE tenant_id = :tenant_id
               AND starts_at BETWEEN :start_at AND :end_at' . $appointmentFilter . '
             GROUP BY DATE(starts_at)',
            ['tenant_id' => $tenantId, 'start_at' => $date['local_start'], 'end_at' => $date['local_end']] + $appointmentParams
        ) as $row) {
            $key = (string) $row['metric_date'];
            if (isset($series[$key])) {
                $series[$key]['appointments'] = (int) $row['appointments'];
                $series[$key]['completed'] = (int) $row['completed'];
            }
        }

        return array_values($series);
    }

    private function responseDataQuality(int $tenantId, array $date, int $userId): array
    {
        [$measuredFilter, $measuredParams] = $this->userFilter('sc.first_response_user_id', $userId);
        $measured = $this->row(
            'SELECT COUNT(*) AS measured_responses,
                    AVG(GREATEST(0, TIMESTAMPDIFF(SECOND, sc.first_incoming_at, sc.first_response_at))) AS avg_response_seconds,
                    MIN(GREATEST(0, TIMESTAMPDIFF(SECOND, sc.first_incoming_at, sc.first_response_at))) AS min_response_seconds,
                    MAX(GREATEST(0, TIMESTAMPDIFF(SECOND, sc.first_incoming_at, sc.first_response_at))) AS max_response_seconds,
                    SUM(sc.first_response_at < sc.first_incoming_at) AS invalid_response_cycles
             FROM conversation_service_cycles sc
             WHERE sc.tenant_id = :tenant_id
               AND sc.first_incoming_at IS NOT NULL
               AND sc.first_response_at IS NOT NULL
               AND sc.first_response_user_id IS NOT NULL
               AND sc.first_response_at BETWEEN :start_at AND :end_at' . $measuredFilter,
            ['tenant_id' => $tenantId, 'start_at' => $date['utc_start'], 'end_at' => $date['utc_end']] + $measuredParams
        );

        $pendingFilter = '';
        $pendingParams = [];
        if ($userId > 0) {
            $pendingFilter = ' AND c.assigned_user_id = :pending_user_id';
            $pendingParams['pending_user_id'] = $userId;
        }
        $pending = $this->scalar(
            'SELECT COUNT(*)
             FROM conversation_service_cycles sc
             INNER JOIN conversations c ON c.id = sc.conversation_id AND c.tenant_id = sc.tenant_id
             WHERE sc.tenant_id = :tenant_id
               AND sc.cycle_status = "active"
               AND sc.first_incoming_at IS NOT NULL
               AND sc.first_response_at IS NULL
               AND sc.first_incoming_at BETWEEN :start_at AND :end_at' . $pendingFilter,
            ['tenant_id' => $tenantId, 'start_at' => $date['utc_start'], 'end_at' => $date['utc_end']] + $pendingParams
        );

        $quality = [
            'measured_responses' => (int) ($measured['measured_responses'] ?? 0),
            'avg_response_seconds' => (int) round((float) ($measured['avg_response_seconds'] ?? 0)),
            'min_response_seconds' => (int) ($measured['min_response_seconds'] ?? 0),
            'max_response_seconds' => (int) ($measured['max_response_seconds'] ?? 0),
            'pending_response_cycles' => $pending,
            'invalid_response_cycles' => (int) ($measured['invalid_response_cycles'] ?? 0),
        ];

        if ($quality['invalid_response_cycles'] > 0) {
            $this->warnings[] = 'Há ciclos com resposta anterior à entrada do cliente. Revise a auditoria de primeira resposta.';
        }
        return $quality;
    }

    private function firstResponseAudit(int $tenantId, array $date, int $userId, int $limit): array
    {
        [$filter, $params] = $this->userFilter('sc.first_response_user_id', $userId);
        $limit = max(1, min(5000, $limit));
        $rows = $this->rows(
            'SELECT sc.conversation_id, sc.cycle_number, sc.first_incoming_at, sc.first_response_at,
                    sc.first_response_user_id, sc.cycle_status, sc.source,
                    GREATEST(0, TIMESTAMPDIFF(SECOND, sc.first_incoming_at, sc.first_response_at)) AS response_seconds,
                    COALESCE(NULLIF(u.whatsapp_display_name, ""), u.name, "Usuário") AS professional_name,
                    COALESCE(NULLIF(ct.name, ""), ct.phone, "Cliente") AS contact_name
             FROM conversation_service_cycles sc
             INNER JOIN conversations c ON c.id = sc.conversation_id AND c.tenant_id = sc.tenant_id
             INNER JOIN contacts ct ON ct.id = c.contact_id AND ct.tenant_id = sc.tenant_id
             LEFT JOIN users u ON u.id = sc.first_response_user_id
             WHERE sc.tenant_id = :tenant_id
               AND sc.first_incoming_at IS NOT NULL
               AND sc.first_response_at IS NOT NULL
               AND sc.first_response_user_id IS NOT NULL
               AND sc.first_response_at BETWEEN :start_at AND :end_at' . $filter . '
             ORDER BY sc.first_response_at DESC
             LIMIT ' . $limit,
            ['tenant_id' => $tenantId, 'start_at' => $date['utc_start'], 'end_at' => $date['utc_end']] + $params
        );

        foreach ($rows as &$row) {
            $row['conversation_uuid'] = \App\Core\PublicId::encode('conversation', (int) ($row['conversation_id'] ?? 0));
            unset($row['conversation_id']);
            $row['first_incoming_at_local'] = \App\Core\Clock::utcToLocal((string) ($row['first_incoming_at'] ?? ''), $date['timezone']);
            $row['first_response_at_local'] = \App\Core\Clock::utcToLocal((string) ($row['first_response_at'] ?? ''), $date['timezone']);
            $row['response_seconds'] = (int) ($row['response_seconds'] ?? 0);
        }
        unset($row);
        return $rows;
    }

    private function recentActivities(int $tenantId, array $date, int $userId): array
    {
        $activities = [];
        [$assignedFilter, $assignedParams] = $this->activityUserFilter('h.assigned_user_id', 'h.previous_user_id', $userId);
        foreach ($this->rows(
            'SELECT h.occurred_at, "conversation_assignment" AS activity_type, h.action,
                    h.conversation_id AS record_id, h.assigned_user_id AS user_id,
                    u.name AS user_name, pu.name AS previous_user_name,
                    ct.name AS subject_name
             FROM conversation_assignment_history h
             LEFT JOIN users u ON u.id = h.assigned_user_id
             LEFT JOIN users pu ON pu.id = h.previous_user_id
             INNER JOIN conversations c ON c.id = h.conversation_id
             INNER JOIN contacts ct ON ct.id = c.contact_id
             WHERE h.tenant_id = :tenant_id
               AND h.occurred_at BETWEEN :start_at AND :end_at' . $assignedFilter . '
             ORDER BY h.occurred_at DESC LIMIT 30',
            ['tenant_id' => $tenantId, 'start_at' => $date['utc_start'], 'end_at' => $date['utc_end']] + $assignedParams
        ) as $row) {
            $row['description'] = $this->assignmentDescription($row);
            $row['occurred_at_local'] = \App\Core\Clock::utcToLocal((string) ($row['occurred_at'] ?? ''), $date['timezone']);
            $activities[] = $row;
        }

        [$appointmentFilter, $appointmentParams] = $this->activityUserFilter('h.owner_user_id', 'h.previous_owner_user_id', $userId);
        foreach ($this->rows(
            'SELECT h.occurred_at, "appointment" AS activity_type, h.event_type AS action,
                    h.appointment_id AS record_id, h.owner_user_id AS user_id,
                    u.name AS user_name, pu.name AS previous_user_name,
                    h.title_snapshot AS subject_name, h.status
             FROM calendar_appointment_history h
             LEFT JOIN users u ON u.id = h.owner_user_id
             LEFT JOIN users pu ON pu.id = h.previous_owner_user_id
             WHERE h.tenant_id = :tenant_id
               AND h.occurred_at BETWEEN :start_at AND :end_at' . $appointmentFilter . '
             ORDER BY h.occurred_at DESC LIMIT 30',
            ['tenant_id' => $tenantId, 'start_at' => $date['utc_start'], 'end_at' => $date['utc_end']] + $appointmentParams
        ) as $row) {
            $row['description'] = $this->appointmentDescription($row);
            $row['occurred_at_local'] = \App\Core\Clock::utcToLocal((string) ($row['occurred_at'] ?? ''), $date['timezone']);
            $activities[] = $row;
        }

        usort($activities, static fn (array $a, array $b): int => strcmp((string) $b['occurred_at'], (string) $a['occurred_at']));
        return array_slice($activities, 0, 30);
    }

    private function assignmentDescription(array $row): string
    {
        $subject = trim((string) ($row['subject_name'] ?? 'Cliente')) ?: 'Cliente';
        $user = trim((string) ($row['user_name'] ?? 'sem responsável')) ?: 'sem responsável';
        $previous = trim((string) ($row['previous_user_name'] ?? ''));
        return match ((string) ($row['action'] ?? '')) {
            'transfer' => $subject . ': transferido de ' . ($previous ?: 'outro responsável') . ' para ' . $user . '.',
            'release' => $subject . ': atendimento liberado por ' . ($previous ?: 'responsável anterior') . '.',
            'snapshot' => $subject . ': responsável atual registrado como ' . $user . '.',
            default => $subject . ': atendimento atribuído a ' . $user . '.',
        };
    }

    private function appointmentDescription(array $row): string
    {
        $title = trim((string) ($row['subject_name'] ?? 'Compromisso')) ?: 'Compromisso';
        $user = trim((string) ($row['user_name'] ?? 'sem profissional')) ?: 'sem profissional';
        $previous = trim((string) ($row['previous_user_name'] ?? ''));
        $action = (string) ($row['action'] ?? '');
        $status = (string) ($row['status'] ?? '');
        if ($action === 'status_changed') {
            return match ($status) {
                'confirmed' => $title . ': confirmado para ' . $user . '.',
                'completed' => $title . ': concluído por ' . $user . '.',
                'cancelled', 'rejected' => $title . ': cancelado.',
                'no_show' => $title . ': cliente não compareceu.',
                default => $title . ': status alterado para ' . str_replace('_', ' ', $status ?: 'atualizado') . '.',
            };
        }
        return match ($action) {
            'owner_changed' => $title . ': profissional alterado de ' . ($previous ?: 'não definido') . ' para ' . $user . '.',
            'rescheduled' => $title . ': horário alterado.',
            'created' => $title . ': compromisso criado para ' . $user . '.',
            'deleted' => $title . ': compromisso excluído.',
            'snapshot' => $title . ': estado inicial registrado.',
            default => $title . ': ' . str_replace('_', ' ', $action ?: 'atualizado') . '.',
        };
    }

    private function mergeRows(array &$professionals, array $rows): void
    {
        foreach ($rows as $row) {
            $id = (int) ($row['user_id'] ?? 0);
            if ($id < 1 || !isset($professionals[$id])) {
                continue;
            }
            foreach ($row as $key => $value) {
                if ($key === 'user_id') continue;
                $professionals[$id][$key] = is_numeric($value) ? (float) $value : $value;
            }
        }
    }

    private function userFilter(string $column, int $userId): array
    {
        if ($userId < 1) return ['', []];
        return [' AND ' . $column . ' = :filter_user_id', ['filter_user_id' => $userId]];
    }

    private function activityUserFilter(string $currentColumn, string $previousColumn, int $userId): array
    {
        if ($userId < 1) return ['', []];
        return [
            ' AND (' . $currentColumn . ' = :activity_current_user_id OR ' . $previousColumn . ' = :activity_previous_user_id)',
            ['activity_current_user_id' => $userId, 'activity_previous_user_id' => $userId],
        ];
    }

    private function tenantTimezone(int $tenantId): string
    {
        $row = $this->row(
            'SELECT COALESCE(NULLIF(os.business_timezone, ""), NULLIF(cas.timezone, ""), "America/Sao_Paulo") AS timezone
             FROM tenants t
             LEFT JOIN tenant_onboarding_settings os ON os.tenant_id = t.id
             LEFT JOIN tenant_calendar_availability_settings cas ON cas.tenant_id = t.id
             WHERE t.id = :tenant_id LIMIT 1',
            ['tenant_id' => $tenantId]
        );
        return \App\Core\Clock::safeTimezone((string) ($row['timezone'] ?? 'America/Sao_Paulo'));
    }

    private function dateParams(array $filters, string $timezone): array
    {
        $timezone = \App\Core\Clock::safeTimezone($timezone);
        $utc = \App\Core\Clock::localRangeToUtc((string) $filters['start'], (string) $filters['end'], $timezone);
        $nowLocal = (new \DateTimeImmutable('now', new \DateTimeZone($timezone)))->format('Y-m-d H:i:s');

        return [
            'utc_start' => $utc['start'],
            'utc_end' => $utc['end'],
            'local_start' => $filters['start'] . ' 00:00:00',
            'local_end' => $filters['end'] . ' 23:59:59',
            'now_local' => $nowLocal,
            'timezone' => $timezone,
        ];
    }

    private function emptyDataQuality(): array
    {
        return [
            'measured_responses' => 0,
            'avg_response_seconds' => 0,
            'min_response_seconds' => 0,
            'max_response_seconds' => 0,
            'pending_response_cycles' => 0,
            'invalid_response_cycles' => 0,
        ];
    }

    private function emptyOverview(): array
    {
        return [
            'team_members' => 0,
            'human_messages' => 0,
            'conversations_replied' => 0,
            'first_responses' => 0,
            'avg_first_response_seconds' => 0,
            'assignments' => 0,
            'transfers_received' => 0,
            'transfers_out' => 0,
            'releases' => 0,
            'closed_conversations' => 0,
            'open_conversations' => 0,
            'appointments' => 0,
            'appointments_confirmed' => 0,
            'appointments_completed' => 0,
            'appointments_cancelled' => 0,
            'appointments_no_show' => 0,
            'appointments_upcoming' => 0,
            'preferred_clients' => 0,
            'appointment_success_rate' => 0.0,
            'attendance_rate' => 0.0,
            'scope_label' => '',
        ];
    }

    private function roleLabel(string $role): string
    {
        return match ($role) {
            'client_admin' => 'Administrador',
            'super_admin' => 'Super Admin',
            default => 'Profissional',
        };
    }

    private function scalar(string $sql, array $params = []): int
    {
        try {
            $statement = $this->pdo->prepare($sql);
            $statement->execute($params);
            return (int) $statement->fetchColumn();
        } catch (Throwable $exception) {
            $this->warn($exception);
            return 0;
        }
    }

    private function row(string $sql, array $params = []): array
    {
        try {
            $statement = $this->pdo->prepare($sql);
            $statement->execute($params);
            return $statement->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $exception) {
            $this->warn($exception);
            return [];
        }
    }

    private function rows(string $sql, array $params = []): array
    {
        try {
            $statement = $this->pdo->prepare($sql);
            $statement->execute($params);
            return $statement->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $exception) {
            $this->warn($exception);
            return [];
        }
    }

    private function warn(Throwable $exception): void
    {
        error_log('[reports.team] ' . preg_replace('/\s+/', ' ', $exception->getMessage()));
        $this->warnings[] = 'Um indicador da equipe ficou temporariamente indisponível.';
    }
}
