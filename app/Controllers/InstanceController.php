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


    public function qrCode(): void
    {
        $instanceId = (int) ($_POST['instance_id'] ?? 0);
        if ($instanceId < 1) {
            $this->json(['ok' => false, 'message' => 'Instância inválida.'], 422);
        }

        $instance = $this->findInstance($instanceId);
        if (!$instance) {
            $this->json(['ok' => false, 'message' => 'Instância não encontrada para sua empresa.'], 404);
        }

        try {
            $service = $this->makeEvolutionService($instance);
            $result = $service->connectInstance();
            $body = $result['body'] ?? [];

            $base64 = $this->extractQrBase64($body);
            $pairingCode = $this->extractPairingCode($body);
            $qrCodeText = $this->extractQrCodeText($body);
            $state = $this->extractConnectionState($body) ?: 'qrcode';
            $mappedStatus = $this->mapEvolutionStateToStatus($state);

            $this->updateInstanceConnectionStatus((int) $instance['id'], $mappedStatus, $state, true);

            $this->audit((int) $instance['tenant_id'], 'evolution.qrcode_requested', [
                'instance_id' => $instanceId,
                'http_status' => $result['status'] ?? null,
                'state' => $state,
                'has_base64' => $base64 !== '',
                'has_pairing_code' => $pairingCode !== '',
            ]);

            $this->json([
                'ok' => true,
                'message' => $base64 !== ''
                    ? 'QR Code gerado. Escaneie com o WhatsApp do cliente.'
                    : 'A Evolution respondeu sem imagem base64. Tente atualizar ou use o código de pareamento, se exibido.',
                'instance' => [
                    'id' => (int) $instance['id'],
                    'name' => $instance['name'],
                    'instance_name' => $instance['instance_name'],
                    'tenant_name' => $instance['tenant_name'] ?? '',
                    'status' => $mappedStatus,
                    'state' => $state,
                ],
                'qr' => [
                    'base64' => $base64,
                    'code' => $qrCodeText,
                    'pairing_code' => $pairingCode,
                    'count' => $body['count'] ?? null,
                ],
                'raw' => $body,
            ]);
        } catch (Throwable $exception) {
            $this->audit((int) $instance['tenant_id'], 'evolution.qrcode_failed', [
                'instance_id' => $instanceId,
                'error' => $exception->getMessage(),
            ]);

            $this->json(['ok' => false, 'message' => 'Falha ao gerar QR Code: ' . $exception->getMessage()], 500);
        }
    }

    public function status(): void
    {
        $instanceId = (int) ($_POST['instance_id'] ?? 0);
        if ($instanceId < 1) {
            $this->json(['ok' => false, 'message' => 'Instância inválida.'], 422);
        }

        $instance = $this->findInstance($instanceId);
        if (!$instance) {
            $this->json(['ok' => false, 'message' => 'Instância não encontrada para sua empresa.'], 404);
        }

        try {
            $service = $this->makeEvolutionService($instance);
            $result = $service->connectionState();
            $body = $result['body'] ?? [];
            $state = $this->extractConnectionState($body) ?: 'unknown';
            $mappedStatus = $this->mapEvolutionStateToStatus($state);

            $this->updateInstanceConnectionStatus((int) $instance['id'], $mappedStatus, $state, false);

            $this->audit((int) $instance['tenant_id'], 'evolution.status_checked', [
                'instance_id' => $instanceId,
                'http_status' => $result['status'] ?? null,
                'state' => $state,
                'mapped_status' => $mappedStatus,
            ]);

            $this->json([
                'ok' => true,
                'message' => 'Status atualizado.',
                'instance' => [
                    'id' => (int) $instance['id'],
                    'name' => $instance['name'],
                    'instance_name' => $instance['instance_name'],
                    'tenant_name' => $instance['tenant_name'] ?? '',
                    'status' => $mappedStatus,
                    'state' => $state,
                    'label' => $this->statusLabel($mappedStatus, $state),
                ],
                'raw' => $body,
            ]);
        } catch (Throwable $exception) {
            $this->audit((int) $instance['tenant_id'], 'evolution.status_failed', [
                'instance_id' => $instanceId,
                'error' => $exception->getMessage(),
            ]);

            $this->json(['ok' => false, 'message' => 'Falha ao consultar status: ' . $exception->getMessage()], 500);
        }
    }

    private function findInstance(int $instanceId): ?array
    {
        $pdo = Database::connection();
        $sql = 'SELECT i.*, t.name AS tenant_name
                FROM evolution_instances i
                INNER JOIN tenants t ON t.id = i.tenant_id
                WHERE i.id = :id';
        $params = ['id' => $instanceId];

        if (!Auth::isSuperAdmin()) {
            $sql .= ' AND i.tenant_id = :tenant_id';
            $params['tenant_id'] = Auth::tenantId();
        }

        $statement = $pdo->prepare($sql . ' LIMIT 1');
        $statement->execute($params);
        $instance = $statement->fetch(PDO::FETCH_ASSOC);

        return $instance ?: null;
    }

    private function makeEvolutionService(array $instance): EvolutionService
    {
        $verifySsl = filter_var(
            Env::get('EVOLUTION_SSL_VERIFY', true),
            FILTER_VALIDATE_BOOL,
            FILTER_NULL_ON_FAILURE
        );
        $caBundle = trim((string) Env::get('EVOLUTION_CA_BUNDLE', ''));

        return new EvolutionService(
            $instance['base_url'],
            Crypto::decrypt($instance['api_key_encrypted']),
            $instance['instance_name'],
            25,
            $verifySsl ?? true,
            $caBundle !== '' ? $caBundle : null
        );
    }

    private function extractQrBase64(array $body): string
    {
        $candidates = [
            $body['base64'] ?? null,
            $body['qrcode']['base64'] ?? null,
            $body['qr']['base64'] ?? null,
            $body['instance']['qrcode']['base64'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return '';
    }

    private function extractPairingCode(array $body): string
    {
        $candidate = $body['pairingCode'] ?? $body['pairing_code'] ?? $body['qrcode']['pairingCode'] ?? null;

        return is_string($candidate) ? trim($candidate) : '';
    }

    private function extractQrCodeText(array $body): string
    {
        $candidate = $body['code'] ?? $body['qrcode']['code'] ?? $body['qr']['code'] ?? null;

        return is_string($candidate) ? trim($candidate) : '';
    }

    private function extractConnectionState(array $body): string
    {
        $candidate = $body['instance']['state']
            ?? $body['instance']['connectionStatus']
            ?? $body['state']
            ?? $body['status']
            ?? $body['connectionStatus']
            ?? $body['connection_state']
            ?? '';

        if (is_array($candidate)) {
            $candidate = $candidate['state'] ?? $candidate['status'] ?? '';
        }

        return strtolower(trim((string) $candidate));
    }

    private function mapEvolutionStateToStatus(string $state): string
    {
        $normalized = strtolower($state);
        if (in_array($normalized, ['open', 'connected', 'connect', 'online'], true)) {
            return 'connected';
        }

        if (in_array($normalized, ['connecting', 'qrcode', 'pairing', 'pairingcode', 'pending'], true)) {
            return 'pending';
        }

        return 'disconnected';
    }

    private function statusLabel(string $status, string $state = ''): string
    {
        return match ($status) {
            'connected' => 'Conectada',
            'pending' => $state === 'qrcode' ? 'Aguardando leitura do QR Code' : 'Pendente',
            default => 'Desconectada',
        };
    }

    private function updateInstanceConnectionStatus(int $instanceId, string $status, string $state, bool $qrRequested): void
    {
        $pdo = Database::connection();
        $statement = $pdo->prepare('UPDATE evolution_instances SET status = :status WHERE id = :id');
        $statement->execute(['status' => $status, 'id' => $instanceId]);

        try {
            $parts = [
                'status = :status',
                'connection_state = :state',
                'last_status_check_at = NOW()',
            ];
            if ($status === 'connected') {
                $parts[] = 'connected_at = COALESCE(connected_at, NOW())';
            }
            if ($status === 'disconnected') {
                $parts[] = 'disconnected_at = NOW()';
            }
            if ($qrRequested) {
                $parts[] = 'qrcode_requested_at = NOW()';
            }

            $statement = $pdo->prepare('UPDATE evolution_instances SET ' . implode(', ', $parts) . ' WHERE id = :id');
            $statement->execute([
                'status' => $status,
                'state' => $state,
                'id' => $instanceId,
            ]);
        } catch (Throwable) {
            // Compatibilidade com ambientes onde a migration opcional ainda não foi aplicada.
        }
    }

    private function json(array $payload, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
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
