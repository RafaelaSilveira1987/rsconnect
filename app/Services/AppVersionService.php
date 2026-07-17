<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Env;
use PDO;
use Throwable;

final class AppVersionService
{
    public const VERSION_LABEL = 'Beta Comercial 1.0';
    public const PACKAGE_LABEL = 'ZIP 33.1';
    public const REQUIRED_MIGRATION = '036_security_access_enforcement.sql';

    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::connection();
    }

    public function dashboard(): array
    {
        $checks = $this->checks();
        $ok = count(array_filter($checks, static fn (array $check): bool => ($check['status'] ?? '') === 'ok'));
        $warning = count(array_filter($checks, static fn (array $check): bool => ($check['status'] ?? '') === 'warning'));
        $blocked = count(array_filter($checks, static fn (array $check): bool => ($check['status'] ?? '') === 'blocked'));
        $score = count($checks) > 0 ? (int) round(($ok / count($checks)) * 100) : 0;

        return [
            'version' => self::VERSION_LABEL,
            'package' => self::PACKAGE_LABEL,
            'required_migration' => self::REQUIRED_MIGRATION,
            'status_label' => $this->statusLabel($score, $blocked),
            'score' => $score,
            'ok' => $ok,
            'warning' => $warning,
            'blocked' => $blocked,
            'checks' => $checks,
            'environment' => $this->environment(),
            'modules' => $this->modules(),
            'deploy' => $this->deployInfo(),
            'next_actions' => $this->nextActions($checks),
        ];
    }

    private function checks(): array
    {
        $checks = [];

        $checks[] = $this->check(
            'Banco de dados',
            $this->databaseOk() ? 'ok' : 'blocked',
            $this->databaseOk() ? 'Conexão ativa com o banco configurado.' : 'Não foi possível consultar o banco de dados.',
            'Conferir DB_HOST, DB_DATABASE, DB_USERNAME e DB_PASSWORD no ambiente.'
        );

        $migrationTables = [
            'tenant_implementation_status',
            'tenant_implementation_checklist',
            'tenant_onboarding_progress',
            'operations_backup_routines',
            'operations_backup_jobs',
            'system_backups',
            'system_health_checks',
            'tenant_calendar_availability_settings',
            'calendar_availability_requests',
            'calendar_availability_slots',
            'calendar_google_sync_logs',
            'tenant_notification_preferences',
            'tenant_admin_tracking',
        ];
        $missingTables = array_values(array_filter($migrationTables, fn (string $table): bool => !$this->tableExists($table)));
        $checks[] = $this->check(
            'Migrations centrais',
            count($missingTables) === 0 ? 'ok' : 'blocked',
            count($missingTables) === 0 ? 'Estrutura principal até o ZIP 33.1 encontrada.' : 'Tabelas ausentes: ' . implode(', ', $missingTables),
            'Rodar as migrations pendentes até a 036, conforme o pacote implantado.'
        );

        $appKey = (string) Env::get('APP_KEY', '');
        $checks[] = $this->check(
            'APP_KEY',
            $appKey !== '' ? 'ok' : 'blocked',
            $appKey !== '' ? 'Chave da aplicação configurada.' : 'APP_KEY vazio.',
            'Não trocar APP_KEY em produção sem plano, pois ela protege dados criptografados.'
        );

        $appUrl = (string) Env::get('APP_URL', '');
        $checks[] = $this->check(
            'APP_URL',
            str_starts_with($appUrl, 'https://') ? 'ok' : ($appUrl !== '' ? 'warning' : 'blocked'),
            $appUrl !== '' ? 'APP_URL atual: ' . $appUrl : 'APP_URL não configurado.',
            'Usar a URL pública HTTPS do RS Connect no EasyPanel.'
        );

        $evolutionUrl = (string) Env::get('EVOLUTION_DEFAULT_URL', '');
        $instances = $this->countWhere('evolution_instances', "status IN ('connected','open','active','online')");
        $checks[] = $this->check(
            'Evolution/WhatsApp',
            ($evolutionUrl !== '' || $instances > 0) ? 'ok' : 'warning',
            $instances . ' instância(s) conectada(s); URL padrão ' . ($evolutionUrl !== '' ? 'configurada.' : 'não configurada.'),
            'Manter pelo menos uma instância conectada e webhook apontando para o RS Connect.'
        );

        $openAiKey = (string) Env::get('OPENAI_API_KEY', '');
        $aiCredentials = $this->countWhere('ai_provider_credentials', "status = 'active'");
        $checks[] = $this->check(
            'IA/OpenAI',
            ($openAiKey !== '' || $aiCredentials > 0) ? 'ok' : 'warning',
            $aiCredentials . ' credencial(is) ativa(s) no banco; chave global ' . ($openAiKey !== '' ? 'presente.' : 'ausente.'),
            'Usar credenciais por empresa/agente ou uma chave global segura.'
        );

        $messages24 = $this->number("SELECT COUNT(*) FROM conversation_messages WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)");
        $checks[] = $this->check(
            'Conversas/webhooks',
            $messages24 > 0 ? 'ok' : 'warning',
            $messages24 . ' mensagem(ns) nas últimas 24 horas.',
            'Enviar e receber mensagem de teste em uma instância real.'
        );

        $backupToken = (string) (Env::get('OPERATIONS_BACKUP_TOKEN', '') ?: Env::get('BACKUP_WEBHOOK_TOKEN', ''));
        $routineOk = $this->countWhere('operations_backup_routines', "status = 'active' AND last_success_at IS NOT NULL");
        $checks[] = $this->check(
            'Backup automático',
            ($backupToken !== '' && $routineOk > 0) ? 'ok' : 'warning',
            ($backupToken !== '' ? 'Token configurado; ' : 'Token pendente; ') . $routineOk . ' rotina(s) validada(s).',
            'Manter OPERATIONS_BACKUP_TOKEN no EasyPanel e rotina n8n validada com callback.'
        );

        $healthDown = $this->latestHealthCount(['down']);
        $healthWarning = $this->latestHealthCount(['warning']);
        $checks[] = $this->check(
            'Monitoramento',
            $healthDown === 0 ? ($healthWarning <= 2 ? 'ok' : 'warning') : 'blocked',
            $healthWarning . ' aviso(s) e ' . $healthDown . ' falha(s) no último ciclo.',
            'Abrir Monitoramento, resolver falhas e revisar avisos recorrentes.'
        );

        $implementationAvg = $this->number('SELECT ROUND(AVG(percent_complete)) FROM tenant_implementation_status');
        $checks[] = $this->check(
            'Implantação comercial',
            $implementationAvg >= 70 ? 'ok' : ($implementationAvg > 0 ? 'warning' : 'blocked'),
            'Média atual de implantação: ' . $implementationAvg . '%.',
            'Usar Implantação para finalizar pendências por cliente.'
        );

        $privacy = $this->countTable('tenant_privacy_settings') + $this->countTable('privacy_settings');
        $checks[] = $this->check(
            'LGPD/Privacidade',
            $privacy > 0 ? 'ok' : 'warning',
            $privacy > 0 ? 'Configuração LGPD localizada.' : 'Nenhuma configuração LGPD localizada.',
            'Conferir termos, política e aceite obrigatório por empresa.'
        );

        $billing = $this->countTable('saas_plans') + $this->countTable('payment_gateways');
        $checks[] = $this->check(
            'Cobrança',
            $billing > 0 ? 'ok' : 'warning',
            $billing . ' registro(s) entre planos/gateways detectado(s).',
            'Manter planos e régua de cobrança definidos para operação comercial.'
        );

        $onboarding = $this->countTable('tenant_onboarding_progress');
        $checks[] = $this->check(
            'Onboarding do cliente',
            $this->tableExists('tenant_onboarding_progress') ? 'ok' : 'warning',
            $this->tableExists('tenant_onboarding_progress') ? $onboarding . ' registro(s) de onboarding.' : 'Tabela de onboarding ausente.',
            'Liberar Primeiros passos para clientes novos.'
        );

        return $checks;
    }

    private function environment(): array
    {
        return [
            ['label' => 'Ambiente', 'value' => (string) Env::get('APP_ENV', 'não informado'), 'secret' => false],
            ['label' => 'Debug', 'value' => (string) Env::get('APP_DEBUG', 'não informado'), 'secret' => false],
            ['label' => 'APP_URL', 'value' => (string) Env::get('APP_URL', 'não informado'), 'secret' => false],
            ['label' => 'Timezone', 'value' => (string) Env::get('APP_TIMEZONE', 'America/Sao_Paulo'), 'secret' => false],
            ['label' => 'Evolution URL', 'value' => (string) Env::get('EVOLUTION_DEFAULT_URL', 'não informado'), 'secret' => false],
            ['label' => 'OpenAI base URL', 'value' => (string) Env::get('OPENAI_API_BASE_URL', 'não informado'), 'secret' => false],
            ['label' => 'n8n base URL', 'value' => (string) Env::get('N8N_BASE_URL', 'não informado'), 'secret' => false],
            ['label' => 'Backup token', 'value' => $this->masked((string) (Env::get('OPERATIONS_BACKUP_TOKEN', '') ?: Env::get('BACKUP_WEBHOOK_TOKEN', ''))), 'secret' => true],
            ['label' => 'OpenAI key', 'value' => $this->masked((string) Env::get('OPENAI_API_KEY', '')), 'secret' => true],
        ];
    }

    private function modules(): array
    {
        return [
            ['name' => 'Empresas', 'count' => $this->countWhere('tenants', "status = 'active'"), 'url' => '/companies'],
            ['name' => 'Conversas 24h', 'count' => $this->number("SELECT COUNT(*) FROM conversation_messages WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"), 'url' => '/conversations'],
            ['name' => 'Assistentes IA', 'count' => $this->countWhere('ai_agents', "status = 'active'"), 'url' => '/agents'],
            ['name' => 'Fluxos n8n', 'count' => $this->countWhere('n8n_tenant_flows', "status = 'active'") + $this->countWhere('n8n_flows', "status = 'active'"), 'url' => '/n8n-flows'],
            ['name' => 'Backups automáticos', 'count' => $this->number("SELECT COUNT(*) FROM system_backups WHERE backup_type = 'automatic' AND status = 'success'"), 'url' => '/backup-automatico'],
            ['name' => 'Alertas ativos', 'count' => $this->activeIncidentCount(), 'url' => '/monitoramento'],
        ];
    }

    private function deployInfo(): array
    {
        $base = dirname(__DIR__, 2);
        $files = [
            $base . '/bootstrap.php',
            $base . '/routes/web.php',
            $base . '/app/Views/layouts/app.php',
        ];
        $latest = null;
        foreach ($files as $file) {
            if (is_file($file)) {
                $mtime = filemtime($file) ?: null;
                if ($mtime !== null && ($latest === null || $mtime > $latest)) {
                    $latest = $mtime;
                }
            }
        }

        return [
            'php_version' => PHP_VERSION,
            'package' => self::PACKAGE_LABEL,
            'version' => self::VERSION_LABEL,
            'last_file_update' => $latest ? date('Y-m-d H:i:s', $latest) : 'não identificado',
            'public_url' => (string) Env::get('APP_URL', ''),
        ];
    }

    private function nextActions(array $checks): array
    {
        $actions = [];
        foreach ($checks as $check) {
            if (($check['status'] ?? '') !== 'ok') {
                $actions[] = [
                    'label' => $check['label'] ?? 'Ajuste',
                    'action' => $check['action'] ?? 'Revisar configuração.',
                    'status' => $check['status'] ?? 'warning',
                ];
            }
        }

        if ($actions === []) {
            $actions[] = ['label' => 'Operação', 'action' => 'Sistema pronto para beta operacional. Manter monitoramento diário e backup validado.', 'status' => 'ok'];
        }

        return array_slice($actions, 0, 8);
    }

    private function statusLabel(int $score, int $blocked): string
    {
        if ($blocked > 0) {
            return 'Beta 1.0 com bloqueios';
        }
        if ($score >= 90) {
            return 'Beta 1.0 operacional';
        }
        if ($score >= 70) {
            return 'Beta 1.0 em validação';
        }
        return 'Beta 1.0 em preparação';
    }

    private function check(string $label, string $status, string $message, string $action): array
    {
        return compact('label', 'status', 'message', 'action');
    }

    private function masked(string $value): string
    {
        if ($value === '') {
            return 'não configurado';
        }
        if (strlen($value) <= 10) {
            return substr($value, 0, 2) . '***';
        }
        return substr($value, 0, 6) . '...' . substr($value, -4);
    }

    private function databaseOk(): bool
    {
        try {
            return (int) $this->pdo->query('SELECT 1')->fetchColumn() === 1;
        } catch (Throwable) {
            return false;
        }
    }

    private function latestHealthCount(array $statuses): int
    {
        if (!$this->tableExists('system_health_checks')) {
            return 0;
        }
        try {
            $placeholders = implode(',', array_fill(0, count($statuses), '?'));
            $sql = "SELECT COUNT(*) FROM system_health_checks h
                    INNER JOIN (
                        SELECT check_key, MAX(id) AS max_id
                        FROM system_health_checks
                        GROUP BY check_key
                    ) latest ON latest.max_id = h.id
                    WHERE h.status IN ({$placeholders})";
            $statement = $this->pdo->prepare($sql);
            $statement->execute($statuses);
            return (int) $statement->fetchColumn();
        } catch (Throwable) {
            return 0;
        }
    }

    private function activeIncidentCount(): int
    {
        if (!$this->tableExists('system_incidents')) {
            return 0;
        }
        return $this->number('SELECT COUNT(*) FROM system_incidents WHERE resolved_at IS NULL AND severity IN (\'warning\',\'critical\')');
    }

    private function number(string $sql): int
    {
        try {
            return (int) ($this->pdo->query($sql)->fetchColumn() ?: 0);
        } catch (Throwable) {
            return 0;
        }
    }

    private function countTable(string $table): int
    {
        if (!$this->tableExists($table)) {
            return 0;
        }
        return $this->number('SELECT COUNT(*) FROM `' . str_replace('`', '', $table) . '`');
    }

    private function countWhere(string $table, string $where): int
    {
        if (!$this->tableExists($table)) {
            return 0;
        }
        try {
            return (int) $this->pdo->query('SELECT COUNT(*) FROM `' . str_replace('`', '', $table) . '` WHERE ' . $where)->fetchColumn();
        } catch (Throwable) {
            return 0;
        }
    }

    private function tableExists(string $table): bool
    {
        try {
            $statement = $this->pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table');
            $statement->execute(['table' => $table]);
            return (int) $statement->fetchColumn() > 0;
        } catch (Throwable) {
            return false;
        }
    }
}
