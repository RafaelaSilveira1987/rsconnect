<?php

declare(strict_types=1);

/**
 * Ensaio integrado de snapshot em MySQL com rollback garantido.
 * Exige uma pré-reserva real de TESTE em homologação vinculada a uma triagem.
 * Nunca executar contra base de produção ou com dados clínicos reais.
 */
if (getenv('QA_TARGET') !== 'homologation' || getenv('QA_ALLOW_DB') !== '1') {
    fwrite(STDERR, "BLOQUEADO: habilitar somente em homologação (QA_TARGET=homologation QA_ALLOW_DB=1).\n");
    exit(2);
}
require dirname(__DIR__, 2) . '/bootstrap.php';

use App\Core\Database;
use App\Core\Env;
use App\Services\AppointmentTriageSnapshotService;

$appEnv = strtolower(trim((string) Env::get('APP_ENV', '')));
$dbName = strtolower(trim((string) Env::get('DB_DATABASE', '')));
if (in_array($appEnv, ['production', 'prod'], true) || preg_match('/(prod|production)/i', $dbName)) {
    fwrite(STDERR, "BLOQUEADO: conexão aparentemente produtiva.\n");
    exit(2);
}
$pdo = Database::connection();
$table = $pdo->query(
    'SELECT COUNT(*) FROM information_schema.tables
     WHERE table_schema = DATABASE() AND table_name = "calendar_appointment_triage_snapshots"'
);
if ((int) $table->fetchColumn() !== 1) {
    fwrite(STDERR, "FALHA: aplicar migration 126 em homologação antes deste teste.\n");
    exit(1);
}
// Selecionar APENAS fixture explicitamente identificada como teste.
$statement = $pdo->query(
    'SELECT a.tenant_id, a.id AS appointment_id, a.conversation_id
     FROM calendar_appointments a
     INNER JOIN conversation_triage_sessions ts
        ON ts.tenant_id = a.tenant_id AND ts.conversation_id = a.conversation_id
     WHERE a.is_pre_schedule = 1 AND a.status IN ("pre_scheduled", "awaiting_approval")
       AND (a.title LIKE "%QA_FIXTURE%" OR a.title LIKE "%TESTE_AUTOMATIZADO%")
     ORDER BY a.id DESC LIMIT 1'
);
$fixture = $statement->fetch(PDO::FETCH_ASSOC);
if (!is_array($fixture)) {
    fwrite(STDERR, "FALHA: crie um pré-agendamento de teste com QA_FIXTURE no título e triagem ativa. Não alteramos dados reais.\n");
    exit(2);
}
$tenantId = (int) $fixture['tenant_id'];
$appointmentId = (int) $fixture['appointment_id'];
$conversationId = (int) $fixture['conversation_id'];
$service = new AppointmentTriageSnapshotService();
$pdo->beginTransaction();
$failure = null;
try {
    if (!$service->capture($pdo, $tenantId, $appointmentId, $conversationId)) {
        throw new RuntimeException('Capture recusou fixture válida');
    }
    $initial = $service->forAppointments($pdo, $tenantId, [$appointmentId]);
    if (!isset($initial[$appointmentId])) {
        throw new RuntimeException('Registro não localizado com tenant correto');
    }
    $otherTenant = $service->forAppointments($pdo, $tenantId + 99999, [$appointmentId]);
    if ($otherTenant !== []) {
        throw new RuntimeException('Vazamento entre empresas na leitura');
    }
    if (!$service->capture($pdo, $tenantId, $appointmentId, $conversationId, true)) {
        throw new RuntimeException('Falha ao congelar snapshot de teste');
    }
    $locked = $service->forAppointments($pdo, $tenantId, [$appointmentId]);
    if (empty($locked[$appointmentId]['locked_at'])) {
        throw new RuntimeException('Snapshot não foi congelado');
    }
    if (!$service->capture($pdo, $tenantId, $appointmentId, $conversationId)) {
        throw new RuntimeException('Segunda captura falhou');
    }
    $again = $service->forAppointments($pdo, $tenantId, [$appointmentId]);
    if ($locked[$appointmentId]['locked_at'] !== $again[$appointmentId]['locked_at']
        || $locked[$appointmentId]['collected_json'] !== $again[$appointmentId]['collected_json']) {
        throw new RuntimeException('Snapshot congelado foi alterado');
    }
    echo "[OK] Captura real MySQL, tenant, congelamento e leitura de teste.\n";
} catch (Throwable $error) {
    $failure = $error;
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
        echo "[OK] ROLLBACK: nenhuma alteração de teste persistida.\n";
    }
}
if ($failure instanceof Throwable) {
    fwrite(STDERR, '[FALHA] ' . $failure->getMessage() . "\n");
    exit(1);
}
