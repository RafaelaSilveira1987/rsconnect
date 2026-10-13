<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Env;
use App\Core\Flash;
use App\Core\Router;
use App\Core\View;
use App\Services\BillingReminderService;
use App\Services\PlatformNotificationService;
use App\Services\SubscriptionLifecycleReminderService;
use App\Services\OperationsService;
use Throwable;

final class BillingReminderController
{
    public function index(): void
    {
        $service = new BillingReminderService();
        $platformNotifications = new PlatformNotificationService();
        $lifecycle = new SubscriptionLifecycleReminderService();
        View::render('billing_reminders.index', [
            'title' => 'Lembretes financeiros e de vigência',
            'rules' => $service->rules(),
            'logs' => $service->logs(),
            'preview' => $service->preview(),
            'automationState' => $service->automationState(),
            'eventLabels' => BillingReminderService::EVENT_LABELS,
            'channelLabels' => BillingReminderService::CHANNEL_LABELS,
            'platformNotificationSettings' => $platformNotifications->settings(),
            'platformWhatsappInstances' => $platformNotifications->whatsappInstances(),
            'lifecycleRules' => $lifecycle->rules(),
            'lifecycleLogs' => $lifecycle->logs(),
            'lifecycleTargetLabels' => SubscriptionLifecycleReminderService::TARGET_LABELS,
            'lifecycleChannelLabels' => SubscriptionLifecycleReminderService::CHANNEL_LABELS,
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

    public function savePlatformSettings(): void
    {
        try {
            $instanceId = (int) ($_POST['whatsapp_instance_id'] ?? 0);
            (new PlatformNotificationService())->saveSettings($instanceId > 0 ? $instanceId : null, Auth::id());
            Audit::log('billing.platform_notification_settings_saved', ['whatsapp_instance_id' => $instanceId]);
            Flash::set('success', $instanceId > 0
                ? 'Canal de WhatsApp da plataforma salvo.'
                : 'Canal de WhatsApp da plataforma removido. Avisos internos continuam disponíveis.');
        } catch (Throwable $exception) {
            Flash::set('error', 'Não foi possível salvar o canal da plataforma: ' . $exception->getMessage());
        }
        $this->redirect('/billing-reminders');
    }

    public function saveLifecycleRule(): void
    {
        try {
            (new SubscriptionLifecycleReminderService())->saveRule($_POST);
            Audit::log('billing.lifecycle_rule_saved', ['id' => (int) ($_POST['id'] ?? 0)]);
            Flash::set('success', 'Regra de vigência/teste salva.');
        } catch (Throwable $exception) {
            Flash::set('error', 'Não foi possível salvar a regra de vigência/teste: ' . $exception->getMessage());
        }
        $this->redirect('/billing-reminders');
    }

    public function run(): void
    {
        try {
            $billing = (new BillingReminderService())->runDueReminders('manual');
            $lifecycle = (new SubscriptionLifecycleReminderService())->runDueReminders('manual');
            $result = [
                'created' => (int) $billing['created'] + (int) $lifecycle['created'],
                'dispatched' => (int) $billing['dispatched'] + (int) $lifecycle['dispatched'],
                'ignored' => (int) $billing['ignored'] + (int) $lifecycle['ignored'],
                'errors' => array_merge((array) $billing['errors'], (array) $lifecycle['errors']),
                'billing' => $billing,
                'lifecycle' => $lifecycle,
            ];
            Audit::log('billing.reminder_run', $result);
            $message = sprintf(
                'Rotinas processadas: %d aviso(s) criado(s), %d encaminhado(s), %d ignorado(s).',
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
        if ($expected === '') {
            http_response_code(503);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'BILLING_CRON_TOKEN não configurado no ambiente.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }
        if ($received === '' || !hash_equals($expected, $received)) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'Token inválido.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        try {
            $billing = (new BillingReminderService())->runDueReminders('cron');
            $lifecycle = (new SubscriptionLifecycleReminderService())->runDueReminders('cron');
            $result = [
                'created' => (int) $billing['created'] + (int) $lifecycle['created'],
                'dispatched' => (int) $billing['dispatched'] + (int) $lifecycle['dispatched'],
                'ignored' => (int) $billing['ignored'] + (int) $lifecycle['ignored'],
                'errors' => array_merge((array) $billing['errors'], (array) $lifecycle['errors']),
                'billing' => $billing,
                'lifecycle' => $lifecycle,
            ];
            (new OperationsService())->refreshBillingCronCheck();
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
