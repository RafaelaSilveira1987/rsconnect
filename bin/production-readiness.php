<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\Core\Database;
use App\Services\AppVersionService;
use App\Services\HealthCheckService;

$pdo = Database::connection();
$rows = [];
$blocked = 0;
$warnings = 0;

$push = static function (string $status, string $name, string $detail) use (&$rows, &$blocked, &$warnings): void {
    $status = strtoupper($status);
    $rows[] = [$status, $name, $detail];
    if ($status === 'BLOCK') {
        $blocked++;
    } elseif ($status === 'WARN') {
        $warnings++;
    }
};

$scalar = static function (PDO $pdo, string $sql): mixed {
    $statement = $pdo->query($sql);
    return $statement !== false ? $statement->fetchColumn() : false;
};

try {
    $health = (new HealthCheckService())->readinessDetails();
    $healthOk = ($health['status'] ?? '') === 'ok';
    $details = implode(', ', array_map(
        static fn (array $c): string => ($c['name'] ?? '?') . '=' . ($c['status'] ?? '?'),
        $health['checks'] ?? []
    ));
    $push($healthOk ? 'OK' : 'BLOCK', 'Readiness da aplicação', $details !== '' ? $details : 'sem detalhes');
} catch (Throwable $e) {
    $push('BLOCK', 'Readiness da aplicação', 'falha ao executar: ' . $e->getMessage());
}

try {
    $required = AppVersionService::REQUIRED_MIGRATION;
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM schema_migrations WHERE migration = :migration');
    $stmt->execute(['migration' => $required]);
    $applied = (int) $stmt->fetchColumn() === 1;
    $push($applied ? 'OK' : 'BLOCK', 'Migration obrigatória', $required . ($applied ? ' aplicada' : ' NÃO aplicada'));
} catch (Throwable $e) {
    $push('BLOCK', 'Migration obrigatória', 'não foi possível consultar schema_migrations: ' . $e->getMessage());
}

try {
    $live = (int) $scalar($pdo, "SELECT COUNT(*) FROM tenants WHERE lifecycle_status = 'live'");
    if ($live < 1) {
        $push('WARN', 'Go-Live', 'nenhuma empresa está LIVE; válido apenas se o ambiente ainda for de homologação');
    } else {
        $missingSla = (int) $scalar($pdo, "SELECT COUNT(*) FROM tenants t LEFT JOIN tenant_sla_settings s ON s.tenant_id=t.id WHERE t.lifecycle_status='live' AND s.tenant_id IS NULL");
        $push($missingSla === 0 ? 'OK' : 'BLOCK', 'Go-Live + SLA', $live . ' tenant(s) LIVE; ' . $missingSla . ' sem política de SLA');
    }
} catch (Throwable $e) {
    $push('BLOCK', 'Go-Live + SLA', 'falha na validação: ' . $e->getMessage());
}

try {
    $total = (int) $scalar($pdo, 'SELECT COUNT(*) FROM evolution_instances WHERE receive_messages = 1');
    $unhealthy = (int) $scalar($pdo, "SELECT COUNT(*) FROM evolution_instances WHERE receive_messages=1 AND (status <> 'connected' OR COALESCE(connection_state,'') <> 'open' OR identity_status <> 'verified' OR COALESCE(reconciliation_status,'unknown') NOT IN ('healthy','corrected'))");
    if ($total < 1) {
        $push('WARN', 'Evolution / WhatsApp', 'nenhuma instância com receive_messages=1');
    } else {
        $push($unhealthy === 0 ? 'OK' : 'BLOCK', 'Evolution / WhatsApp', $total . ' instância(s) receptoras; ' . $unhealthy . ' com estado/identidade/reconciliação pendente');
    }
} catch (Throwable $e) {
    $push('BLOCK', 'Evolution / WhatsApp', 'falha na validação: ' . $e->getMessage());
}

try {
    $failed = (int) $scalar($pdo, "SELECT COUNT(*) FROM webhook_security_events WHERE source='evolution' AND status='failed' AND last_received_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 60 MINUTE)");
    $processingOld = (int) $scalar($pdo, "SELECT COUNT(*) FROM webhook_security_events WHERE source='evolution' AND status='processing' AND last_attempt_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 10 MINUTE)");
    $status = ($processingOld > 0) ? 'BLOCK' : (($failed > 0) ? 'WARN' : 'OK');
    $push($status, 'Webhooks Evolution', $failed . ' falha(s) na última hora; ' . $processingOld . ' processamento(s) travado(s) > 10 min');
} catch (Throwable $e) {
    $push('BLOCK', 'Webhooks Evolution', 'falha na validação: ' . $e->getMessage());
}

try {
    $open = (int) $scalar($pdo, "SELECT COUNT(*) FROM conversations WHERE status IN ('open','pending')");
    $unassigned = (int) $scalar($pdo, "SELECT COUNT(*) FROM conversations WHERE status IN ('open','pending') AND assigned_user_id IS NULL");
    $push('OK', 'Carga operacional', $open . ' conversa(s) ativa(s); ' . $unassigned . ' sem responsável (indicador operacional, não bloqueio)');
} catch (Throwable $e) {
    $push('WARN', 'Carga operacional', 'não foi possível consultar: ' . $e->getMessage());
}

try {
    $storageLog = dirname(__DIR__) . '/storage/logs/evolution-webhook.log';
    $detail = is_file($storageLog) ? 'log evolution-webhook disponível' : 'log evolution-webhook ainda não criado';
    $push('OK', 'Observabilidade', $detail . '; diagnósticos e reconciliação habilitados pelo pacote');
} catch (Throwable $e) {
    $push('WARN', 'Observabilidade', $e->getMessage());
}

echo PHP_EOL . AppVersionService::PACKAGE_LABEL . PHP_EOL;
echo str_repeat('=', 78) . PHP_EOL;
foreach ($rows as [$status, $name, $detail]) {
    printf('[%-5s] %-24s %s%s', $status, $name, $detail, PHP_EOL);
}
echo str_repeat('-', 78) . PHP_EOL;
if ($blocked > 0) {
    echo "RESULTADO: BLOQUEADO ({$blocked} bloqueio(s), {$warnings} atenção(ões))." . PHP_EOL;
    echo "Corrija os bloqueios antes do Go-Live/release candidate." . PHP_EOL;
    exit(2);
}
if ($warnings > 0) {
    echo "RESULTADO: PRONTO COM ATENÇÃO ({$warnings} atenção(ões), nenhum bloqueio)." . PHP_EOL;
    echo "Revise as atenções e registre a evidência no roteiro de homologação." . PHP_EOL;
    exit(0);
}
echo "RESULTADO: PRONTO PARA HOMOLOGAÇÃO FINAL (sem bloqueios ou atenções)." . PHP_EOL;
exit(0);
