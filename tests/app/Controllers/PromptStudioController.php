<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Flash;
use App\Core\Router;
use App\Services\PromptStudioService;
use PDO;
use Throwable;

final class PromptStudioController
{
    public function generate(): void
    {
        $tenantId = $this->resolveTenantId();
        if ($tenantId < 1) {
            $this->json(['ok' => false, 'message' => 'Empresa não identificada.'], 422);
        }

        try {
            $pdo = Database::connection();
            $companyStatement = $pdo->prepare('SELECT * FROM tenants WHERE id = :id LIMIT 1');
            $companyStatement->execute(['id' => $tenantId]);
            $company = $companyStatement->fetch(PDO::FETCH_ASSOC) ?: [];

            $settingsStatement = $pdo->prepare(
                'SELECT calendar_mode, business_hours_json
                 FROM tenant_onboarding_settings WHERE tenant_id = :tenant_id LIMIT 1'
            );
            $settingsStatement->execute(['tenant_id' => $tenantId]);
            $operations = $settingsStatement->fetch(PDO::FETCH_ASSOC) ?: [];
            $operations['business_hours_enabled'] = !empty($operations['business_hours_json']);

            try {
                $preStatement = $pdo->prepare('SELECT ai_can_confirm FROM tenant_pre_schedule_settings WHERE tenant_id = :tenant_id LIMIT 1');
                $preStatement->execute(['tenant_id' => $tenantId]);
                $operations = array_merge($operations, $preStatement->fetch(PDO::FETCH_ASSOC) ?: []);
            } catch (Throwable) {
                $operations['ai_can_confirm'] = 0;
            }

            $service = new PromptStudioService();
            $result = $service->generate($_POST, $company, $operations);
            $agentId = (int) ($_POST['agent_id'] ?? 0);
            $service->saveDraft($tenantId, Auth::id(), $agentId > 0 ? $agentId : null, $result);

            Audit::log('prompt_studio.generated', [
                'agent_id' => $agentId ?: null,
                'prompt_length' => strlen((string) $result['prompt']),
                'warnings' => count($result['warnings']),
            ], $tenantId);

            $this->json(['ok' => true] + $result);
        } catch (Throwable $exception) {
            $this->json(['ok' => false, 'message' => 'Não foi possível gerar o prompt: ' . $exception->getMessage()], 500);
        }
    }

    public function restore(): void
    {
        $tenantId = $this->resolveTenantId();
        $agentId = (int) ($_POST['agent_id'] ?? 0);
        $versionId = (int) ($_POST['version_id'] ?? 0);

        if ($tenantId < 1 || $agentId < 1 || $versionId < 1) {
            Flash::set('error', 'Não foi possível identificar a versão do prompt.');
            $this->redirect($tenantId);
        }

        try {
            $restored = (new PromptStudioService())->restoreVersion($tenantId, $agentId, $versionId, Auth::id());
            if (!$restored) {
                throw new \RuntimeException('Versão não encontrada para este assistente.');
            }
            Audit::log('prompt_studio.restored', ['agent_id' => $agentId, 'version_id' => $versionId], $tenantId);
            Flash::set('success', 'Versão restaurada. As próximas respostas usarão as instruções recuperadas.');
        } catch (Throwable $exception) {
            Flash::set('error', 'Não foi possível restaurar o prompt: ' . $exception->getMessage());
        }

        $this->redirect($tenantId);
    }

    private function resolveTenantId(): int
    {
        if (!Auth::isSuperAdmin()) {
            return (int) (Auth::tenantId() ?? 0);
        }
        $requested = (int) ($_POST['tenant_id'] ?? $_GET['tenant_id'] ?? 0);
        if ($requested > 0) {
            $statement = Database::connection()->prepare('SELECT id FROM tenants WHERE id = :id LIMIT 1');
            $statement->execute(['id' => $requested]);
            if ($statement->fetchColumn()) {
                return $requested;
            }
        }
        return (int) ($_SESSION['admin_agents_tenant_id'] ?? 0);
    }

    private function json(array $payload, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    private function redirect(int $tenantId): never
    {
        $path = '/agents';
        if (Auth::isSuperAdmin() && $tenantId > 0) {
            $path .= '?tenant_id=' . $tenantId;
        }
        header('Location: ' . Router::url($path));
        exit;
    }
}
