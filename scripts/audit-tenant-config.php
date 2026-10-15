<?php

declare(strict_types=1);

/**
 * Somente leitura: php scripts/audit-tenant-config.php --tenant=123
 * Não exibe segredos, dados do cliente ou mensagens do WhatsApp.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require_once dirname(__DIR__) . '/bootstrap.php';

use App\Core\Database;
use App\Services\AgentReadinessService;

$options = getopt('', ['tenant:']);
$tenantId = filter_var($options['tenant'] ?? null, FILTER_VALIDATE_INT,
    ['options'=>['min_range'=>1]]);
if (!$tenantId) {
    fwrite(STDERR, "Uso: php scripts/audit-tenant-config.php --tenant=ID\n");
    exit(2);
}

try {
    $pdo = Database::connection();
    $stmt = $pdo->prepare('SELECT id FROM ai_agents WHERE tenant_id=:tid ORDER BY id');
    $stmt->execute(['tid'=>$tenantId]);
    $service = new AgentReadinessService($pdo);
    $reports = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $agentId) {
        $report = $service->audit((int) $tenantId, (int) $agentId);
        $reports[] = [
            'agent_id'=>$report['agent_id'],
            'can_operate'=>$report['can_operate'],
            'score'=>$report['readiness_score'],
            'consistency_issues'=>array_map(static fn (array $i) => [
                'key'=>$i['key'], 'severity'=>$i['severity'], 'owner'=>$i['owner'],
            ], $report['consistency']['issues'] ?? []),
        ];
    }
    echo json_encode(['tenant_id'=>(int) $tenantId, 'mode'=>'read_only', 'agents'=>$reports],
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $error) {
    error_log('[RS Connect][consistency-audit] ' . $error->getMessage());
    fwrite(STDERR, "Não foi possível concluir a auditoria. Confira conexão e migrations no log.\n");
    exit(1);
}
