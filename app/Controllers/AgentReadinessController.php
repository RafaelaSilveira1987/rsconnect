<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\PublicId;
use App\Core\Router;
use App\Services\AgentAutoFixService;
use App\Services\AgentReadinessService;
use JsonException;
use RuntimeException;
use Throwable;

/** Endpoints web autenticados; o tenant NUNCA é lido da URL/body. */
final class AgentReadinessController
{
    public function show(): void
    {
        $this->respond(function (int $tenantId, int $agentId): array {
            return (new AgentReadinessService())->audit($tenantId, $agentId);
        });
    }

    public function autofix(): void
    {
        $this->respond(function (int $tenantId, int $agentId): array {
            $raw = file_get_contents('php://input', false, null, 0, 4097);
            if (!is_string($raw) || strlen($raw) > 4096) {
                throw new RuntimeException('JSON excede o limite de 4 KiB.', 413);
            }
            try {
                $body = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw new RuntimeException('JSON inválido.', 400);
            }
            if (!is_array($body) || array_is_list($body) || array_keys($body) !== ['auto_fix_action']
                || !is_string($body['auto_fix_action']) || strlen($body['auto_fix_action']) > 80) {
                throw new RuntimeException('Informe apenas auto_fix_action como string.', 422);
            }
            return (new AgentAutoFixService())->apply($tenantId, $agentId, $body['auto_fix_action']);
        });
    }

    /** @param callable(int,int):array<string,mixed> $work */
    private function respond(callable $work): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, private, max-age=0');
        try {
            $tenantId = (int) (Auth::tenantId() ?? 0);
            if ($tenantId < 1) throw new RuntimeException('Empresa não identificada.', 403);
            $publicId = Router::routeParam('id');
            $agentId = is_string($publicId) ? PublicId::decode('agent', $publicId) : null;
            if ($agentId === null) throw new RuntimeException('Agente não encontrado.', 404);
            $payload = $work($tenantId, $agentId);
            $this->json(200, $payload);
        } catch (RuntimeException $error) {
            $code = in_array($error->getCode(), [400,403,404,409,413,422], true) ? $error->getCode() : 409;
            $this->json($code, ['ok'=>false,'message'=>$error->getMessage()]);
        } catch (Throwable $error) {
            error_log('[RS Connect][Readiness] ' . $error->getMessage());
            $this->json(500, ['ok'=>false,'message'=>'Falha ao auditar a configuração. Consulte os logs técnicos.']);
        }
    }

    /** @param array<string,mixed> $payload */
    private function json(int $code, array $payload): void
    {
        http_response_code($code);
        echo json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
