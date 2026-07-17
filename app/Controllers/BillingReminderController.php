<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Env;
use App\Core\Flash;
use App\Core\Router;
use App\Core\View;
use App\Services\BillingReminderService;
use App\Services\OperationsService;
use Throwable;

final class BillingReminderController
{
    public function index(): void
    {
        $service = new BillingReminderService();
        View::render('billing_reminders.index', [
            'title' => 'Régua de cobrança',
            'rules' => $service->rules(),
            'logs' => $service->logs(),
            'preview' => $service->preview(),
            'eventLabels' => BillingReminderService::EVENT_LABELS,
            'channelLabels' => BillingReminderService::CHANNEL_LABELS,
        ]);
    }

    public function saveRule(): void
    {
        try {
            (new BillingReminderService())->saveRule($_POST);
            Audit::log('billing.reminder_rule_saved', ['id' => (int) ($_POST['id'] ?? 0)]);
            Flash::set('success', 'Regra da régua salva.');
        } catch (Throwable $exception) {
            Flash::set('error', 'Não foi possível salvar regra: ' . $exception->getMessage());
        }
        $this->redirect('/billing-reminders');
    }

    public function run(): void
    {
        try {
            $result = (new BillingReminderService())->runDueReminders();
            Audit::log('billing.reminder_run', $result);
            $message = sprintf(
                'Régua processada: %d aviso(s) criado(s), %d enviado(s), %d ignorado(s).',
                (int) $result['created'],
                (int) $result['dispatched'],
                (int) $result['ignored']
            );
            if (!empty($result['errors'])) {
                $message .= ' Erros: ' . implode(' | ', array_slice($result['errors'], 0, 3));
                Flash::set('warning', $message);
            } else {
                Flash::set('success', $message);
            }

            // Atualiza imediatamente o card do Monitoramento antes do redirecionamento.
            (new OperationsService())->refreshBillingCronCheck();
        } catch (Throwable $exception) {
            Flash::set('error', 'Não foi possível processar a régua: ' . $exception->getMessage());
        }
        $this->redirect($this->safeReturnPath((string) ($_POST['return_to'] ?? ''), '/billing-reminders'));
    }


    public function cron(): void
    {
        $expected = trim((string) Env::get('BILLING_CRON_TOKEN', ''));
        $received = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));
        if ($expected !== '' && !hash_equals($expected, $received)) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'Token inválido.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        try {
            $result = (new BillingReminderService())->runDueReminders();
            http_response_code(200);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => true] + $result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (Throwable $exception) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => $exception->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        exit;
    }

    private function safeReturnPath(string $path, string $fallback): string
    {
        $path = trim($path);
        if ($path === '' || !str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return $fallback;
        }
        return $path;
    }

    private function redirect(string $path): never
    {
        header('Location: ' . Router::url($path));
        exit;
    }
}
