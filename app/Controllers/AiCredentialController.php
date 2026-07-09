<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Crypto;
use App\Core\Database;
use App\Core\Flash;
use App\Core\Router;
use App\Core\View;
use PDO;
use Throwable;

final class AiCredentialController
{
    public function index(): void
    {
        $pdo = Database::connection();

        $credentials = $pdo->query(
            'SELECT c.*, t.name AS tenant_name, a.name AS agent_name
             FROM ai_provider_credentials c
             INNER JOIN tenants t ON t.id = c.tenant_id
             LEFT JOIN ai_agents a ON a.id = c.agent_id
             ORDER BY t.name, c.is_default DESC, c.created_at DESC'
        )->fetchAll(PDO::FETCH_ASSOC);

        foreach ($credentials as &$credential) {
            $credential['api_key_masked'] = $credential['api_key_encrypted'] ? '••••••••••••' : 'Não informada';
            unset($credential['api_key_encrypted']);
        }

        $tenants = $pdo->query(
            'SELECT id, name, status
             FROM tenants
             ORDER BY name'
        )->fetchAll(PDO::FETCH_ASSOC);

        $agents = $pdo->query(
            'SELECT a.id, a.tenant_id, a.name, a.model_provider, a.model_name, t.name AS tenant_name
             FROM ai_agents a
             INNER JOIN tenants t ON t.id = a.tenant_id
             ORDER BY t.name, a.name'
        )->fetchAll(PDO::FETCH_ASSOC);

        View::render('ai_credentials.index', [
            'title' => 'Credenciais de IA',
            'credentials' => $credentials,
            'tenants' => $tenants,
            'agents' => $agents,
        ]);
    }

    public function save(): void
    {
        $id = (int) ($_POST['id'] ?? 0);
        $tenantId = (int) ($_POST['tenant_id'] ?? 0);
        $agentId = (int) ($_POST['agent_id'] ?? 0);
        $label = trim((string) ($_POST['label'] ?? ''));
        $provider = strtolower(trim((string) ($_POST['provider'] ?? 'openai')));
        $baseUrl = rtrim(trim((string) ($_POST['base_url'] ?? '')), '/');
        $defaultModel = trim((string) ($_POST['default_model'] ?? ''));
        $apiKey = trim((string) ($_POST['api_key'] ?? ''));
        $status = (string) ($_POST['status'] ?? 'active');
        $isDefault = isset($_POST['is_default']);

        if ($tenantId < 1 || $label === '' || !in_array($provider, ['openai', 'google', 'custom'], true)) {
            Flash::set('error', 'Preencha empresa, nome da credencial e provedor.');
            $this->redirect('/ai-credentials');
        }

        if (!in_array($status, ['active', 'inactive'], true)) {
            $status = 'active';
        }

        $pdo = Database::connection();

        try {
            $tenantExists = $pdo->prepare('SELECT id FROM tenants WHERE id = :id LIMIT 1');
            $tenantExists->execute(['id' => $tenantId]);
            if (!$tenantExists->fetchColumn()) {
                throw new \RuntimeException('Empresa inválida.');
            }

            if ($agentId > 0) {
                $agentCheck = $pdo->prepare('SELECT id FROM ai_agents WHERE id = :id AND tenant_id = :tenant_id LIMIT 1');
                $agentCheck->execute(['id' => $agentId, 'tenant_id' => $tenantId]);
                if (!$agentCheck->fetchColumn()) {
                    throw new \RuntimeException('O agente selecionado não pertence à empresa.');
                }
            }

            if ($id < 1 && $apiKey === '') {
                throw new \RuntimeException('Informe a API Key para criar a credencial.');
            }

            $pdo->beginTransaction();

            if ($isDefault) {
                $reset = $pdo->prepare(
                    'UPDATE ai_provider_credentials
                     SET is_default = 0
                     WHERE tenant_id = :tenant_id
                       AND provider = :provider
                       AND ((:agent_id = 0 AND agent_id IS NULL) OR agent_id = :agent_id_match)'
                );
                $reset->execute([
                    'tenant_id' => $tenantId,
                    'provider' => $provider,
                    'agent_id' => $agentId,
                    'agent_id_match' => $agentId > 0 ? $agentId : null,
                ]);
            }

            if ($id > 0) {
                $sql = 'UPDATE ai_provider_credentials
                        SET tenant_id = :tenant_id,
                            agent_id = :agent_id,
                            label = :label,
                            provider = :provider,
                            base_url = :base_url,
                            default_model = :default_model,
                            status = :status,
                            is_default = :is_default';
                $params = [
                    'tenant_id' => $tenantId,
                    'agent_id' => $agentId > 0 ? $agentId : null,
                    'label' => $label,
                    'provider' => $provider,
                    'base_url' => $baseUrl !== '' ? $baseUrl : null,
                    'default_model' => $defaultModel !== '' ? $defaultModel : null,
                    'status' => $status,
                    'is_default' => $isDefault ? 1 : 0,
                    'id' => $id,
                ];

                if ($apiKey !== '') {
                    $sql .= ', api_key_encrypted = :api_key';
                    $params['api_key'] = Crypto::encrypt($apiKey);
                }

                $sql .= ' WHERE id = :id';
                $statement = $pdo->prepare($sql);
                $statement->execute($params);
                Audit::log('ai_credential.updated', ['credential_id' => $id, 'provider' => $provider], $tenantId);
            } else {
                $statement = $pdo->prepare(
                    'INSERT INTO ai_provider_credentials
                        (tenant_id, agent_id, label, provider, api_key_encrypted, base_url, default_model, status, is_default)
                     VALUES
                        (:tenant_id, :agent_id, :label, :provider, :api_key, :base_url, :default_model, :status, :is_default)'
                );
                $statement->execute([
                    'tenant_id' => $tenantId,
                    'agent_id' => $agentId > 0 ? $agentId : null,
                    'label' => $label,
                    'provider' => $provider,
                    'api_key' => Crypto::encrypt($apiKey),
                    'base_url' => $baseUrl !== '' ? $baseUrl : null,
                    'default_model' => $defaultModel !== '' ? $defaultModel : null,
                    'status' => $status,
                    'is_default' => $isDefault ? 1 : 0,
                ]);
                Audit::log('ai_credential.created', ['credential_id' => (int) $pdo->lastInsertId(), 'provider' => $provider], $tenantId);
            }

            $pdo->commit();
            Flash::set('success', 'Credencial de IA salva com segurança.');
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Flash::set('error', $exception->getMessage());
        }

        $this->redirect('/ai-credentials');
    }

    private function redirect(string $path): never
    {
        header('Location: ' . Router::url($path));
        exit;
    }
}
