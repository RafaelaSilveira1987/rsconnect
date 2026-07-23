<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use Throwable;

final class TenantModuleService
{
    private static array $settingsCache = [];
    /** @return array<string,array{label:string,description:string,paths:array<int,string>,default_visible:bool,default_enabled:bool}> */
    public static function modules(): array
    {
        return [
            'dashboard' => [
                'label' => 'Dashboard',
                'description' => 'Painel inicial, indicadores e atalhos operacionais.',
                'paths' => ['/'],
                'default_visible' => true,
                'default_enabled' => true,
            ],
            'conversations' => [
                'label' => 'Conversas',
                'description' => 'Caixa de entrada, atendimento humano e IA.',
                'paths' => ['/conversations'],
                'default_visible' => true,
                'default_enabled' => true,
            ],
            'contacts' => [
                'label' => 'Contatos',
                'description' => 'Cadastro de contatos e leads.',
                'paths' => ['/contacts'],
                'default_visible' => true,
                'default_enabled' => true,
            ],
            'crm' => [
                'label' => 'Comercial',
                'description' => 'Oportunidades, negociações e etapas comerciais.',
                'paths' => ['/crm'],
                'default_visible' => true,
                'default_enabled' => true,
            ],
            'tasks' => [
                'label' => 'Tarefas',
                'description' => 'Retornos e atividades internas.',
                'paths' => ['/tasks'],
                'default_visible' => true,
                'default_enabled' => true,
            ],
            'calendar' => [
                'label' => 'Agenda',
                'description' => 'Agenda, compromissos e pré-agendamentos.',
                'paths' => ['/calendar'],
                'default_visible' => true,
                'default_enabled' => true,
            ],
            'reports' => [
                'label' => 'Relatórios',
                'description' => 'Métricas, filtros e exportações.',
                'paths' => ['/reports'],
                'default_visible' => true,
                'default_enabled' => true,
            ],
            'instances' => [
                'label' => 'Conexões WhatsApp',
                'description' => 'Conexões da Evolution API por empresa.',
                'paths' => ['/instances'],
                'default_visible' => true,
                'default_enabled' => true,
            ],
            'agents' => [
                'label' => 'Agentes de IA',
                'description' => 'Prompts, regras e comportamento da IA.',
                'paths' => ['/agents'],
                'default_visible' => true,
                'default_enabled' => true,
            ],
            'automations' => [
                'label' => 'Automações',
                'description' => 'Logs e rotinas automáticas da IA/n8n.',
                'paths' => ['/automations'],
                'default_visible' => true,
                'default_enabled' => true,
            ],
            'notifications' => [
                'label' => 'Notificações',
                'description' => 'Avisos internos e alertas financeiros.',
                'paths' => ['/notifications'],
                'default_visible' => true,
                'default_enabled' => true,
            ],
            'privacy' => [
                'label' => 'Privacidade/LGPD',
                'description' => 'Políticas, aceite, solicitações de dados e registros LGPD.',
                'paths' => ['/privacy', '/lgpd'],
                'default_visible' => true,
                'default_enabled' => true,
            ],
            'subscription' => [
                'label' => 'Minha assinatura',
                'description' => 'Plano contratado, cobranças e limites.',
                'paths' => ['/subscription'],
                'default_visible' => true,
                'default_enabled' => true,
            ],
            'users' => [
                'label' => 'Usuários',
                'description' => 'Equipe, acessos e perfis.',
                'paths' => ['/users'],
                'default_visible' => true,
                'default_enabled' => true,
            ],
            'permissions' => [
                'label' => 'Permissões',
                'description' => 'Matriz de permissões dos perfis.',
                'paths' => ['/permissions'],
                'default_visible' => true,
                'default_enabled' => true,
            ],
            'campaigns' => [
                'label' => 'Campanhas',
                'description' => 'Menu reservado para disparos e campanhas controladas.',
                'paths' => ['/campaigns'],
                'default_visible' => false,
                'default_enabled' => false,
            ],
            'attendance_filters' => [
                'label' => 'Filtros de atendimento',
                'description' => 'Menu reservado para filtros avançados de operação.',
                'paths' => ['/attendance-filters'],
                'default_visible' => false,
                'default_enabled' => false,
            ],
            'company_settings' => [
                'label' => 'Minha empresa',
                'description' => 'Dados e preferências da empresa. Sempre recomendado.',
                'paths' => ['/company-settings'],
                'default_visible' => true,
                'default_enabled' => true,
            ],
        ];
    }

    public function visible(int $tenantId, string $moduleKey): bool
    {
        $settings = $this->settingsForTenant($tenantId);
        return (bool) ($settings[$moduleKey]['is_visible'] ?? (self::modules()[$moduleKey]['default_visible'] ?? true));
    }

    public function enabled(int $tenantId, string $moduleKey): bool
    {
        if ($moduleKey === 'company_settings' || $moduleKey === 'dashboard') {
            return true;
        }
        $settings = $this->settingsForTenant($tenantId);
        return (bool) ($settings[$moduleKey]['is_enabled'] ?? (self::modules()[$moduleKey]['default_enabled'] ?? true));
    }

    /** @return array<string,array{is_visible:int,is_enabled:int}> */
    public function settingsForTenant(int $tenantId): array
    {
        if ($tenantId < 1 || !$this->tableExists('tenant_module_settings')) {
            return [];
        }
        if (isset(self::$settingsCache[$tenantId])) {
            return self::$settingsCache[$tenantId];
        }
        try {
            $statement = Database::connection()->prepare(
                'SELECT module_key, is_visible, is_enabled FROM tenant_module_settings WHERE tenant_id = :tenant_id'
            );
            $statement->execute(['tenant_id' => $tenantId]);
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return [];
        }

        $settings = [];
        foreach ($rows as $row) {
            $settings[(string) $row['module_key']] = [
                'is_visible' => (int) $row['is_visible'],
                'is_enabled' => (int) $row['is_enabled'],
            ];
        }
        self::$settingsCache[$tenantId] = $settings;
        return $settings;
    }

    public function saveSettings(int $tenantId, array $visibleModules, array $enabledModules): void
    {
        if ($tenantId < 1 || !$this->tableExists('tenant_module_settings')) {
            return;
        }
        $statement = Database::connection()->prepare(
            'INSERT INTO tenant_module_settings (tenant_id, module_key, is_visible, is_enabled)
             VALUES (:tenant_id, :module_key, :is_visible, :is_enabled)
             ON DUPLICATE KEY UPDATE
                is_visible = VALUES(is_visible),
                is_enabled = VALUES(is_enabled),
                updated_at = CURRENT_TIMESTAMP'
        );
        foreach (self::modules() as $moduleKey => $module) {
            $alwaysEnabled = in_array($moduleKey, ['dashboard', 'company_settings'], true);
            $isEnabled = $alwaysEnabled ? 1 : (in_array($moduleKey, $enabledModules, true) ? 1 : 0);
            $isVisible = $alwaysEnabled ? 1 : (in_array($moduleKey, $visibleModules, true) ? 1 : 0);
            if ($isEnabled === 0) {
                $isVisible = 0;
            }
            $statement->execute([
                'tenant_id' => $tenantId,
                'module_key' => $moduleKey,
                'is_visible' => $isVisible,
                'is_enabled' => $isEnabled,
            ]);
        }
        unset(self::$settingsCache[$tenantId]);
    }

    /**
     * Permite ao administrador da empresa organizar apenas a navegação.
     * O acesso técnico continua sendo definido pelo Admin RS.
     *
     * @param array<int,string> $visibleModules
     */
    public function saveVisibility(int $tenantId, array $visibleModules): void
    {
        if ($tenantId < 1 || !$this->tableExists('tenant_module_settings')) {
            return;
        }

        $current = $this->settingsForTenant($tenantId);
        $statement = Database::connection()->prepare(
            'INSERT INTO tenant_module_settings (tenant_id, module_key, is_visible, is_enabled)
             VALUES (:tenant_id, :module_key, :is_visible, :is_enabled)
             ON DUPLICATE KEY UPDATE
                is_visible = VALUES(is_visible),
                updated_at = CURRENT_TIMESTAMP'
        );

        foreach (self::modules() as $moduleKey => $module) {
            $mandatory = in_array($moduleKey, ['dashboard', 'company_settings'], true);
            $isEnabled = $mandatory
                ? 1
                : (int) (($current[$moduleKey]['is_enabled'] ?? null) ?? ($module['default_enabled'] ?? true));
            $isVisible = $mandatory || in_array($moduleKey, $visibleModules, true) ? 1 : 0;
            if ($isEnabled === 0) {
                $isVisible = 0;
            }
            $statement->execute([
                'tenant_id' => $tenantId,
                'module_key' => $moduleKey,
                'is_visible' => $isVisible,
                'is_enabled' => $isEnabled,
            ]);
        }

        unset(self::$settingsCache[$tenantId]);
    }

    public function moduleForPath(string $path): ?string
    {
        $path = '/' . trim($path, '/');
        if ($path === '//') {
            $path = '/';
        }
        foreach (self::modules() as $moduleKey => $module) {
            foreach ($module['paths'] as $prefix) {
                $prefix = '/' . trim($prefix, '/');
                if ($prefix === '//') {
                    $prefix = '/';
                }
                if ($prefix === '/' && $path === '/') {
                    return $moduleKey;
                }
                if ($prefix !== '/' && ($path === $prefix || str_starts_with($path, $prefix . '/'))) {
                    return $moduleKey;
                }
            }
        }
        return null;
    }

    private function tableExists(string $table): bool
    {
        try {
            $statement = Database::connection()->prepare(
                'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table'
            );
            $statement->execute(['table' => $table]);
            return (int) $statement->fetchColumn() > 0;
        } catch (Throwable) {
            return false;
        }
    }
}
