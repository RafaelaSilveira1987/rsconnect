<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Core\PublicId;
use PDO;
use Throwable;

/**
 * Central fail-closed tenant boundary for authenticated browser requests.
 *
 * Controllers keep their own tenant predicates as the first line of defence.
 * This guard adds a second, route-independent boundary so a user cannot alter
 * a numeric hidden field or reuse a valid UUID that belongs to another tenant.
 */
final class TenantIsolationService
{
    /** @var array<string,string> */
    private const DIRECT_TABLES = [
        'user' => 'users',
        'contact' => 'contacts',
        'conversation' => 'conversations',
        'instance' => 'evolution_instances',
        'appointment' => 'calendar_appointments',
        'agent' => 'ai_agents',
        'lead' => 'crm_leads',
        'opportunity' => 'admin_crm_opportunities',
        'campaign' => 'message_campaigns',
        'invoice' => 'tenant_invoices',
        'flow' => 'n8n_tenant_flows',
        'task' => 'crm_tasks',
        'pipeline' => 'crm_pipelines',
        'stage' => 'crm_stages',
        'department' => 'service_departments',
        'privacy_request' => 'privacy_requests',
        'availability_slot' => 'calendar_availability_slots',
        'prompt_version' => 'ai_agent_prompt_versions',
        'credential' => 'ai_provider_credentials',
        'message' => 'conversation_messages',
        'notification' => 'client_notifications',
        'recipient' => 'client_communication_recipients',
        'subscription' => 'tenant_subscriptions',
    ];

    /** @var array<string,string> */
    private const PLURAL_PARAMETERS = [
        'conversation_ids' => 'conversation',
        'agent_ids' => 'agent',
    ];

    /** @var array<string,bool> */
    private array $cache = [];

    /**
     * @return array{allowed:bool,violations:list<array{parameter:string,entity:string,id:int,source:string}>}
     */
    public function validateAuthenticatedRequest(string $routePath, array $query, array $post): array
    {
        if (!Auth::check() || Auth::isSuperAdmin()) {
            return ['allowed' => true, 'violations' => []];
        }

        $tenantId = (int) (Auth::tenantId() ?? 0);
        if ($tenantId < 1) {
            return [
                'allowed' => false,
                'violations' => [[
                    'parameter' => 'auth.tenant_id',
                    'entity' => 'tenant',
                    'id' => 0,
                    'source' => 'session',
                ]],
            ];
        }

        $parameterEntities = PublicId::requestParameterEntities($routePath) + self::PLURAL_PARAMETERS;
        $violations = [];
        foreach ([['source' => 'query', 'bag' => $query], ['source' => 'post', 'bag' => $post]] as $requestBag) {
            foreach ($parameterEntities as $parameter => $entity) {
                if (!array_key_exists($parameter, $requestBag['bag'])) {
                    continue;
                }

                foreach ($this->extractIds($requestBag['bag'][$parameter], $entity) as $id) {
                    if (!$this->belongsToTenant($entity, $id, $tenantId)) {
                        $violations[] = [
                            'parameter' => $parameter,
                            'entity' => $entity,
                            'id' => $id,
                            'source' => $requestBag['source'],
                        ];
                    }
                }
            }
        }

        return ['allowed' => $violations === [], 'violations' => $violations];
    }

    /** @return list<int> */
    private function extractIds(mixed $value, string $entity): array
    {
        if (is_array($value)) {
            $ids = [];
            foreach ($value as $item) {
                $ids = array_merge($ids, $this->extractIds($item, $entity));
            }
            return array_values(array_unique($ids));
        }

        if (!is_scalar($value)) {
            return [];
        }

        $normalized = trim((string) $value);
        if ($normalized === '' || $normalized === '0') {
            return [];
        }
        if (preg_match('/^[1-9][0-9]*$/', $normalized) === 1) {
            return [(int) $normalized];
        }

        $decoded = PublicId::decode($entity, $normalized);
        return $decoded !== null ? [$decoded] : [];
    }

    private function belongsToTenant(string $entity, int $id, int $tenantId): bool
    {
        if ($id < 1) {
            return false;
        }
        if ($entity === 'tenant') {
            return $id === $tenantId;
        }

        $cacheKey = $entity . ':' . $id . ':' . $tenantId;
        if (array_key_exists($cacheKey, $this->cache)) {
            return $this->cache[$cacheKey];
        }

        try {
            $allowed = match ($entity) {
                'communication' => $this->exists(
                    'SELECT 1 FROM client_communication_recipients WHERE communication_id = :id AND tenant_id = :tenant_id LIMIT 1',
                    $id,
                    $tenantId
                ),
                'session' => $this->exists(
                    'SELECT 1 FROM user_sessions s INNER JOIN users u ON u.id = s.user_id WHERE s.id = :id AND u.tenant_id = :tenant_id LIMIT 1',
                    $id,
                    $tenantId
                ),
                default => isset(self::DIRECT_TABLES[$entity])
                    ? $this->exists(
                        'SELECT 1 FROM ' . self::DIRECT_TABLES[$entity] . ' WHERE id = :id AND tenant_id = :tenant_id LIMIT 1',
                        $id,
                        $tenantId
                    )
                    // Global/system-only entities remain governed by route permissions.
                    : true,
            };
        } catch (Throwable) {
            // Recognized tenant-owned entities fail closed if the ownership
            // lookup is unavailable; unsupported global entities are skipped.
            $allowed = !isset(self::DIRECT_TABLES[$entity])
                && !in_array($entity, ['communication', 'session'], true);
        }

        $this->cache[$cacheKey] = $allowed;
        return $allowed;
    }

    private function exists(string $sql, int $id, int $tenantId): bool
    {
        $statement = Database::connection()->prepare($sql);
        $statement->bindValue(':id', $id, PDO::PARAM_INT);
        $statement->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
        $statement->execute();
        return (bool) $statement->fetchColumn();
    }
}
