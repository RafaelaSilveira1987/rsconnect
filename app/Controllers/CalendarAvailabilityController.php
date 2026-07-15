<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Flash;
use App\Core\Router;
use App\Core\View;
use App\Services\CalendarAvailabilityService;
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
            'metrics' => ['pending' => 0, 'requests' => 0, 'slots' => 0, 'selected' => 0],
        ];

        View::render('calendar_availability.index', [
            'title' => 'Agenda inteligente',
            'tenantId' => $tenantId,
            'tenants' => $tenants,
            'settings' => $dashboard['settings'],
            'pending' => $dashboard['pending'],
            'requests' => $dashboard['requests'],
            'slots' => $dashboard['slots'],
            'metrics' => $dashboard['metrics'],
            'canManage' => Auth::can('calendar.manage'),
        ]);
    }

    public function saveSettings(): void
    {
        Csrf::validate($_POST['_token'] ?? null);
        $tenantId = $this->resolveTenantFromPost();
        if ($tenantId < 1) {
            Flash::set('error', 'Selecione uma empresa para salvar a configuração.');
            $this->redirect('/agenda-inteligente');
        }
        (new CalendarAvailabilityService())->saveSettings($tenantId, $_POST);
        Flash::set('success', 'Configuração de disponibilidade salva.');
        $this->redirect('/agenda-inteligente?tenant_id=' . $tenantId);
    }

    public function request(): void
    {
        Csrf::validate($_POST['_token'] ?? null);
        $tenantId = $this->resolveTenantFromPost();
        $appointmentId = (int) ($_POST['appointment_id'] ?? 0);
        $returnTo = trim((string) ($_POST['return_to'] ?? ''));
        $result = (new CalendarAvailabilityService())->requestForAppointment($tenantId, $appointmentId, 'manual_panel');
        Flash::set(!empty($result['ok']) ? 'success' : 'warning', (string) ($result['message'] ?? 'Solicitação processada.'));
        $this->redirect($returnTo !== '' && str_starts_with($returnTo, '/') ? $returnTo : '/agenda-inteligente?tenant_id=' . $tenantId);
    }

    public function applySlot(): void
    {
        Csrf::validate($_POST['_token'] ?? null);
        $tenantId = $this->resolveTenantFromPost();
        $appointmentId = (int) ($_POST['appointment_id'] ?? 0);
        $slotId = (int) ($_POST['slot_id'] ?? 0);
        $result = (new CalendarAvailabilityService())->applySlot($tenantId, $appointmentId, $slotId);
        Flash::set(!empty($result['ok']) ? 'success' : 'error', (string) ($result['message'] ?? 'Horário processado.'));
        $this->redirect('/agenda-inteligente?tenant_id=' . $tenantId);
    }

    public function callback(): void
    {
        $raw = file_get_contents('php://input') ?: '';
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            $payload = $_POST;
        }
        $token = trim((string) ($_GET['token'] ?? ($_SERVER['HTTP_X_RS_CONNECT_TOKEN'] ?? '')));
        $result = (new CalendarAvailabilityService())->handleCallback($payload, $token !== '' ? $token : null);
        http_response_code(!empty($result['ok']) ? 200 : 400);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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
