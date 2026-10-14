<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\PublicId;
use App\Core\Router;
use App\Services\AgentSimulationService;
use JsonException;
use RuntimeException;
use Throwable;

/** Simulação isolada. Não envia mensagem ao WhatsApp nem cria reservas. */
final class AgentBusinessSimulationController
{
    public function simulate(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, private, max-age=0');
        try {
            $tenantId = (int) (Auth::tenantId() ?? 0);
            if ($tenantId < 1 || !Auth::can('agents.manage')) {
                throw new RuntimeException('Você não tem permissão para testar este atendimento.', 403);
            }
            $publicId = Router::routeParam('id');
            $agentId = is_string($publicId) ? PublicId::decode('agent', $publicId) : null;
            if (!$agentId) throw new RuntimeException('Atendimento não encontrado.', 404);

            $json = file_get_contents('php://input', false, null, 0, 32769);
            if (!is_string($json) || strlen($json) > 32768) throw new RuntimeException('Mensagem muito longa.', 413);
            try {
                $body = json_decode($json, true, 24, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw new RuntimeException('Formato da mensagem inválido.', 400);
            }
            if (!is_array($body) || array_is_list($body) || array_diff(array_keys($body), ['message','history','state']) !== []) {
                throw new RuntimeException('Informações do teste inválidas.', 422);
            }
            $message = $body['message'] ?? null;
            if (!is_string($message) || mb_strlen(trim($message), 'UTF-8') < 1 || mb_strlen($message, 'UTF-8') > 1000) {
                throw new RuntimeException('Digite uma mensagem de até 1.000 caracteres.', 422);
            }
            $history = $body['history'] ?? [];
            $state = $body['state'] ?? [];
            if (!is_array($history) || count($history) > 16 || !array_is_list($history) || !is_array($state)
                || strlen(json_encode($state, JSON_THROW_ON_ERROR)) > 10000) {
                throw new RuntimeException('Histórico de teste inválido. Reinicie a conversa.', 422);
            }
            foreach ($history as $turn) {
                if (!is_array($turn) || !in_array($turn['role'] ?? null, ['user','assistant'], true)
                    || !is_string($turn['content'] ?? null) || mb_strlen($turn['content'], 'UTF-8') > 1500) {
                    throw new RuntimeException('Histórico de teste inválido. Reinicie a conversa.', 422);
                }
            }
            // "real" executa o mesmo motor de laboratório já instalado e pode consumir crédito.
            // A consulta ao agente usa tenantId autenticado, não um valor enviado pela UI.
            $result = (new AgentSimulationService())->turn($tenantId, $agentId, trim($message), $history, $state, 'real');
            Audit::log('agent.business_preview_turn', [
                'agent_id' => $agentId,
                'safe' => !empty($result['safe']),
                'success' => !empty($result['ok']),
            ], $tenantId);
            $this->reply(200, [
                'ok' => !empty($result['ok']),
                'response' => (string) ($result['response'] ?? ''),
                'safe' => !empty($result['safe']),
                'message' => $result['error'] ? 'O teste não pôde ser concluído. Verifique a conexão do atendimento.' : '',
                'state' => $result['state'] ?? [],
                'history' => array_slice((array) ($result['history'] ?? []), -16),
                'needs_review' => !empty($result['safety_findings']),
            ]);
        } catch (RuntimeException $e) {
            $this->reply(in_array($e->getCode(), [400,403,404,413,422], true) ? $e->getCode() : 422,
                ['ok' => false, 'message' => $e->getMessage()]);
        } catch (Throwable $e) {
            error_log('[RS Connect][BusinessPreview] ' . $e->getMessage());
            $this->reply(500, ['ok' => false, 'message' => 'Não foi possível testar agora. Tente novamente.']);
        }
    }

    private function reply(int $status, array $body): void
    {
        http_response_code($status);
        echo json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
