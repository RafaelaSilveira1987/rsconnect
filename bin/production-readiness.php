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
    $liveReceivers = (int) $scalar($pdo, "SELECT COUNT(*) FROM evolution_instances ei INNER JOIN tenants t ON t.id=ei.tenant_id WHERE ei.receive_messages=1 AND t.lifecycle_status='live'");
    $unhealthyLive = (int) $scalar($pdo, "SELECT COUNT(*) FROM evolution_instances ei INNER JOIN tenants t ON t.id=ei.tenant_id WHERE ei.receive_messages=1 AND t.lifecycle_status='live' AND (ei.status <> 'connected' OR COALESCE(ei.connection_state,'') <> 'open' OR ei.identity_status <> 'verified' OR COALESCE(ei.reconciliation_status,'unknown') NOT IN ('healthy','corrected'))");
    $nonLiveReceivers = (int) $scalar($pdo, "SELECT COUNT(*) FROM evolution_instances ei INNER JOIN tenants t ON t.id=ei.tenant_id WHERE ei.receive_messages=1 AND t.lifecycle_status <> 'live'");
    $unhealthyNonLive = (int) $scalar($pdo, "SELECT COUNT(*) FROM evolution_instances ei INNER JOIN tenants t ON t.id=ei.tenant_id WHERE ei.receive_messages=1 AND t.lifecycle_status <> 'live' AND (ei.status <> 'connected' OR COALESCE(ei.connection_state,'') <> 'open' OR ei.identity_status <> 'verified' OR COALESCE(ei.reconciliation_status,'unknown') NOT IN ('healthy','corrected'))");

    if ($liveReceivers < 1) {
        $push('WARN', 'Evolution / WhatsApp LIVE', 'nenhuma instância receptora em tenant LIVE');
    } else {
        $push($unhealthyLive === 0 ? 'OK' : 'BLOCK', 'Evolution / WhatsApp LIVE', $liveReceivers . ' instância(s) receptora(s) em tenant LIVE; ' . $unhealthyLive . ' pendente(s)');
    }

    if ($nonLiveReceivers > 0) {
        $push('INFO', 'Evolution fora de produção', $nonLiveReceivers . ' instância(s) receptora(s) em ONBOARDING/READY/SUSPENDED; ' . $unhealthyNonLive . ' pendente(s), sem bloquear a release');
    }
} catch (Throwable $e) {
    $push('BLOCK', 'Evolution / WhatsApp LIVE', 'falha na validação: ' . $e->getMessage());
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
    $openLive = (int) $scalar($pdo, "SELECT COUNT(*) FROM conversations c INNER JOIN tenants t ON t.id=c.tenant_id WHERE t.lifecycle_status='live' AND c.status IN ('open','pending')");
    $unassignedLive = (int) $scalar($pdo, "SELECT COUNT(*) FROM conversations c INNER JOIN tenants t ON t.id=c.tenant_id WHERE t.lifecycle_status='live' AND c.status IN ('open','pending') AND c.assigned_user_id IS NULL");
    $openNonLive = (int) $scalar($pdo, "SELECT COUNT(*) FROM conversations c INNER JOIN tenants t ON t.id=c.tenant_id WHERE t.lifecycle_status <> 'live' AND c.status IN ('open','pending')");
    $push('OK', 'Carga operacional LIVE', $openLive . ' conversa(s) ativa(s); ' . $unassignedLive . ' sem responsável (indicador operacional, não bloqueio)');
    if ($openNonLive > 0) {
        $push('INFO', 'Carga fora de produção', $openNonLive . ' conversa(s) aberta(s)/pendente(s) em tenants não LIVE, fora do indicador produtivo');
    }
} catch (Throwable $e) {
    $push('WARN', 'Carga operacional LIVE', 'não foi possível consultar: ' . $e->getMessage());
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
