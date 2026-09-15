<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;

final class OperationalLoadService
{
    private PDO $pdo;
    private SlaPolicyService $sla;
    private TenantLifecycleService $lifecycle;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::connection();
        $this->sla = new SlaPolicyService($this->pdo);
        $this->lifecycle = new TenantLifecycleService($this->pdo);
    }

    /** @param array<string,mixed> $filters */
    public function build(int $tenantId, array $filters = []): array
    {
        if ($tenantId < 1) {
            return $this->emptyPayload();
        }

        $rows = $this->activeConversations($tenantId, $filters);
        $isLive = $this->lifecycle->isLive($tenantId);
        $prepared = [];
        foreach ($rows as $row) {
            $row['sla'] = null;
            $row['awaiting_first_response'] = false;
            $firstIncoming = trim((string) ($row['sla_first_incoming_at'] ?? ''));
            $firstResponse = trim((string) ($row['sla_first_response_at'] ?? ''));
            if ($firstIncoming !== '') {
                $row['awaiting_first_response'] = $firstResponse === '';
                if ($isLive) {
                    $policy = $this->sla->policyForCycle($tenantId, $row);
                    $row['sla'] = $this->sla->state(
                        $tenantId,
                        $firstIncoming,
                        $firstResponse !== '' ? $firstResponse : null,
                        $policy
                    );
                }
            }
            $prepared[] = $row;
        }

        $slaFilter = trim((string) ($filters['sla'] ?? ''));
        if ($slaFilter !== '') {
            $prepared = array_values(array_filter($prepared, static function (array $row) use ($slaFilter): bool {
                $sla = is_array($row['sla'] ?? null) ? $row['sla'] : null;
                return match ($slaFilter) {
                    'pending' => !empty($row['awaiting_first_response']),
                    'normal' => !empty($row['awaiting_first_response']) && ($sla['status'] ?? '') === 'normal',
                    'warning' => !empty($row['awaiting_first_response']) && ($sla['status'] ?? '') === 'warning',
                    'breached' => !empty($row['awaiting_first_response']) && ($sla['status'] ?? '') === 'breached',
                    default => true,
                };
            }));
        }

        $summary = [
            'active_total' => count($prepared),
            'unassigned' => 0,
            'human_active' => 0,
            'awaiting_first_response' => 0,
            'sla_warning' => 0,
            'sla_breached' => 0,
        ];
        $teamMap = [];
        foreach ($this->activeUsers($tenantId) as $user) {
            $id = (int) $user['id'];
            $teamMap[$id] = [
                'user_id' => $id,
                'name' => (string) $user['name'],
                'active' => 0,
                'human_active' => 0,
                'awaiting_first_response' => 0,
                'sla_warning' => 0,
                'sla_breached' => 0,
                'unread' => 0,
            ];
        }
        $unassigned = [
            'user_id' => 0,
            'name' => 'Sem responsável',
            'active' => 0,
            'human_active' => 0,
            'awaiting_first_response' => 0,
            'sla_warning' => 0,
            'sla_breached' => 0,
            'unread' => 0,
        ];

        foreach ($prepared as &$row) {
            $assignedId = (int) ($row['assigned_user_id'] ?? 0);
            $bucket = &$unassigned;
            if ($assignedId > 0) {
                if (!isset($teamMap[$assignedId])) {
                    $teamMap[$assignedId] = [
                        'user_id' => $assignedId,
                        'name' => (string) (($row['assigned_user_name'] ?? '') ?: 'Usuário #' . $assignedId),
                        'active' => 0,
                        'human_active' => 0,
                        'awaiting_first_response' => 0,
                        'sla_warning' => 0,
                        'sla_breached' => 0,
                        'unread' => 0,
                    ];
                }
                $bucket = &$teamMap[$assignedId];
            } else {
                $summary['unassigned']++;
            }

            $bucket['active']++;
            $bucket['unread'] += (int) ($row['unread_count'] ?? 0);
            if ((string) ($row['attendance_mode'] ?? '') === 'human' && $assignedId > 0) {
                $summary['human_active']++;
                $bucket['human_active']++;
            }
            if (!empty($row['awaiting_first_response'])) {
                $summary['awaiting_first_response']++;
                $bucket['awaiting_first_response']++;
                $slaStatus = (string) (($row['sla']['status'] ?? '') ?: 'normal');
                if ($slaStatus === 'warning') {
                    $summary['sla_warning']++;
                    $bucket['sla_warning']++;
                } elseif ($slaStatus === 'breached') {
                    $summary['sla_breached']++;
                    $bucket['sla_breached']++;
                }
            }

            $row['sla_label'] = $this->slaLabel($row);
            $row['sla_class'] = $this->slaClass($row);
            $row['sla_elapsed_label'] = $this->durationLabel((int) ($row['sla']['elapsed_seconds'] ?? 0));
            $row['sla_remaining_label'] = $this->durationLabel((int) ($row['sla']['remaining_seconds'] ?? 0));
            unset($bucket);
        }
        unset($row);

        if ($unassigned['active'] > 0 || trim((string) ($filters['assigned_user_id'] ?? '')) === 'unassigned') {
            array_unshift($teamMap, $unassigned);
        }
        $team = array_values($teamMap);
        usort($team, static function (array $a, array $b): int {
            $criticalA = ((int) $a['sla_breached'] * 10000) + ((int) $a['sla_warning'] * 1000) + ((int) $a['active'] * 10) + (int) $a['unread'];
            $criticalB = ((int) $b['sla_breached'] * 10000) + ((int) $b['sla_warning'] * 1000) + ((int) $b['active'] * 10) + (int) $b['unread'];
            return $criticalB <=> $criticalA ?: strcmp((string) $a['name'], (string) $b['name']);
        });

        usort($prepared, static function (array $a, array $b): int {
            $rank = static function (array $row): int {
                if (!empty($row['awaiting_first_response'])) {
                    return match ((string) ($row['sla']['status'] ?? 'normal')) {
                        'breached' => 4,
                        'warning' => 3,
                        default => 2,
                    };
                }
                return 1;
            };
            $comparison = $rank($b) <=> $rank($a);
            if ($comparison !== 0) {
                return $comparison;
            }
            return strcmp((string) ($b['last_message_at'] ?? ''), (string) ($a['last_message_at'] ?? ''));
        });

        $payload = [
            'summary' => $summary,
            'team' => $team,
            'conversations' => array_slice($prepared, 0, 100),
            'instances' => $this->instances($tenantId),
            'users' => $this->activeUsers($tenantId),
            'tenant_live' => $isLive,
            'generated_at' => gmdate('Y-m-d H:i:s'),
        ];
        $payload['fingerprint'] = hash('sha256', json_encode([$summary, $team, array_map(static fn (array $row): array => [
            (int) ($row['id'] ?? 0),
            (string) ($row['status'] ?? ''),
            (string) ($row['attendance_mode'] ?? ''),
            (int) ($row['assigned_user_id'] ?? 0),
            (int) ($row['unread_count'] ?? 0),
            (string) ($row['last_message_at'] ?? ''),
            (string) ($row['sla_class'] ?? ''),
        ], $prepared)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
        return $payload;
    }

    /** @param array<string,mixed> $filters */
    private function activeConversations(int $tenantId, array $filters): array
    {
        $conditions = ['c.tenant_id = :tenant_id', 'c.status IN ("open","pending")'];
        $params = ['tenant_id' => $tenantId];

        $status = trim((string) ($filters['status'] ?? ''));
        if (in_array($status, ['open', 'pending'], true)) {
            $conditions[] = 'c.status = :status';
            $params['status'] = $status;
        }
        $mode = trim((string) ($filters['mode'] ?? ''));
        if (in_array($mode, ['ai', 'human', 'paused'], true)) {
            $conditions[] = 'c.attendance_mode = :mode';
            $params['mode'] = $mode;
        }
        $instanceId = (int) ($filters['instance_id'] ?? 0);
        if ($instanceId > 0) {
            $conditions[] = 'c.evolution_instance_id = :instance_id';
            $params['instance_id'] = $instanceId;
        }
        $assigned = trim((string) ($filters['assigned_user_id'] ?? ''));
        if ($assigned === 'unassigned') {
            $conditions[] = 'c.assigned_user_id IS NULL';
        } elseif (ctype_digit($assigned) && (int) $assigned > 0) {
            $conditions[] = 'c.assigned_user_id = :assigned_user_id';
            $params['assigned_user_id'] = (int) $assigned;
        }

        $sql = 'SELECT c.id, c.tenant_id, c.evolution_instance_id, c.contact_id, c.status, c.attendance_mode,
                       c.assigned_user_id, c.unread_count, c.last_message_at, c.last_message_preview,
                       ct.name AS contact_name, ct.phone,
                       i.name AS instance_label, i.instance_name,
                       u.name AS assigned_user_name,
                       sc.id AS service_cycle_id, sc.first_incoming_at AS sla_first_incoming_at,
                       sc.first_response_at AS sla_first_response_at, sc.first_response_user_id,
                       sc.sla_target_minutes, sc.sla_warning_percent,
                       sc.sla_count_outside_business_hours, sc.sla_timezone, sc.sla_business_hours_json
                FROM conversations c
                INNER JOIN contacts ct ON ct.id = c.contact_id AND ct.tenant_id = c.tenant_id
                INNER JOIN evolution_instances i ON i.id = c.evolution_instance_id AND i.tenant_id = c.tenant_id
                LEFT JOIN users u ON u.id = c.assigned_user_id AND u.tenant_id = c.tenant_id
                LEFT JOIN conversation_service_cycles sc ON sc.id = (
                    SELECT MAX(active_cycle.id)
                    FROM conversation_service_cycles active_cycle
                    WHERE active_cycle.tenant_id = c.tenant_id
                      AND active_cycle.conversation_id = c.id
                      AND active_cycle.cycle_status = "active"
                )
                WHERE ' . implode(' AND ', $conditions) . '
                ORDER BY COALESCE(c.last_message_at, c.created_at) DESC';
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function activeUsers(int $tenantId): array
    {
        $statement = $this->pdo->prepare('SELECT id, name, role FROM users WHERE tenant_id = :tenant_id AND status = "active" ORDER BY name');
        $statement->execute(['tenant_id' => $tenantId]);
        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function instances(int $tenantId): array
    {
        $statement = $this->pdo->prepare('SELECT id, name, instance_name, status FROM evolution_instances WHERE tenant_id = :tenant_id ORDER BY is_default DESC, name');
        $statement->execute(['tenant_id' => $tenantId]);
        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function slaLabel(array $row): string
    {
        if (empty($row['awaiting_first_response'])) {
            return trim((string) ($row['sla_first_incoming_at'] ?? '')) === '' ? 'SLA ainda não iniciado' : '1ª resposta registrada';
        }
        return match ((string) ($row['sla']['status'] ?? 'normal')) {
            'warning' => 'SLA em risco',
            'breached' => 'SLA violado',
            default => 'Dentro do prazo',
        };
    }

    private function slaClass(array $row): string
    {
        if (empty($row['awaiting_first_response'])) {
            return 'resolved';
        }
        return match ((string) ($row['sla']['status'] ?? 'normal')) {
            'warning' => 'warning',
            'breached' => 'breached',
            default => 'normal',
        };
    }

    private function durationLabel(int $seconds): string
    {
        if ($seconds <= 0) {
            return '0 min';
        }
        if ($seconds < 60) {
            return $seconds . 's';
        }
        $minutes = intdiv($seconds, 60);
        $remaining = $seconds % 60;
        if ($minutes < 60) {
            return $remaining > 0 ? $minutes . 'min ' . $remaining . 's' : $minutes . ' min';
        }
        $hours = intdiv($minutes, 60);
        $mins = $minutes % 60;
        return $hours . 'h' . ($mins > 0 ? ' ' . $mins . 'min' : '');
    }

    private function emptyPayload(): array
    {
        return [
            'summary' => ['active_total' => 0, 'unassigned' => 0, 'human_active' => 0, 'awaiting_first_response' => 0, 'sla_warning' => 0, 'sla_breached' => 0],
            'team' => [], 'conversations' => [], 'instances' => [], 'users' => [], 'tenant_live' => false,
            'generated_at' => gmdate('Y-m-d H:i:s'), 'fingerprint' => '',
        ];
    }
}
