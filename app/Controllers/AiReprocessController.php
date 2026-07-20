<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Env;
use App\Core\Flash;
use App\Core\Router;
use App\Services\AiReprocessService;
use Throwable;

final class AiReprocessController
{
    public function save(): void
    {
        try {
            $settings = (new AiReprocessService())->saveSettings(
                isset($_POST['enabled']) && (string) $_POST['enabled'] === '1',
                trim((string) ($_POST['run_time'] ?? '03:00')),
                trim((string) ($_POST['timezone'] ?? Env::get('APP_TIMEZONE', 'America/Sao_Paulo'))),
                (int) ($_POST['max_messages_per_run'] ?? 100),
                Auth::id()
            );
            Audit::log('ai.reprocess.settings_saved', [
                'enabled' => (int) ($settings['enabled'] ?? 0),
                'run_time' => $settings['run_time'] ?? null,
                'timezone' => $settings['timezone'] ?? null,
                'max_messages_per_run' => $settings['max_messages_per_run'] ?? null,
            ]);
            Flash::set('success', 'Rotina de reprocessamento da IA atualizada.');
        } catch (Throwable $exception) {
            Flash::set('error', 'Não foi possível salvar a rotina: ' . $exception->getMessage());
        }

        $this->redirect('/operations/ai-reprocess');
    }

    public function run(): void
    {
        try {
            $result = (new AiReprocessService())->runAll('manual', Auth::id());
            Audit::log('ai.reprocess.manual_run', $result);

            if (($result['status'] ?? '') === 'busy') {
                Flash::set('warning', (string) ($result['message'] ?? 'Já existe uma execução em andamento.'));
            } elseif ((int) ($result['replied'] ?? 0) > 0) {
                Flash::set('success', (int) $result['replied'] . ' conversa(s) presa(s) receberam resposta. Nenhuma mensagem já respondida foi reenviada.');
            } elseif ((int) ($result['attempted'] ?? 0) > 0) {
                Flash::set('warning', 'As mensagens pendentes foram reavaliadas, mas regras do atendimento impediram novos envios.');
            } else {
                Flash::set('info', 'Nenhuma mensagem estava presa na fila da IA. Nada foi enviado.');
            }
        } catch (Throwable $exception) {
            Flash::set('error', 'Não foi possível executar o reprocessamento: ' . $exception->getMessage());
        }

        $this->redirect('/operations/ai-reprocess');
    }

    public function cron(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        $token = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ($_SERVER['HTTP_X_RS_AI_REPROCESS_TOKEN'] ?? '')));
        $service = new AiReprocessService();

        if (!$service->validCronToken($token)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'message' => 'Token inválido.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        try {
            $result = $service->runScheduledIfDue();
            echo json_encode(['ok' => true, 'result' => $result], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (Throwable $exception) {
            http_response_code(500);
            echo json_encode([
                'ok' => false,
                'message' => 'Falha ao verificar a rotina agendada.',
                'error' => Env::get('APP_DEBUG', false) ? $exception->getMessage() : null,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
    }

    private function redirect(string $path): never
    {
        header('Location: ' . Router::url($path));
        exit;
    }
}
