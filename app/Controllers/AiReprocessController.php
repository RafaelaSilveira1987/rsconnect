<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Env;
use App\Core\Flash;
use App\Core\Router;
use App\Services\AiReprocessService;
use App\Services\AfterHoursMonitorService;
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
                $attention = (int) ($result['errors'] ?? 0) > 0
                    ? ' Algumas pendências continuam com erro e permanecem visíveis na fila.'
                    : '';
                Flash::set('success', (int) $result['replied'] . ' conversa(s) presa(s) receberam resposta. Nenhuma mensagem já respondida foi reenviada.' . $attention);
            } elseif ((int) ($result['errors'] ?? 0) > 0) {
                Flash::set('error', 'A fila encontrou mensagem sem resposta e ocorreu uma falha real durante o processamento. A pendência foi mantida; consulte o diagnóstico da fila.');
            } elseif ((int) ($result['blocked'] ?? 0) > 0) {
                Flash::set('warning', (int) ($result['blocked'] ?? 0) . ' grupo(s) de pendência aguardam reconexão do WhatsApp/Evolution. Nenhuma nova tentativa foi enviada enquanto a instância estiver desconectada.');
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

    public function queueCron(): void
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
            $limit = max(1, min(250, (int) Env::get('AI_REPROCESS_QUEUE_LIMIT', 50)));
            $result = $service->runAll('queue_cron', null, $limit);
            echo json_encode(['ok' => true, 'result' => $result], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (Throwable $exception) {
            http_response_code(500);
            echo json_encode([
                'ok' => false,
                'message' => 'Falha ao verificar a fila rápida da IA.',
                'error' => Env::get('APP_DEBUG', false) ? $exception->getMessage() : null,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
    }


    public function saveAfterHoursMonitor(): void
    {
        try {
            $settings = (new AfterHoursMonitorService())->save(
                isset($_POST['enabled']) && (string) $_POST['enabled'] === '1',
                (int) ($_POST['interval_minutes'] ?? 15),
                (int) ($_POST['max_items_per_run'] ?? 50),
                Auth::id()
            );
            Audit::log('ai.after_hours_monitor.settings_saved', [
                'enabled' => (int) ($settings['enabled'] ?? 0),
                'interval_minutes' => (int) ($settings['interval_minutes'] ?? 15),
                'max_items_per_run' => (int) ($settings['max_items_per_run'] ?? 50),
            ]);
            Flash::set('success', 'Monitor pós-horário atualizado.');
        } catch (Throwable $exception) {
            Flash::set('error', 'Não foi possível salvar o monitor pós-horário: ' . $exception->getMessage());
        }

        $this->redirect('/operations/ai-reprocess#after-hours-monitor');
    }

    public function runAfterHoursMonitor(): void
    {
        try {
            $result = (new AfterHoursMonitorService())->run(true, 'manual_after_hours_monitor');
            Audit::log('ai.after_hours_monitor.manual_run', $result);
            $summary = is_array($result['summary'] ?? null) ? $result['summary'] : [];
            $recovered = (int) ($summary['recovered'] ?? 0);
            $errors = (int) ($summary['errors'] ?? 0);
            if ($recovered > 0) {
                Flash::set('success', $recovered . ' conversa(s) pós-horário retomada(s).');
            } elseif ($errors > 0) {
                Flash::set('warning', 'A verificação terminou com pendências que precisam de nova tentativa.');
            } else {
                Flash::set('info', 'Monitor executado. Nenhuma conversa estava pronta para retomada.');
            }
        } catch (Throwable $exception) {
            Flash::set('error', 'Não foi possível executar o monitor pós-horário: ' . $exception->getMessage());
        }

        $this->redirect('/operations/ai-reprocess#after-hours-monitor');
    }

    public function afterHoursCron(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        $token = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ($_SERVER['HTTP_X_RS_AFTER_HOURS_TOKEN'] ?? '')));
        $service = new AfterHoursMonitorService();
        if (!$service->validToken($token)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'message' => 'Token inválido.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        try {
            $result = $service->run(false, 'after_hours_cron');
            echo json_encode(['ok' => true, 'result' => $result], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (Throwable $exception) {
            http_response_code(500);
            echo json_encode([
                'ok' => false,
                'message' => 'Falha ao executar o monitor pós-horário.',
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
