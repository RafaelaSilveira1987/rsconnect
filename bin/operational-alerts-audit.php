#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\Core\Database;
use App\Core\Env;
use App\Services\AccessControlService;
use App\Services\OperationalAlertService;

$sendTest = in_array('--send-test', $argv, true);
$pdo = Database::connection();
$alerts = new OperationalAlertService();

echo "RS CONNECT — AUDITOR DE AVISOS OPERACIONAIS\n";
echo str_repeat('=', 72) . "\n\n";

echo "=== 1. TRANSPORTE WHATSAPP ADMINISTRATIVO ===\n";
$env = [
    'OPERATIONS_ALERT_EVOLUTION_URL' => Env::get('OPERATIONS_ALERT_EVOLUTION_URL', Env::get('EVOLUTION_DEFAULT_URL', '')),
    'OPERATIONS_ALERT_EVOLUTION_API_KEY' => Env::get('OPERATIONS_ALERT_EVOLUTION_API_KEY', Env::get('EVOLUTION_DEFAULT_API_KEY', '')),
    'OPERATIONS_ALERT_EVOLUTION_INSTANCE' => Env::get('OPERATIONS_ALERT_EVOLUTION_INSTANCE', ''),
    'OPERATIONS_HEALTH_DIGEST_ENABLED' => Env::get('OPERATIONS_HEALTH_DIGEST_ENABLED', true),
    'OPERATIONS_HEALTH_DIGEST_TIME' => Env::get('OPERATIONS_HEALTH_DIGEST_TIME', '08:00'),
];
foreach ($env as $key => $value) {
    if (str_contains($key, 'API_KEY')) {
        echo ($value !== '' ? '[OK] ' : '[ERRO] ') . $key . ($value !== '' ? ' configurada (valor protegido)' : ' ausente') . "\n";
        continue;
    }
    echo (($value !== '' && $value !== false) ? '[OK] ' : '[ERRO] ') . $key . '=' . (is_bool($value) ? ($value ? 'true' : 'false') : (string) $value) . "\n";
}

echo "\n=== 2. SUPER ADMINS / PREFERÊNCIAS ===\n";
$admins = $pdo->query("SELECT id,name,email FROM users WHERE role='super_admin' AND status='active' ORDER BY id")
    ->fetchAll(PDO::FETCH_ASSOC) ?: [];
if ($admins === []) {
    echo "[ERRO] Nenhum super admin ativo encontrado.\n";
}
foreach ($admins as $admin) {
    $userId = (int) $admin['id'];
    $prefs = $alerts->preferences($userId);
    $phone = preg_replace('/\D+/', '', (string) ($prefs['whatsapp_recipient'] ?? '')) ?: '';
    $masked = $phone !== '' ? str_repeat('*', max(0, strlen($phone) - 4)) . substr($phone, -4) : 'não configurado';
    echo sprintf(
        "#%d %s | WhatsApp=%s | destino=%s | alertas warning=%s | rotinas=%s\n",
        $userId,
        (string) $admin['name'],
        !empty($prefs['whatsapp_enabled']) ? 'ATIVO' : 'DESATIVADO',
        $masked,
        !empty($prefs['warning_enabled']) ? 'SIM' : 'NÃO',
        !empty($prefs['routines_enabled']) ? 'SIM' : 'NÃO'
    );

    if ($sendTest) {
        echo "  Teste de entrega solicitado...\n";
        $result = $alerts->testConfiguredChannels($userId);
        foreach ($result as $channel => $delivery) {
            echo '  - ' . $channel . ': ' . (!empty($delivery['ok']) ? '[OK] ' : '[ERRO] ') . (string) ($delivery['message'] ?? '') . "\n";
        }
    }
}

echo "\n=== 3. EMPRESAS COM ACESSO BLOQUEADO ===\n";
try {
    $access = new AccessControlService();
    $summary = $access->securitySummary();
    $blocked = is_array($summary['blocked_tenants'] ?? null) ? $summary['blocked_tenants'] : [];
    if ($blocked === []) {
        echo "[OK] Nenhuma empresa bloqueada pelas regras atuais.\n";
    }
    foreach ($blocked as $tenant) {
        $tenantId = (int) ($tenant['id'] ?? 0);
        if ($tenantId < 1) continue;
        $status = $access->statusForTenant($tenantId);
        if (!empty($status['allowed'])) continue;
        echo sprintf(
            "[ATENÇÃO] #%d %s | %s | %s\n",
            $tenantId,
            (string) ($status['tenant_name'] ?? $tenant['name'] ?? 'Empresa'),
            (string) ($status['code'] ?? 'blocked'),
            (string) ($status['title'] ?? 'Acesso bloqueado')
        );
    }
} catch (Throwable $e) {
    echo '[ERRO] Não foi possível auditar bloqueios: ' . $e->getMessage() . "\n";
}

echo "\n=== 4. ÚLTIMOS ENVIOS / ERROS ===\n";
try {
    $rows = $pdo->query(
        "SELECT d.id,d.notification_kind,d.channel,d.status,d.destination,d.error_message,d.last_attempt_at,i.event
         FROM operational_alert_deliveries d
         LEFT JOIN system_incidents i ON i.id=d.incident_id
         ORDER BY d.id DESC LIMIT 15"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if ($rows === []) echo "Nenhuma entrega registrada.\n";
    foreach ($rows as $row) {
        echo sprintf(
            "#%d | %s | %s | %s | %s%s\n",
            (int) $row['id'],
            (string) $row['channel'],
            (string) $row['notification_kind'],
            (string) $row['status'],
            (string) ($row['last_attempt_at'] ?? ''),
            !empty($row['error_message']) ? ' | erro=' . mb_substr((string) $row['error_message'], 0, 180) : ''
        );
    }
} catch (Throwable $e) {
    echo '[ERRO] Histórico indisponível: ' . $e->getMessage() . "\n";
}

echo "\n" . str_repeat('=', 72) . "\n";
echo $sendTest
    ? "[OK] Auditoria concluída com teste explícito dos canais configurados.\n"
    : "[OK] Auditoria somente leitura concluída. Use --send-test para testar entrega.\n";
