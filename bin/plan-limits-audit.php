<?php

declare(strict_types=1);

use App\Core\Autoloader;
use App\Core\Database;
use App\Core\Env;
use App\Services\SubscriptionService;

$root = dirname(__DIR__);
require_once $root . '/app/Core/Autoloader.php';
Autoloader::register($root . '/app');
Env::load($root . '/.env');

$failures = 0;
$checks = 0;

$check = static function (bool $ok, string $label, string $detail = '') use (&$failures, &$checks): void {
    $checks++;
    echo ($ok ? '[OK] ' : '[ERRO] ') . $label;
    if ($detail !== '') {
        echo ' — ' . $detail;
    }
    echo PHP_EOL;
    if (!$ok) {
        $failures++;
    }
};

try {
    $pdo = Database::connection();

    echo "RS CONNECT — HOMOLOGAÇÃO DE PLANOS E LIMITES\n";
    echo str_repeat('=', 72) . "\n";

    echo "\n=== 1. MATRIZ COMERCIAL NO BANCO ===\n";
    $rows = $pdo->query(
        "SELECT id, plan_key, name, monthly_price, own_ai_monthly_price, rs_ai_monthly_price,
                commitment_discounts_json, limits_json, features_json, status, is_default
         FROM saas_plans
         WHERE plan_key IN ('starter','pro','business','custom')
         ORDER BY sort_order, id"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $byKey = [];
    foreach ($rows as $row) {
        $byKey[(string) $row['plan_key']] = $row;
    }

    $expected = [
        'starter' => ['name' => 'Inicial', 'own' => 69.00, 'rs' => 99.00, 'users' => 3, 'instances' => 1, 'agents' => 1, 'ai' => 1500],
        'pro' => ['name' => 'Profissional', 'own' => 129.00, 'rs' => 179.00, 'users' => 6, 'instances' => 2, 'agents' => 2, 'ai' => 8000],
        'business' => ['name' => 'Empresarial', 'own' => 259.00, 'rs' => 349.00, 'users' => 15, 'instances' => 5, 'agents' => 5, 'ai' => 30000],
    ];

    foreach ($expected as $key => $exp) {
        $row = $byKey[$key] ?? null;
        $check($row !== null, "Plano {$key} existe");
        if (!$row) {
            continue;
        }

        $limits = json_decode((string) ($row['limits_json'] ?? '{}'), true);
        $discounts = json_decode((string) ($row['commitment_discounts_json'] ?? '{}'), true);
        $limits = is_array($limits) ? $limits : [];
        $discounts = is_array($discounts) ? $discounts : [];
        $aiLimit = $limits['ai_interactions_month'] ?? $limits['messages_month'] ?? $limits['ai_replies_month'] ?? null;

        $check((string) $row['status'] === 'active', "{$exp['name']} ativo");
        $check(abs((float) $row['own_ai_monthly_price'] - $exp['own']) < 0.001, "{$exp['name']} — IA própria", 'R$ ' . number_format((float) $row['own_ai_monthly_price'], 2, ',', '.'));
        $check(abs((float) $row['rs_ai_monthly_price'] - $exp['rs']) < 0.001, "{$exp['name']} — IA RS Connect", 'R$ ' . number_format((float) $row['rs_ai_monthly_price'], 2, ',', '.'));
        $check((int) ($limits['users'] ?? -1) === $exp['users'], "{$exp['name']} — usuários", (string) ($limits['users'] ?? 'ausente'));
        $check((int) ($limits['instances'] ?? -1) === $exp['instances'], "{$exp['name']} — canais", (string) ($limits['instances'] ?? 'ausente'));
        $check((int) ($limits['agents'] ?? -1) === $exp['agents'], "{$exp['name']} — agentes", (string) ($limits['agents'] ?? 'ausente'));
        $check((int) $aiLimit === $exp['ai'], "{$exp['name']} — franquia IA", (string) ($aiLimit ?? 'ausente'));
        $check((float) ($discounts['3'] ?? -1) === 0.0, "{$exp['name']} — compromisso 3 meses");
        $check((float) ($discounts['6'] ?? -1) === 8.0, "{$exp['name']} — compromisso 6 meses");
        $check((float) ($discounts['12'] ?? -1) === 15.0, "{$exp['name']} — compromisso 12 meses");
    }

    $check(isset($byKey['custom']), 'Plano Personalizado existe');

    echo "\n=== 2. ENFORCEMENT NO CÓDIGO ===\n";
    $sourceChecks = [
        $root . '/app/Controllers/UserController.php' => ["ensureCanCreate((int) \$tenantId, 'users')" => 'Usuários ativos'],
        $root . '/app/Controllers/InstanceController.php' => ["ensureCanCreate(\$tenantId, 'instances')" => 'Canais WhatsApp'],
        $root . '/app/Controllers/AgentController.php' => ["ensureCanCreate(\$tenantId, 'agents')" => 'Agentes de IA'],
        $root . '/app/Controllers/N8nFlowController.php' => ["ensureCanCreate(\$tenantId, 'n8n_flows')" => 'Automações n8n'],
        $root . '/app/Controllers/CalendarController.php' => ["ensureCanCreate(\$tenantId, 'appointments_month')" => 'Agendamentos'],
        $root . '/app/Services/AiUsageService.php' => ["ai_interactions_month" => 'Franquia IA RS Connect', 'plan_billable' => 'Consumo faturável de IA'],
    ];

    foreach ($sourceChecks as $file => $needles) {
        $content = is_file($file) ? (string) file_get_contents($file) : '';
        foreach ($needles as $needle => $label) {
            $check($content !== '' && str_contains($content, $needle), "Bloqueio aplicado: {$label}");
        }
    }

    echo "\n=== 3. TESTE TRANSACIONAL DO MOTOR DE LIMITES ===\n";
    $tenant = $pdo->query(
        "SELECT ts.tenant_id, ts.plan_id, t.name AS tenant_name
         FROM tenant_subscriptions ts
         INNER JOIN tenants t ON t.id = ts.tenant_id
         WHERE t.status = 'active'
           AND ts.billing_status IN ('trialing','active','overdue','suspended')
         ORDER BY ts.id DESC
         LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);

    if (!$tenant) {
        $check(false, 'Empresa elegível para teste transacional', 'nenhuma encontrada');
    } else {
        $tenantId = (int) $tenant['tenant_id'];
        $planId = (int) $tenant['plan_id'];
        $service = new SubscriptionService();
        $usage = $service->usageForTenant($tenantId);

        echo 'Empresa usada: #' . $tenantId . ' ' . (string) $tenant['tenant_name'] . PHP_EOL;
        echo 'Uso atual: users=' . (int) ($usage['users'] ?? 0)
            . ', instances=' . (int) ($usage['instances'] ?? 0)
            . ', agents=' . (int) ($usage['agents'] ?? 0)
            . ', ai=' . (int) ($usage['ai_interactions_month'] ?? 0) . PHP_EOL;

        $pdo->beginTransaction();
        try {
            $setLimits = $pdo->prepare(
                "UPDATE saas_plans
                 SET limits_json = JSON_SET(
                    COALESCE(limits_json, JSON_OBJECT()),
                    '$.users', :users,
                    '$.instances', :instances,
                    '$.agents', :agents,
                    '$.ai_interactions_month', :ai
                 )
                 WHERE id = :id"
            );

            $setLimits->execute([
                'users' => (int) ($usage['users'] ?? 0),
                'instances' => (int) ($usage['instances'] ?? 0),
                'agents' => (int) ($usage['agents'] ?? 0),
                'ai' => (int) ($usage['ai_interactions_month'] ?? 0),
                'id' => $planId,
            ]);

            foreach (['users', 'instances', 'agents', 'ai_interactions_month'] as $key) {
                $decision = $service->ensureCanCreate($tenantId, $key);
                $check(empty($decision['ok']), "Limite bloqueia ao atingir teto: {$key}");
            }

            $setLimits->execute([
                'users' => (int) ($usage['users'] ?? 0) + 1,
                'instances' => (int) ($usage['instances'] ?? 0) + 1,
                'agents' => (int) ($usage['agents'] ?? 0) + 1,
                'ai' => (int) ($usage['ai_interactions_month'] ?? 0) + 1,
                'id' => $planId,
            ]);

            foreach (['users', 'instances', 'agents', 'ai_interactions_month'] as $key) {
                $decision = $service->ensureCanCreate($tenantId, $key);
                $check(!empty($decision['ok']), "Limite libera abaixo do teto: {$key}");
            }
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
        $check(!$pdo->inTransaction(), 'ROLLBACK concluído — produção não alterada');
    }

    echo "\n=== 4. RESULTADO ===\n";
    echo "Checks: {$checks} | Falhas: {$failures}\n";
    if ($failures === 0) {
        echo "[APROVADO] PLANOS E LIMITES HOMOLOGADOS NO MOTOR E NO BANCO.\n";
        exit(0);
    }

    echo "[FALHA] Existem divergências em planos/limites.\n";
    exit(1);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, '[ERRO] ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
