<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Crypto;
use App\Core\Database;
use App\Core\Env;
use App\Core\Flash;
use App\Core\Router;
use App\Core\View;
use App\Services\EvolutionService;
use App\Services\SubscriptionService;
use PDO;
use Throwable;

final class InstanceController
{
    public function index(): void
    {
        $pdo = Database::connection();

        if (Auth::isSuperAdmin()) {
            $statement = $pdo->query(
                'SELECT i.*, t.name AS tenant_name
                 FROM evolution_instances i
                 INNER JOIN tenants t ON t.id = i.tenant_id
                 ORDER BY i.created_at DESC'
            );
        } else {
            $statement = $pdo->prepare(
                'SELECT i.*, t.name AS tenant_name
                 FROM evolution_instances i
                 INNER JOIN tenants t ON t.id = i.tenant_id
                 WHERE i.tenant_id = :tenant_id
                 ORDER BY i.created_at DESC'
            );
            $statement->execute(['tenant_id' => Auth::tenantId()]);
        }

        $instances = $statement->fetchAll(PDO::FETCH_ASSOC);
        foreach ($instances as &$instance) {
            $instance['api_key_masked'] = $instance['api_key_encrypted'] ? '••••••••••••' : 'Não informada';
            unset($instance['api_key_encrypted']);
        }

        $tenants = [];
        if (Auth::isSuperAdmin()) {
            $tenants = $pdo->query('SELECT id, name FROM tenants WHERE status = "active" ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
        }

        View::render('instances.index', [
            'title' => 'Instâncias',
            'instances' => $instances,
            'tenants' => $tenants,
            'defaultUrl' => (string) Env::get('EVOLUTION_DEFAULT_URL', ''),
        ]);
    }

    public function store(): void
    {
        $tenantId = Auth::isSuperAdmin()
            ? (int) ($_POST['tenant_id'] ?? 0)
            : (int) Auth::tenantId();

        $name = trim((string) ($_POST['name'] ?? ''));
        $instanceName = trim((string) ($_POST['instance_name'] ?? ''));
        $baseUrl = rtrim(trim((string) ($_POST['base_url'] ?? '')), '/');
        $apiKey = trim((string) ($_POST['api_key'] ?? ''));
        $status = (string) ($_POST['status'] ?? 'disconnected');

        if ($tenantId < 1 || $name === '' || $instanceName === '' || !filter_var($baseUrl, FILTER_VALIDATE_URL) || $apiKey === '') {
            Flash::set('error', 'Preencha empresa, nome, instância, URL válida e API Key.');
            $this->redirect('/instances');
        }

        if (!in_array($status, ['connected', 'disconnected', 'pending'], true)) {
            $status = 'disconnected';
        }

        $limit = (new SubscriptionService())->ensureCanCreate($tenantId, 'instances');
        if (empty($limit['ok'])) {
            Flash::set('error', $limit['message']);
            $this->redirect('/instances');
        }

        try {
            $pdo = Database::connection();

            $duplicate = $pdo->prepare(
                'SELECT id, name
                 FROM evolution_instances
                 WHERE tenant_id = :tenant_id
                   AND LOWER(instance_name) = LOWER(:instance_name)
                 LIMIT 1'
            );
            $duplicate->execute([
                'tenant_id' => $tenantId,
                'instance_name' => $instanceName,
            ]);
            $existingInstance = $duplicate->fetch(PDO::FETCH_ASSOC);

            if ($existingInstance) {
                Flash::set(
                    'error',
                    'A instância "' . $instanceName . '" já está cadastrada para esta empresa como "' .
                    $existingInstance['name'] . '". Use outro nome na Evolution ou utilize a instância existente.'
                );
                $this->redirect('/instances');
            }

            $statement = $pdo->prepare(
                'INSERT INTO evolution_instances
                    (tenant_id, name, instance_name, base_url, api_key_encrypted, status, is_default)
                 VALUES
                    (:tenant_id, :name, :instance_name, :base_url, :api_key, :status, :is_default)'
            );
            $statement->execute([
                'tenant_id' => $tenantId,
                'name' => $name,
                'instance_name' => $instanceName,
                'base_url' => $baseUrl,
                'api_key' => Crypto::encrypt($apiKey),
                'status' => $status,
                'is_default' => isset($_POST['is_default']) ? 1 : 0,
            ]);

            Flash::set('success', 'Instância cadastrada com segurança.');
        } catch (Throwable $exception) {
            $isDuplicate = str_contains($exception->getMessage(), 'uq_instance_tenant_name')
                || str_contains($exception->getMessage(), 'Duplicate entry');

            Flash::set(
                'error',
                $isDuplicate
                    ? 'Essa instância já está cadastrada para esta empresa. Use outro nome na Evolution ou utilize o cadastro existente.'
                    : 'Não foi possível cadastrar a instância. Verifique os dados informados e tente novamente.'
            );
        }

        $this->redirect('/instances');
    }

    public function sendTest(): void
    {
        $instanceId = (int) ($_POST['instance_id'] ?? 0);
        $phone = preg_replace('/\D+/', '', (string) ($_POST['phone'] ?? '')) ?: '';
        $message = trim((string) ($_POST['message'] ?? ''));

        if ($instanceId < 1 || strlen($phone) < 10 || $message === '') {
            Flash::set('error', 'Selecione a instância, informe o telefone completo e uma mensagem.');
            $this->redirect('/instances');
        }

        $pdo = Database::connection();
        $sql = 'SELECT * FROM evolution_instances WHERE id = :id';
        $params = ['id' => $instanceId];

        if (!Auth::isSuperAdmin()) {
            $sql .= ' AND tenant_id = :tenant_id';
            $params['tenant_id'] = Auth::tenantId();
        }

        $statement = $pdo->prepare($sql . ' LIMIT 1');
        $statement->execute($params);
        $instance = $statement->fetch(PDO::FETCH_ASSOC);

        if (!$instance) {
            Flash::set('error', 'Instância não encontrada para sua empresa.');
            $this->redirect('/instances');
        }

        try {
            $verifySsl = filter_var(
                Env::get('EVOLUTION_SSL_VERIFY', true),
                FILTER_VALIDATE_BOOL,
                FILTER_NULL_ON_FAILURE
            );
            $caBundle = trim((string) Env::get('EVOLUTION_CA_BUNDLE', ''));

            $service = new EvolutionService(
                $instance['base_url'],
                Crypto::decrypt($instance['api_key_encrypted']),
                $instance['instance_name'],
                20,
                $verifySsl ?? true,
                $caBundle !== '' ? $caBundle : null
            );
            $result = $service->sendText($phone, $message);

            $this->audit((int) $instance['tenant_id'], 'evolution.test_sent', [
                'instance_id' => $instanceId,
                'phone' => $phone,
                'http_status' => $result['status'],
            ]);

            Flash::set('success', 'Mensagem de teste enviada. HTTP ' . $result['status'] . '.');
        } catch (Throwable $exception) {
            $this->audit((int) $instance['tenant_id'], 'evolution.test_failed', [
                'instance_id' => $instanceId,
                'error' => $exception->getMessage(),
            ]);
            Flash::set('error', 'Falha no envio: ' . $exception->getMessage());
        }

        $this->redirect('/instances');
    }

    private function audit(int $tenantId, string $action, array $context): void
    {
        $statement = Database::connection()->prepare(
            'INSERT INTO audit_logs (tenant_id, user_id, action, context_json, ip_address)
             VALUES (:tenant_id, :user_id, :action, :context_json, :ip_address)'
        );
        $statement->execute([
            'tenant_id' => $tenantId,
            'user_id' => Auth::id(),
            'action' => $action,
            'context_json' => json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    }

    private function redirect(string $path): never
    {
        header('Location: ' . Router::url($path));
        exit;
    }
}
