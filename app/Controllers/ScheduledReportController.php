<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Env;
use App\Core\Flash;
use App\Core\Router;
use App\Core\View;
use App\Services\ScheduledReportService;
use RuntimeException;
use Throwable;

final class ScheduledReportController
{
    public function index(): void
    {
        View::render('reports.automatic', [
            'title' => 'Relatórios automáticos',
            'data' => (new ScheduledReportService())->dashboard(Auth::tenantId(), Auth::isSuperAdmin()),
        ], 'app');
    }

    public function save(): void
    {
        $this->requireCsrf();
        try {
            (new ScheduledReportService())->saveSchedule(
                $_POST,
                (int) Auth::id(),
                Auth::isSuperAdmin(),
                Auth::tenantId()
            );
            $this->go('success', 'Programação salva. O próximo envio já foi calculado.');
        } catch (Throwable $exception) {
            $this->go('error', $exception->getMessage());
        }
    }

    public function generate(): void
    {
        $this->requireCsrf();
        try {
            $service = new ScheduledReportService();
            $scheduleUuid = trim((string) ($_POST['schedule_uuid'] ?? ''));
            $report = $scheduleUuid !== ''
                ? $service->generateScheduleNow($scheduleUuid, (int) Auth::id(), Auth::isSuperAdmin(), Auth::tenantId())
                : $service->generateManual($_POST, (int) Auth::id(), Auth::isSuperAdmin(), Auth::tenantId());

            $status = (string) ($report['status_label'] ?? $report['status'] ?? 'Pronto');
            $this->go('success', 'Relatório gerado. Situação: ' . $status . '.');
        } catch (Throwable $exception) {
            $this->go('error', $exception->getMessage());
        }
    }

    public function toggle(): void
    {
        $this->requireCsrf();
        try {
            $status = (new ScheduledReportService())->toggleSchedule(
                trim((string) ($_POST['schedule_uuid'] ?? '')),
                Auth::isSuperAdmin(),
                Auth::tenantId()
            );
            $this->go('success', $status === 'active' ? 'Programação ativada.' : 'Programação pausada.');
        } catch (Throwable $exception) {
            $this->go('error', $exception->getMessage());
        }
    }

    public function resend(): void
    {
        $this->requireCsrf();
        try {
            $report = (new ScheduledReportService())->resend(
                trim((string) ($_POST['report_uuid'] ?? '')),
                Auth::isSuperAdmin(),
                Auth::tenantId()
            );
            $this->go('success', 'Reenvio processado. Situação: ' . (string) ($report['status_label'] ?? $report['status'] ?? '') . '.');
        } catch (Throwable $exception) {
            $this->go('error', $exception->getMessage());
        }
    }

    public function download(): void
    {
        try {
            $file = (new ScheduledReportService())->downloadable(
                trim((string) ($_GET['report_uuid'] ?? '')),
                Auth::isSuperAdmin(),
                Auth::tenantId()
            );
        } catch (Throwable $exception) {
            http_response_code(404);
            View::render('errors.404', ['title' => 'Relatório não encontrado'], 'app');
            return;
        }

        $disposition = !empty($_GET['download']) ? 'attachment' : 'inline';
        header('Content-Type: ' . $file['mime']);
        header('Content-Length: ' . $file['size']);
        header('Content-Disposition: ' . $disposition . '; filename="' . addslashes($file['filename']) . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store, max-age=0');
        readfile($file['path']);
        exit;
    }

    public function cron(): void
    {
        $configured = trim((string) Env::get(
            'SCHEDULED_REPORTS_CRON_TOKEN',
            Env::get('OPERATIONS_MONITOR_TOKEN', '')
        ));
        $provided = trim((string) (
            $_SERVER['HTTP_X_RS_CONNECT_TOKEN']
            ?? $_SERVER['HTTP_AUTHORIZATION']
            ?? $_GET['token']
            ?? ''
        ));
        if (str_starts_with(strtolower($provided), 'bearer ')) {
            $provided = trim(substr($provided, 7));
        }

        if ($configured === '' || $provided === '' || !hash_equals($configured, $provided)) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'Token do monitor de relatórios inválido.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        try {
            $result = (new ScheduledReportService())->runDue((int) ($_GET['limit'] ?? 20));
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => true, 'result' => $result], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (Throwable $exception) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => false,
                'error' => 'Não foi possível executar os relatórios programados.',
                'detail' => $exception->getMessage(),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
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
        header('Location: ' . Router::url('/reports/automatic'));
        exit;
    }
}
