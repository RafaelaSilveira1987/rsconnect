<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Generates reversible, tamper-resistant public UUIDs for numeric database IDs.
 *
 * Internal primary and foreign keys remain numeric. Browser URLs receive an
 * opaque UUID-shaped token bound to an entity type. The APP_KEY is used to
 * encrypt and authenticate the payload, so sequential IDs are never exposed.
 */
final class PublicId
{
    private const CIPHER = 'aes-256-ecb';
    private const MAGIC = "RS";

    /** @var array<string,int> */
    private const ENTITY_CODES = [
        'tenant' => 1,
        'contact' => 2,
        'conversation' => 3,
        'instance' => 4,
        'user' => 5,
        'appointment' => 6,
        'agent' => 7,
        'lead' => 8,
        'opportunity' => 9,
        'campaign' => 10,
        'invoice' => 11,
        'flow' => 12,
        'communication' => 13,
        'routine' => 14,
        'incident' => 15,
        'plan' => 16,
        'subscription' => 17,
        'gateway' => 18,
        'task' => 19,
        'pipeline' => 20,
        'stage' => 21,
        'department' => 22,
        'privacy_request' => 23,
        'availability_slot' => 24,
        'activity' => 25,
        'prompt_version' => 26,
        'credential' => 27,
        'message' => 28,
        'backup_job' => 29,
        'notification' => 30,
        'recipient' => 31,
        'permission' => 32,
        'session' => 33,
        'record' => 34,
        'commercial_request' => 35,
    ];

    /**
     * Maps legacy/internal query parameters to public aliases and entity types.
     *
     * @var array<string,array{alias:string,entity:string}>
     */
    private const PARAMETER_MAP = [
        'tenant_id' => ['alias' => 'tenant_uuid', 'entity' => 'tenant'],
        'company_id' => ['alias' => 'company_uuid', 'entity' => 'tenant'],
        'contact_id' => ['alias' => 'contact_uuid', 'entity' => 'contact'],
        'conversation_id' => ['alias' => 'conversation_uuid', 'entity' => 'conversation'],
        'source_conversation_id' => ['alias' => 'source_conversation_uuid', 'entity' => 'conversation'],
        'instance_id' => ['alias' => 'instance_uuid', 'entity' => 'instance'],
        'evolution_instance_id' => ['alias' => 'evolution_instance_uuid', 'entity' => 'instance'],
        'replacement_instance_id' => ['alias' => 'replacement_instance_uuid', 'entity' => 'instance'],
        'user_id' => ['alias' => 'user_uuid', 'entity' => 'user'],
        'assigned_user_id' => ['alias' => 'assigned_user_uuid', 'entity' => 'user'],
        'owner_user_id' => ['alias' => 'owner_user_uuid', 'entity' => 'user'],
        'professional_user_id' => ['alias' => 'professional_uuid', 'entity' => 'user'],
        'preferred_user_id' => ['alias' => 'preferred_user_uuid', 'entity' => 'user'],
        'created_by_user_id' => ['alias' => 'created_by_user_uuid', 'entity' => 'user'],
        'approved_by_user_id' => ['alias' => 'approved_by_user_uuid', 'entity' => 'user'],
        'sender_user_id' => ['alias' => 'sender_user_uuid', 'entity' => 'user'],
        'owner_id' => ['alias' => 'owner_uuid', 'entity' => 'user'],
        'appointment_id' => ['alias' => 'appointment_uuid', 'entity' => 'appointment'],
        'agent_id' => ['alias' => 'agent_uuid', 'entity' => 'agent'],
        'ai_agent_id' => ['alias' => 'ai_agent_uuid', 'entity' => 'agent'],
        'lead_id' => ['alias' => 'lead_uuid', 'entity' => 'lead'],
        'crm_lead_id' => ['alias' => 'crm_lead_uuid', 'entity' => 'lead'],
        'opportunity_id' => ['alias' => 'opportunity_uuid', 'entity' => 'opportunity'],
        'campaign_id' => ['alias' => 'campaign_uuid', 'entity' => 'campaign'],
        'invoice_id' => ['alias' => 'invoice_uuid', 'entity' => 'invoice'],
        'flow_id' => ['alias' => 'flow_uuid', 'entity' => 'flow'],
        'communication_id' => ['alias' => 'communication_uuid', 'entity' => 'communication'],
        'routine_id' => ['alias' => 'routine_uuid', 'entity' => 'routine'],
        'incident_id' => ['alias' => 'incident_uuid', 'entity' => 'incident'],
        'plan_id' => ['alias' => 'plan_uuid', 'entity' => 'plan'],
        'subscription_id' => ['alias' => 'subscription_uuid', 'entity' => 'subscription'],
        'gateway_id' => ['alias' => 'gateway_uuid', 'entity' => 'gateway'],
        'payment_gateway_id' => ['alias' => 'payment_gateway_uuid', 'entity' => 'gateway'],
        'task_id' => ['alias' => 'task_uuid', 'entity' => 'task'],
        'pipeline_id' => ['alias' => 'pipeline_uuid', 'entity' => 'pipeline'],
        'stage_id' => ['alias' => 'stage_uuid', 'entity' => 'stage'],
        'department_id' => ['alias' => 'department_uuid', 'entity' => 'department'],
        'request_id' => ['alias' => 'request_uuid', 'entity' => 'privacy_request'],
        'commercial_request_id' => ['alias' => 'commercial_request_uuid', 'entity' => 'commercial_request'],
        'slot_id' => ['alias' => 'slot_uuid', 'entity' => 'availability_slot'],
        'chosen_availability_slot_id' => ['alias' => 'chosen_slot_uuid', 'entity' => 'availability_slot'],
        'activity_id' => ['alias' => 'activity_uuid', 'entity' => 'activity'],
        'version_id' => ['alias' => 'version_uuid', 'entity' => 'prompt_version'],
        'credential_id' => ['alias' => 'credential_uuid', 'entity' => 'credential'],
        'message_id' => ['alias' => 'message_uuid', 'entity' => 'message'],
        'stored_message_id' => ['alias' => 'stored_message_uuid', 'entity' => 'message'],
        'backup_job_id' => ['alias' => 'backup_job_uuid', 'entity' => 'backup_job'],
        'notification_id' => ['alias' => 'notification_uuid', 'entity' => 'notification'],
        'recipient_id' => ['alias' => 'recipient_uuid', 'entity' => 'recipient'],
        'permission_id' => ['alias' => 'permission_uuid', 'entity' => 'permission'],
        'session_id' => ['alias' => 'session_uuid', 'entity' => 'session'],
    ];

    /**
     * Path-specific query mappings for ambiguous parameters such as `id` or
     * `tenant`. These names cannot be mapped globally because some routes use
     * them for non-database values (for example /login?tenant=empresa-slug).
     *
     * @var array<string,array<string,array{alias:string,entity:string}>>
     */
    private const PATH_PARAMETER_MAP = [
        '/companies/overview' => [
            'id' => ['alias' => 'company_uuid', 'entity' => 'tenant'],
        ],
        '/company-settings' => [
            'id' => ['alias' => 'company_uuid', 'entity' => 'tenant'],
        ],
        '/companies/health' => [
            'id' => ['alias' => 'company_uuid', 'entity' => 'tenant'],
        ],
        '/calendar/ics' => [
            'id' => ['alias' => 'appointment_uuid', 'entity' => 'appointment'],
        ],
        '/central-operacao' => [
            'tenant' => ['alias' => 'tenant_uuid', 'entity' => 'tenant'],
        ],
    ];

    /** @var array<string,string> */
    private static array $encodeCache = [];

    /** @var array<string,int> */
    private static array $decodeCache = [];

    public static function encode(string $entity, int $id): string
    {
        if ($id <= 0) {
            return '';
        }

        $entityCode = self::ENTITY_CODES[$entity] ?? null;
        if ($entityCode === null) {
            throw new RuntimeException('Tipo de identificador público não suportado: ' . $entity);
        }

        $cacheKey = $entity . ':' . $id;
        if (isset(self::$encodeCache[$cacheKey])) {
            return self::$encodeCache[$cacheKey];
        }

        $high = intdiv($id, 4294967296);
        $low = $id % 4294967296;
        $body = self::MAGIC . chr($entityCode) . pack('N2', $high, $low);
        $plain = $body . substr(hash_hmac('sha256', $body, self::macKey(), true), 0, 5);

        $encrypted = openssl_encrypt(
            $plain,
            self::CIPHER,
            self::encryptionKey(),
            OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING
        );
        if ($encrypted === false || strlen($encrypted) !== 16) {
            throw new RuntimeException('Não foi possível gerar o identificador público.');
        }

        // Present the encrypted token as a valid RFC 4122 version-4 UUID.
        $bytes = array_values(unpack('C*', $encrypted));
        $bytes[6] = ($bytes[6] & 0x0F) | 0x40;
        $bytes[8] = ($bytes[8] & 0x3F) | 0x80;
        $uuid = self::formatUuid(pack('C*', ...$bytes));

        self::$encodeCache[$cacheKey] = $uuid;
        self::$decodeCache[$entity . ':' . $uuid] = $id;
        return $uuid;
    }

    public static function decode(string $entity, string $uuid): ?int
    {
        $uuid = strtolower(trim($uuid));
        if (!self::isUuid($uuid)) {
            return null;
        }

        $entityCode = self::ENTITY_CODES[$entity] ?? null;
        if ($entityCode === null) {
            return null;
        }

        $cacheKey = $entity . ':' . $uuid;
        if (isset(self::$decodeCache[$cacheKey])) {
            return self::$decodeCache[$cacheKey];
        }

        $binary = hex2bin(str_replace('-', '', $uuid));
        if ($binary === false || strlen($binary) !== 16) {
            return null;
        }

        $visible = array_values(unpack('C*', $binary));

        // Six encrypted bits were normalized to UUID version/variant bits.
        // Try the 64 original combinations and accept only a valid MAC payload.
        for ($versionBits = 0; $versionBits < 16; $versionBits++) {
            for ($variantBits = 0; $variantBits < 4; $variantBits++) {
                $candidate = $visible;
                $candidate[6] = ($candidate[6] & 0x0F) | ($versionBits << 4);
                $candidate[8] = ($candidate[8] & 0x3F) | ($variantBits << 6);
                $cipherText = pack('C*', ...$candidate);
                $plain = openssl_decrypt(
                    $cipherText,
                    self::CIPHER,
                    self::encryptionKey(),
                    OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING
                );
                if ($plain === false || strlen($plain) !== 16) {
                    continue;
                }

                $body = substr($plain, 0, 11);
                $mac = substr($plain, 11, 5);
                if (!str_starts_with($body, self::MAGIC) || ord($body[2]) !== $entityCode) {
                    continue;
                }
                $expected = substr(hash_hmac('sha256', $body, self::macKey(), true), 0, 5);
                if (!hash_equals($expected, $mac)) {
                    continue;
                }

                $parts = unpack('Nhigh/Nlow', substr($body, 3, 8));
                if (!is_array($parts)) {
                    return null;
                }
                $id = ((int) $parts['high'] * 4294967296) + (int) $parts['low'];
                if ($id <= 0 || $id > PHP_INT_MAX) {
                    return null;
                }

                self::$decodeCache[$cacheKey] = $id;
                self::$encodeCache[$entity . ':' . $id] = $uuid;
                return $id;
            }
        }

        return null;
    }

    public static function publicizePath(string $path): string
    {
        $path = trim($path);
        if ($path === '' || preg_match('~^https?://~i', $path) === 1 || str_starts_with($path, '//')) {
            return $path;
        }

        $parts = parse_url($path);
        if ($parts === false) {
            return $path;
        }

        $routePath = (string) ($parts['path'] ?? '/');
        $query = [];
        if (isset($parts['query'])) {
            parse_str((string) $parts['query'], $query);
        }

        $query = self::publicizeQuery($routePath, $query);
        $result = $routePath === '' ? '/' : $routePath;
        if ($query !== []) {
            $result .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }
        if (isset($parts['fragment']) && $parts['fragment'] !== '') {
            $result .= '#' . $parts['fragment'];
        }

        return $result;
    }

    /**
     * Converts public query aliases back into the internal numeric parameters
     * expected by existing controllers.
     */
    public static function hydrateRequest(string $routePath): bool
    {
        // Public identifiers are used in URLs/query strings. POST bodies keep
        // their existing internal field contract so ordinary fields such as
        // "message", "plan" or "owner" can never be mistaken for UUIDs.
        $ok = self::hydrateBag($_GET, $routePath);
        $_REQUEST = array_merge($_REQUEST, $_GET);
        return $ok;
    }

    public static function hasLegacyPublicQuery(string $routePath, array $query): bool
    {
        foreach (self::PARAMETER_MAP as $internal => $definition) {
            if (isset($query[$internal]) && self::positiveInteger($query[$internal]) !== null) {
                return true;
            }
            $alias = $definition['alias'];
            if (isset($query[$alias]) && self::positiveInteger($query[$alias]) !== null) {
                return true;
            }
        }

        foreach (self::PATH_PARAMETER_MAP[$routePath] ?? [] as $internal => $definition) {
            if (isset($query[$internal]) && self::positiveInteger($query[$internal]) !== null) {
                return true;
            }
            if (isset($query[$definition['alias']]) && self::positiveInteger($query[$definition['alias']]) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns the entity type bound to an internal request parameter.
     *
     * This is used by the tenant-isolation guard after public UUIDs have been
     * hydrated back to the numeric IDs expected by legacy controllers.
     */
    public static function entityForParameter(string $parameter, string $routePath = '/'): ?string
    {
        $routePath = '/' . trim($routePath, '/');
        if ($routePath === '//') {
            $routePath = '/';
        }

        if (isset(self::PATH_PARAMETER_MAP[$routePath][$parameter])) {
            return self::PATH_PARAMETER_MAP[$routePath][$parameter]['entity'];
        }

        return self::PARAMETER_MAP[$parameter]['entity'] ?? null;
    }

    /** @return array<string,string> */
    public static function requestParameterEntities(string $routePath = '/'): array
    {
        $entities = [];
        foreach (self::PARAMETER_MAP as $parameter => $definition) {
            $entities[$parameter] = $definition['entity'];
            // POST bodies are intentionally not hydrated by hydrateRequest().
            // Expose public aliases here so TenantIsolationService validates
            // UUID fields such as commercial_request_uuid before controllers run.
            $entities[$definition['alias']] = $definition['entity'];
        }
        foreach (self::PATH_PARAMETER_MAP['/' . trim($routePath, '/')] ?? [] as $parameter => $definition) {
            $entities[$parameter] = $definition['entity'];
            $entities[$definition['alias']] = $definition['entity'];
        }

        return $entities;
    }

    public static function isUuid(string $value): bool
    {
        return preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            trim($value)
        ) === 1;
    }

    /** @param array<string,mixed> $query */
    private static function publicizeQuery(string $routePath, array $query): array
    {
        foreach (self::PARAMETER_MAP as $internal => $definition) {
            $alias = $definition['alias'];
            $entity = $definition['entity'];

            if (array_key_exists($internal, $query)) {
                $id = self::positiveInteger($query[$internal]);
                if ($id !== null) {
                    $query[$alias] = self::encode($entity, $id);
                    unset($query[$internal]);
                }
            }

            if (array_key_exists($alias, $query)) {
                $id = self::positiveInteger($query[$alias]);
                if ($id !== null) {
                    $query[$alias] = self::encode($entity, $id);
                }
            }
        }

        foreach (self::PATH_PARAMETER_MAP[$routePath] ?? [] as $internal => $definition) {
            if (array_key_exists($internal, $query)) {
                $id = self::positiveInteger($query[$internal]);
                if ($id !== null) {
                    $query[$definition['alias']] = self::encode($definition['entity'], $id);
                    unset($query[$internal]);
                }
            }

            if (array_key_exists($definition['alias'], $query)) {
                $id = self::positiveInteger($query[$definition['alias']]);
                if ($id !== null) {
                    $query[$definition['alias']] = self::encode($definition['entity'], $id);
                }
            }
        }

        return $query;
    }

    /** @param array<string,mixed> $bag */
    private static function hydrateBag(array &$bag, string $routePath): bool
    {
        // Resolve path-specific aliases first. Some public names intentionally
        // match a global alias (for example tenant_uuid), while the legacy
        // controller parameter on that route is `tenant` or `id`.
        foreach (self::PATH_PARAMETER_MAP[$routePath] ?? [] as $internal => $definition) {
            $alias = $definition['alias'];
            if (!array_key_exists($alias, $bag)) {
                continue;
            }

            $value = $bag[$alias];
            if (is_scalar($value) && trim((string) $value) === '') {
                unset($bag[$alias]);
                continue;
            }

            $numeric = self::positiveInteger($value);
            $decoded = $numeric ?? (is_scalar($value) ? self::decode($definition['entity'], (string) $value) : null);
            if ($decoded === null) {
                return false;
            }
            $bag[$internal] = $decoded;
            unset($bag[$alias]);
        }

        foreach (self::PARAMETER_MAP as $internal => $definition) {
            $alias = $definition['alias'];
            if (!array_key_exists($alias, $bag)) {
                continue;
            }

            $value = $bag[$alias];
            if (is_scalar($value) && trim((string) $value) === '') {
                unset($bag[$alias]);
                continue;
            }

            $numeric = self::positiveInteger($value);
            if ($numeric !== null) {
                $bag[$internal] = $numeric;
                unset($bag[$alias]);
                continue;
            }

            $decoded = is_scalar($value) ? self::decode($definition['entity'], (string) $value) : null;
            if ($decoded === null) {
                return false;
            }
            $bag[$internal] = $decoded;
            unset($bag[$alias]);
        }

        return true;
    }

    private static function positiveInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (!is_string($value) || preg_match('/^[1-9][0-9]*$/', $value) !== 1) {
            return null;
        }
        $id = (int) $value;
        return $id > 0 ? $id : null;
    }

    private static function formatUuid(string $binary): string
    {
        $hex = bin2hex($binary);
        return substr($hex, 0, 8) . '-'
            . substr($hex, 8, 4) . '-'
            . substr($hex, 12, 4) . '-'
            . substr($hex, 16, 4) . '-'
            . substr($hex, 20, 12);
    }

    private static function encryptionKey(): string
    {
        return hash('sha256', 'rs-connect-public-id-encryption|' . self::normalizedAppKey(), true);
    }

    private static function macKey(): string
    {
        return hash('sha256', 'rs-connect-public-id-authentication|' . self::normalizedAppKey(), true);
    }

    private static function normalizedAppKey(): string
    {
        $appKey = (string) Env::get('APP_KEY', '');
        if ($appKey === '') {
            throw new RuntimeException('APP_KEY não configurada. Identificadores públicos indisponíveis.');
        }

        if (str_starts_with($appKey, 'base64:')) {
            $decoded = base64_decode(substr($appKey, 7), true);
            if ($decoded !== false) {
                return $decoded;
            }
        }

        return $appKey;
    }
}
