<?php

declare(strict_types=1);

namespace App\Core;

final class Audit
{
    public static function log(string $action, array $context = [], ?int $tenantId = null): void
    {
        $statement = Database::connection()->prepare(
            'INSERT INTO audit_logs (tenant_id, user_id, action, context_json, ip_address)
             VALUES (:tenant_id, :user_id, :action, :context_json, :ip_address)'
        );
        $statement->execute([
            'tenant_id' => $tenantId ?? Auth::tenantId(),
            'user_id' => Auth::id(),
            'action' => $action,
            'context_json' => $context === []
                ? null
                : json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    }
}
