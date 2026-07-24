<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Env;
use PDO;
use Throwable;

final class CommercialBetaService
{
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
            'score' => $score,
            'ok' => $ok,
            'warning' => $warning,
            'blocked' => $blocked,
            'status_label' => $this->statusLabel($score, $blocked),
            'checks' => $checks,
            'metrics' => $this->metrics(),
            'quick_actions' => $this->quickActions(),
            'release_notes' => $this->releaseNotes(),
            'version_label' => 'Beta Comercial 1.0',
            'operational_routine' => $this->operationalRoutine(),
        ];
    }

    private function checks(): array
    {
        $checks = [];

        $activeTenants = $this->countWhere('tenants', "status = 'active'");
        $checks[] = $this->check(
            'Base de clientes',
            $activeTenants > 0 ? 'ok' : 'blocked',
            $activeTenants . ' empresa(s) ativa(s) cadastrada(s).',
            'Cadastrar pelo menos uma empresa ativa para operar o SaaS.'
        );

        $avgImplementation = $this->number('SELECT ROUND(AVG(percent_complete)) FROM tenant_implementation_status');
        $checks[] = $this->check(
            'Implantação comercial',
            $avgImplementation >= 70 ? 'ok' : ($avgImplementation > 0 ? 'warning' : 'blocked'),
            'Média de implantação: ' . $avgImplementation . '%.',
            'Use o módulo Implantação para concluir pendências por empresa.'
        );

        $connectedInstances = $this->countWhere('evolution_instances', "status IN ('connected','open','active','online')");
        $checks[] = $this->check(
            'WhatsApp/Evolution',
            $connectedInstances > 0 ? 'ok' : 'warning',
            $connectedInstances . ' instância(s) com status conectado/ativo.',
            'Conectar ao menos uma instância e validar envio/recebimento.'
        );

        $aiConfigured = $this->countWhere('ai_provider_credentials', "status = 'active'") > 0 || (string) Env::get('OPENAI_API_KEY', '') !== '';
        $checks[] = $this->check(
            'IA configurada',
            $aiConfigured ? 'ok' : 'warning',
            $aiConfigured ? 'Credencial de IA encontrada.' : 'Nenhuma credencial ativa de IA detectada.',
            'Configure a credencial de IA global ou por empresa antes de vender automação.'
        );

        $messages24h = $this->number("SELECT COUNT(*) FROM conversation_messages WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)");
        $checks[] = $this->check(
            'Conversas e webhooks',
            $messages24h > 0 ? 'ok' : 'warning',
            $messages24h . ' mensagem(ns) registrada(s) nas últimas 24h.',
            'Enviar uma mensagem de teste por uma instância conectada.'
        );

        $backupOk = $this->number("SELECT COUNT(*) FROM system_backups WHERE backup_type = 'automatic' AND status = 'success' AND created_at >= DATE_SUB(NOW(), INTERVAL 72 HOUR)");
        $routineOk = $this->number("SELECT COUNT(*) FROM operations_backup_routines WHERE status = 'active' AND last_success_at IS NOT NULL");
        $checks[] = $this->check(
            'Backup automático',
            ($backupOk > 0 && $routineOk > 0) ? 'ok' : 'warning',
            $backupOk . ' backup(s) automático(s) OK em 72h; ' . $routineOk . ' rotina(s) validada(s).',
            'Manter rotina ativa, n8n gerando dump real e callback validado.'
        );

        $healthDown = $this->latestHealthCount(['down']);
        $healthWarning = $this->latestHealthCount(['warning']);
        $checks[] = $this->check(
            'Monitoramento',
            $healthDown === 0 ? ($healthWarning === 0 ? 'ok' : 'warning') : 'blocked',
            $healthWarning . ' aviso(s) e ' . $healthDown . ' falha(s) no último status por serviço.',
            'Abrir Monitoramento e resolver serviços em aviso ou falha.'
        );

        $privacyRows = $this->countTable('tenant_privacy_settings') + $this->countTable('privacy_settings');
        $checks[] = $this->check(
            'LGPD e aceite',
            $privacyRows > 0 ? 'ok' : 'warning',
            $privacyRows > 0 ? 'Configuração LGPD encontrada.' : 'Configuração LGPD não localizada.',
            'Revisar termos, política e aceite obrigatório por empresa.'
        );

        $plans = $this->countTable('saas_plans') + $this->countTable('subscription_plans');
        $gateways = $this->countTable('payment_gateways');
        $checks[] = $this->check(
            'Cobrança SaaS',
            ($plans > 0 || $gateways > 0) ? 'ok' : 'warning',
            $plans . ' plano(s) e ' . $gateways . ' gateway(s) detectado(s).',
            'Manter ao menos um plano e um processo de cobrança definido.'
        );

        $n8nFlows = $this->countWhere('n8n_tenant_flows', "status = 'active'") + $this->countWhere('n8n_flows', "status = 'active'");
        $checks[] = $this->check(
            'n8n por empresa',
            $n8nFlows > 0 ? 'ok' : 'warning',
            $n8nFlows . ' fluxo(s) ativo(s) detectado(s).',
            'Usar fluxos n8n por empresa quando houver agenda, cobrança ou backup.'
        );

        return $checks;
    }

    private function metrics(): array
    {
        return [
            'tenants' => $this->countTable('tenants'),
            'active_tenants' => $this->countWhere('tenants', "status = 'active'"),
            'implementation_avg' => $this->number('SELECT ROUND(AVG(percent_complete)) FROM tenant_implementation_status'),
            'implementation_testing' => $this->countWhere('tenant_implementation_status', "status IN ('testing','operating','ready')"),
            'conversations_24h' => $this->number("SELECT COUNT(*) FROM conversation_messages WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"),
            'automatic_backups' => $this->number("SELECT COUNT(*) FROM system_backups WHERE backup_type = 'automatic' AND status = 'success'"),
            'backup_routines' => $this->countWhere('operations_backup_routines', "status = 'active'"),
            'last_backup' => $this->singleValue("SELECT created_at FROM system_backups WHERE status = 'success' ORDER BY created_at DESC LIMIT 1"),
        ];
    }

    private function quickActions(): array
    {
        return [
            ['label' => 'Ver implantação', 'url' => '/implantacao', 'scope' => 'super_admin'],
            ['label' => 'Abrir monitoramento', 'url' => '/monitoramento', 'scope' => 'super_admin'],
            ['label' => 'Backup automático', 'url' => '/backup-automatico', 'scope' => 'super_admin'],
            ['label' => 'n8n', 'url' => '/n8n', 'scope' => 'super_admin'],
            ['label' => 'Primeiros passos', 'url' => '/primeiros-passos', 'scope' => 'client'],
            ['label' => 'Privacidade/LGPD', 'url' => '/privacy', 'scope' => 'all'],
        ];
    }

    private function releaseNotes(): array
    {
        return [
            ['version' => '36.6.0', 'title' => 'Estabilização operacional', 'summary' => 'Corrige execução do backup via SSH, sincroniza revisão de incidentes e trata fila bloqueada por Evolution desconectada sem gerar novas falhas.'],
            ['version' => '36.5.9', 'title' => 'Central de operação e diagnóstico da fila', 'summary' => 'Fixa o hamburger no viewport, contextualiza tokens e identifica pendências da IA por instância Evolution.'],
            ['version' => '36.5.8', 'title' => 'Administração RS e monitoramento', 'summary' => 'Reorganiza o menu, agrupa n8n e amplia a Central de operação com evidências, busca e filtros.'],
            ['version' => '36.5.7', 'title' => 'Identificação e cron seguro', 'summary' => 'Corrige nome automático de contatos, melhora toque mobile e endurece a ativação do cron de cobrança.'],
            ['version' => '36.5.6', 'title' => 'Homologação final', 'summary' => 'Corrige contexto de clientes, takeover humano da IA, reprocessamento, cron e responsividade.'],
            ['version' => '36.5.5', 'title' => 'Prontidão beta e Minha empresa', 'summary' => 'Alinha o diagnóstico à migration 048 e reorganiza melhor o bloco de endereço da empresa.'],
            ['version' => '36.5.4', 'title' => 'Equipe e acessos em drawer', 'summary' => 'Cadastro e edição de usuários passam a abrir em gaveta lateral, no padrão de Contatos.'],
            ['version' => '36.5.3', 'title' => 'Cadastro mais limpo e endereço por CEP', 'summary' => 'Dados mestres compactos e preenchimento automático de endereço ao informar o CEP.'],
            ['version' => '36.5.2', 'title' => 'Minha empresa como central administrativa', 'summary' => 'Abas internas, proteção dos dados mestres e reorganização da área administrativa do cliente.'],
            ['version' => '36.5.1', 'title' => 'Comercial em tempo real', 'summary' => 'Indicadores do Kanban atualizados instantaneamente após mover oportunidades.'],
            ['version' => '36.4.7', 'title' => 'Relatórios refinados', 'summary' => 'Melhorias visuais, métricas e consistência dos relatórios do cliente e do Super Admin.'],
            ['version' => '36.3.0', 'title' => 'Backup operacional confiável', 'summary' => 'Rotina de backup com job real, callback idempotente, timeout e histórico operacional.'],
        ];
    }

    private function operationalRoutine(): array
    {
        return [
            'Diário' => ['Monitoramento sem falhas críticas', 'Conversas recebendo mensagens', 'Backup automático concluído ou job n8n sem erro'],
            'Semanal' => ['Revisar implantação das empresas', 'Conferir cobrança/régua', 'Testar uma conversa com IA por cliente ativo'],
            'Antes de novo cliente' => ['Criar empresa e usuário', 'Conectar WhatsApp', 'Criar agente IA', 'Configurar LGPD', 'Concluir checklist de implantação'],
        ];
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

    private function number(string $sql): int
    {
        try {
            return (int) ($this->pdo->query($sql)->fetchColumn() ?: 0);
        } catch (Throwable) {
            return 0;
        }
    }

    private function singleValue(string $sql): ?string
    {
        try {
            $value = $this->pdo->query($sql)->fetchColumn();
            return $value === false ? null : (string) $value;
        } catch (Throwable) {
            return null;
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
