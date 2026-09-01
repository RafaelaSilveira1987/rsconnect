<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Flash;
use App\Core\Router;
use App\Core\View;
use App\Services\OperationalAlertService;
use Throwable;

final class OperationalAlertsController
{
    public function index(): void
    {
        View::render('operations.alerts', [
            'title' => 'Alertas operacionais',
            'data' => (new OperationalAlertService())->dashboard(),
        ]);
    }

    public function save(): void
    {
        $this->requireCsrf();
        try {
            (new OperationalAlertService())->savePreferences((int) Auth::id(), $_POST);
            $this->go('success', 'Preferências de alertas salvas.');
        } catch (Throwable $exception) {
            $this->go('error', $exception->getMessage());
        }
    }

    public function test(): void
    {
        $this->requireCsrf();
        try {
            $results = (new OperationalAlertService())->testConfiguredChannels((int) Auth::id());
            $messages = [];
            $hasFailure = false;
            foreach ($results as $channel => $result) {
                $ok = !empty($result['ok']);
                $hasFailure = $hasFailure || !$ok;
                $messages[] = ucfirst((string) $channel) . ': ' . (string) ($result['message'] ?? ($ok ? 'OK' : 'falha'));
            }
            $this->go($hasFailure ? 'error' : 'success', implode(' | ', $messages));
        } catch (Throwable $exception) {
            $this->go('error', 'Não foi possível testar os canais: ' . $exception->getMessage());
        }
    }

    public function acknowledge(): void
    {
        $this->requireCsrf();
        try {
            (new OperationalAlertService())->acknowledgeIncident(
                (int) ($_POST['incident_id'] ?? 0),
                (int) Auth::id(),
                (string) ($_POST['note'] ?? '')
            );
            $this->go('success', 'Incidente reconhecido. O acompanhamento permanece ativo até a recuperação.');
        } catch (Throwable $exception) {
            $this->go('error', $exception->getMessage());
        }
    }

    public function resolve(): void
    {
        $this->requireCsrf();
        try {
            $releaseQueue = (string) ($_POST['release_queue'] ?? '') === '1';
            $result = (new OperationalAlertService())->resolveIncident(
                (int) ($_POST['incident_id'] ?? 0),
                $releaseQueue
            );
            $cancelledTotal = (int) ($result['cancelled_messages'] ?? 0)
                + (int) ($result['cancelled_ai_pending'] ?? 0)
                + (int) ($result['cancelled_after_hours'] ?? 0);
            $paused = (int) ($result['instances_paused'] ?? 0) > 0;
            $message = $releaseQueue
                ? 'Situação resolvida. ' . $cancelledTotal . ' pendência(s) retirada(s) da fila.'
                    . ($paused ? ' Os alertas permanecerão silenciados até a reconexão.' : '')
                : (!empty($result['messaging_incident'])
                    ? 'Situação resolvida.' . ($paused ? ' Os alertas da conexão permanecerão silenciados até a reconexão.' : '')
                    : 'Incidente marcado como resolvido e recuperação comunicada.');
            $this->go('success', $message);
        } catch (Throwable $exception) {
            $this->go('error', $exception->getMessage());
        }
    }

    public function readAll(): void
    {
        $this->requireCsrf();
        (new OperationalAlertService())->markAllRead((int) Auth::id());
        $this->go('success', 'Alertas marcados como lidos.');
    }

    public function count(): void
    {
        $data = (new OperationalAlertService())->dashboard((int) Auth::id());
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => true,
            'count' => (int) ($data['unread'] ?? 0),
            'latest' => $data['notifications'][0] ?? null,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function requireCsrf(): void
    {
        if (!Csrf::validate($_POST['_token'] ?? null)) {
            $this->go('error', 'Sessão expirada. Atualize a página e tente novamente.');
        }
    }

    private function go(string $type, string $message): never
    {
        Flash::set($type, $message);
        header('Location: ' . Router::url('/operacao-alertas'));
        exit;
    }
}
