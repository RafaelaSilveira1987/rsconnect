<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Env;
use App\Core\Flash;
use App\Services\MessageGovernanceService;

final class MessageGovernanceController
{
    public function runManual(): void
    {
        $tenantId = Auth::isSuperAdmin() ? (int) ($_POST['tenant_id'] ?? 0) : (int) (Auth::tenantId() ?? 0);
        $result = (new MessageGovernanceService())->run($tenantId > 0 ? $tenantId : null, 'manual');
        Flash::set(!empty($result['ok']) ? 'success' : 'warning', sprintf(
            'Retenção executada: %d conteúdo(s) e %d payload(s) removido(s).',
            (int) ($result['messages_purged'] ?? 0),
            (int) ($result['payloads_purged'] ?? 0)
        ));
        header('Location: /company-settings' . (Auth::isSuperAdmin() && $tenantId > 0 ? '?id=' . $tenantId : ''));
        exit;
    }

    public function cron(): void
    {
        header('Content-Type: application/json; charset=UTF-8');
        $expected = trim((string) Env::get('MESSAGE_RETENTION_TOKEN', ''));
        $authorization = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
        $bearer = str_starts_with($authorization, 'Bearer ') ? substr($authorization, 7) : '';
        $received = trim((string) ($_SERVER['HTTP_X_RS_MESSAGE_RETENTION_TOKEN'] ?? $_GET['token'] ?? $_POST['token'] ?? $bearer));
        if ($expected === '') {
            http_response_code(503);
            echo json_encode(['ok' => false, 'message' => 'MESSAGE_RETENTION_TOKEN não configurado.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return;
        }
        if ($received === '' || !hash_equals($expected, $received)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'message' => 'Token inválido.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return;
        }

        $origin = trim((string) ($_SERVER['HTTP_X_RS_AUTOMATION_ORIGIN'] ?? 'cron'));
        $source = $origin === 'n8n' ? 'n8n' : 'cron';
        $result = (new MessageGovernanceService())->run(null, $source);
        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
