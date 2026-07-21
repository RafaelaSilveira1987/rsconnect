<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Env;
use App\Core\Flash;
use App\Core\Router;
use App\Core\View;
use App\Services\CalendarAvailabilityService;
use App\Services\CalendarGoogleLifecycleService;
use PDO;
use Throwable;

final class CalendarAvailabilityController
{
    public function index(): void
    {
        $tenantId = $this->resolveTenantFromQuery();
        $tenants = Auth::isSuperAdmin()
            ? Database::connection()->query('SELECT id, name FROM tenants WHERE status = "active" ORDER BY name')->fetchAll(PDO::FETCH_ASSOC)
            : [];

        $service = new CalendarAvailabilityService();
        $dashboard = $tenantId > 0 ? $service->dashboard($tenantId) : [
            'settings' => $service->settings($tenantId),
            'pending' => [],
            'requests' => [],
            'slots' => [],
            'googleLogs' => [],
            'metrics' => ['pending' => 0, 'requests' => 0, 'slots' => 0, 'selected' => 0, 'held' => 0],
            'integration' => ['n8n_enabled' => false, 'active_url_configured' => false, 'token_configured' => false, 'calendar_configured' => false, 'active_mode' => 'free_slots', 'last_status' => '', 'last_error' => '', 'last_at' => ''],
            'maintenance' => ['enabled' => false, 'expired_holds' => 0, 'confirmed_without_event' => 0, 'failed_syncs' => 0, 'stale_requests' => 0, 'last_run' => null],
        ];

        View::render('calendar_availability.index', [
            'title' => 'Agenda — disponibilidade',
            'tenantId' => $tenantId,
            'tenants' => $tenants,
            'settings' => $dashboard['settings'],
            'pending' => $dashboard['pending'],
            'requests' => $dashboard['requests'],
            'slots' => $dashboard['slots'],
            'googleLogs' => $dashboard['googleLogs'] ?? [],
            'metrics' => $dashboard['metrics'],
            'integration' => $dashboard['integration'] ?? [],
            'maintenance' => $dashboard['maintenance'] ?? [],
            'canManage' => Auth::can('calendar.manage'),
        ]);
    }

    public function saveSettings(): void
    {
        Csrf::validate($_POST['_token'] ?? null);
        $tenantId = $this->resolveTenantFromPost();
        if ($tenantId < 1) {
            Flash::set('error', 'Selecione uma empresa para salvar a configuração.');
            $this->redirect('/calendar?section=availability');
        }

        try {
            (new CalendarAvailabilityService())->saveSettings($tenantId, $_POST, Auth::isSuperAdmin());
            Flash::set('success', 'Configuração de disponibilidade salva.');
        } catch (Throwable $exception) {
            Flash::set('error', 'Não foi possível salvar: ' . $exception->getMessage());
        }
        $this->redirect('/calendar?section=availability&tenant_id=' . $tenantId);
    }

    public function request(): void
    {
        Csrf::validate($_POST['_token'] ?? null);
        $tenantId = $this->resolveTenantFromPost();
        $appointmentId = (int) ($_POST['appointment_id'] ?? 0);
        $returnTo = trim((string) ($_POST['return_to'] ?? ''));
        $result = (new CalendarAvailabilityService())->requestForAppointment($tenantId, $appointmentId, 'manual_panel');
        Flash::set(!empty($result['ok']) ? 'success' : 'warning', (string) ($result['message'] ?? 'Solicitação processada.'));
        $this->redirect($returnTo !== '' && str_starts_with($returnTo, '/') ? $returnTo : '/calendar?section=availability&tenant_id=' . $tenantId);
    }

    public function applySlot(): void
    {
        Csrf::validate($_POST['_token'] ?? null);
        $tenantId = $this->resolveTenantFromPost();
        $appointmentId = (int) ($_POST['appointment_id'] ?? 0);
        $slotId = (int) ($_POST['slot_id'] ?? 0);
        $returnTo = trim((string) ($_POST['return_to'] ?? ''));
        $result = (new CalendarAvailabilityService())->applySlot($tenantId, $appointmentId, $slotId);
        Flash::set(!empty($result['ok']) ? 'success' : 'error', (string) ($result['message'] ?? 'Horário processado.'));
        $this->redirect($returnTo !== '' && str_starts_with($returnTo, '/') ? $returnTo : '/calendar?section=availability&tenant_id=' . $tenantId);
    }

    public function releaseSlot(): void
    {
        Csrf::validate($_POST['_token'] ?? null);
        $tenantId = $this->resolveTenantFromPost();
        $appointmentId = (int) ($_POST['appointment_id'] ?? 0);
        $returnTo = trim((string) ($_POST['return_to'] ?? ''));
        $result = (new CalendarAvailabilityService())->releaseSelectedSlot($tenantId, $appointmentId);
        Flash::set(!empty($result['ok']) ? 'success' : 'error', (string) ($result['message'] ?? 'Liberação processada.'));
        $this->redirect($returnTo !== '' && str_starts_with($returnTo, '/') ? $returnTo : '/calendar?section=availability&tenant_id=' . $tenantId);
    }


    public function runMaintenance(): void
    {
        Csrf::validate($_POST['_token'] ?? null);
        $tenantId = $this->resolveTenantFromPost();
        if ($tenantId < 1) {
            Flash::set('error', 'Selecione uma empresa para executar a manutenção da agenda.');
            $this->redirect('/calendar?section=availability');
        }

        $result = (new CalendarGoogleLifecycleService())->runMaintenance($tenantId, 'manual');
        Audit::log('calendar.maintenance.manual', ['result' => $result], $tenantId);
        if (!empty($result['ok']) && ($result['status'] ?? '') === 'success') {
            Flash::set('success', 'Manutenção concluída. Pré-reservas vencidas, sincronizações e callbacks foram revisados.');
        } elseif (!empty($result['ok'])) {
            Flash::set('warning', 'Manutenção concluída com avisos. Abra os detalhes para revisar as ações que não puderam ser concluídas.');
        } else {
            Flash::set('error', 'Não foi possível concluir a manutenção da agenda: ' . (string) ($result['message'] ?? 'erro não informado'));
        }
        $this->redirect('/calendar?section=availability&tenant_id=' . $tenantId . '#calendar-maintenance');
    }

    public function maintenanceCron(): void
    {
        $token = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));
        $expected = trim((string) Env::get('CALENDAR_MAINTENANCE_TOKEN', ''));
        if ($expected === '' || !hash_equals($expected, $token)) {
            http_response_code(403);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['ok' => false, 'message' => 'Token inválido.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $tenantId = (int) ($_GET['tenant_id'] ?? $_POST['tenant_id'] ?? 0);
        $result = (new CalendarGoogleLifecycleService())->runMaintenance($tenantId > 0 ? $tenantId : null, 'cron');
        http_response_code(!empty($result['ok']) ? 200 : 500);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function callback(): void
    {
        $raw = file_get_contents('php://input') ?: '';
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            $payload = $_POST;
        }

        $bearer = trim((string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
        $bearerToken = str_starts_with($bearer, 'Bearer ') ? trim(substr($bearer, 7)) : '';
        $token = trim((string) (
            $_GET['token']
            ?? $_SERVER['HTTP_X_RS_CALENDAR_TOKEN']
            ?? $_SERVER['HTTP_X_RS_CONNECT_TOKEN']
            ?? $bearerToken
            ?? ''
        ));

        $service = new CalendarAvailabilityService();
        // A busca do Google devolve o callback antes de disparar WhatsApp/pré-reserva.
        // Isso evita o ciclo síncrono RS -> n8n -> RS -> n8n ultrapassar o timeout
        // enquanto o resultado já foi salvo corretamente no banco.
        $result = $service->handleCallback($payload, $token !== '' ? $token : null, true);
        $deferred = isset($result['_deferred_conversation']) && is_array($result['_deferred_conversation'])
            ? $result['_deferred_conversation']
            : null;
        unset($result['_deferred_conversation']);
        if ($deferred !== null) {
            $result['conversation_processing'] = 'queued';
        }

        if ($deferred !== null) {
            ignore_user_abort(true);
            @set_time_limit(120);
        }

        $this->respondJsonAndContinue($result, !empty($result['ok']) ? 200 : 400);

        if ($deferred !== null) {
            try {
                $service->processDeferredConversation(
                    (int) ($deferred['request_id'] ?? 0),
                    (string) ($deferred['request_token'] ?? ''),
                    (string) ($deferred['diagnostic'] ?? '')
                );
            } catch (Throwable $exception) {
                Audit::log('calendar.callback.deferred_failed', [
                    'request_id' => (int) ($deferred['request_id'] ?? 0),
                    'error' => mb_substr($exception->getMessage(), 0, 700),
                ]);
            }
        }
    }

    /**
     * Entrega o HTTP ao n8n antes das tarefas lentas de conversa.
     * Funciona com FastCGI e também com Apache mod_php usado no container atual.
     */
    private function respondJsonAndContinue(array $payload, int $status): void
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            $json = '{"ok":false,"message":"Falha ao montar resposta do callback."}';
            $status = 500;
        }

        @ini_set('zlib.output_compression', '0');
        if (function_exists('apache_setenv')) {
            @apache_setenv('no-gzip', '1');
        }

        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
        header('Content-Length: ' . strlen($json));
        header('Connection: close');
        echo $json;

        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
            return;
        }

        while (ob_get_level() > 0) {
            @ob_end_flush();
        }
        @flush();
    }

    private function resolveTenantFromQuery(): int
    {
        if (!Auth::isSuperAdmin()) {
            return (int) Auth::tenantId();
        }
        $requested = (int) ($_GET['tenant_id'] ?? 0);
        if ($requested > 0) {
            return $requested;
        }
        try {
            $first = Database::connection()->query('SELECT id FROM tenants WHERE status = "active" ORDER BY name LIMIT 1')->fetchColumn();
            return $first ? (int) $first : 0;
        } catch (Throwable) {
            return 0;
        }
    }

    private function resolveTenantFromPost(): int
    {
        return Auth::isSuperAdmin() ? (int) ($_POST['tenant_id'] ?? 0) : (int) Auth::tenantId();
    }

    private function redirect(string $path): never
    {
        header('Location: ' . Router::url($path));
        exit;
    }
}
