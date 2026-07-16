<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\View;
use App\Services\AdminExecutiveDashboardService;
use App\Services\NotificationService;
use PDO;

final class DashboardController
{
    public function index(): void
    {
        if (Auth::isSuperAdmin()) {
            $this->adminDashboard();
            return;
        }

        $tenantId = Auth::tenantId();
        $pdo = Database::connection();

        $instanceStatement = $pdo->prepare(
            'SELECT COUNT(*) AS total,
                    COALESCE(SUM(status = "connected"), 0) AS connected,
                    COALESCE(SUM(status = "disconnected"), 0) AS disconnected,
                    COALESCE(SUM(status = "pending"), 0) AS pending
             FROM evolution_instances
             WHERE tenant_id = :tenant_id'
        );
        $instanceStatement->execute(['tenant_id' => $tenantId]);
        $instances = $instanceStatement->fetch(PDO::FETCH_ASSOC) ?: [];

        $userStatement = $pdo->prepare('SELECT COUNT(*) FROM users WHERE tenant_id = :tenant_id AND status = "active"');
        $userStatement->execute(['tenant_id' => $tenantId]);

        $agentStatement = $pdo->prepare('SELECT COUNT(*) FROM ai_agents WHERE tenant_id = :tenant_id AND status = "active"');
        $agentStatement->execute(['tenant_id' => $tenantId]);

        $companyStatement = $pdo->prepare(
            'SELECT name, segment, onboarding_step, onboarding_completed_at
             FROM tenants WHERE id = :tenant_id LIMIT 1'
        );
        $companyStatement->execute(['tenant_id' => $tenantId]);

        $conversationStatement = $pdo->prepare(
            'SELECT COUNT(*) AS total,
                    COALESCE(SUM(status = "open"), 0) AS open_count,
                    COALESCE(SUM(unread_count), 0) AS unread_count,
                    COALESCE(SUM(attendance_mode = "human" AND status <> "closed"), 0) AS human_count
             FROM conversations
             WHERE tenant_id = :tenant_id'
        );
        $conversationStatement->execute(['tenant_id' => $tenantId]);

        $agendaIntent = ['total' => 0, 'pending_pre_schedules' => 0, 'approved_pre_schedules' => 0];
        try {
            $hasAgendaIntent = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'conversations' AND COLUMN_NAME = 'agenda_intent_detected'")->fetchColumn() > 0;
            if ($hasAgendaIntent) {
                $intentStatement = $pdo->prepare('SELECT COUNT(*) FROM conversations WHERE tenant_id = :tenant_id AND agenda_intent_detected = 1 AND status <> "closed"');
                $intentStatement->execute(['tenant_id' => $tenantId]);
                $agendaIntent['total'] = (int) $intentStatement->fetchColumn();
            }
            $hasPreSchedule = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'calendar_appointments' AND COLUMN_NAME = 'is_pre_schedule'")->fetchColumn() > 0;
            if ($hasPreSchedule) {
                $preStatement = $pdo->prepare('SELECT
                        COALESCE(SUM(is_pre_schedule = 1 AND status IN ("pre_scheduled", "awaiting_approval")), 0) AS pending_pre_schedules,
                        COALESCE(SUM(is_pre_schedule = 1 AND status = "confirmed"), 0) AS approved_pre_schedules
                    FROM calendar_appointments
                    WHERE tenant_id = :tenant_id');
                $preStatement->execute(['tenant_id' => $tenantId]);
                $pre = $preStatement->fetch(PDO::FETCH_ASSOC) ?: [];
                $agendaIntent['pending_pre_schedules'] = (int) ($pre['pending_pre_schedules'] ?? 0);
                $agendaIntent['approved_pre_schedules'] = (int) ($pre['approved_pre_schedules'] ?? 0);
            }
        } catch (\Throwable) {
            $agendaIntent = ['total' => 0, 'pending_pre_schedules' => 0, 'approved_pre_schedules' => 0];
        }

        $notificationService = new NotificationService();

        View::render('dashboard.client', [
            'title' => 'Dashboard',
            'instances' => $instances,
            'activeUsers' => (int) $userStatement->fetchColumn(),
            'activeAgents' => (int) $agentStatement->fetchColumn(),
            'conversations' => $conversationStatement->fetch(PDO::FETCH_ASSOC) ?: [],
            'company' => $companyStatement->fetch(PDO::FETCH_ASSOC) ?: [],
            'notifications' => $tenantId ? $notificationService->latestForTenant((int) $tenantId, 5) : [],
            'notificationUnreadCount' => $tenantId ? $notificationService->unreadCount((int) $tenantId) : 0,
            'agendaIntent' => $agendaIntent,
        ]);
    }

    private function adminDashboard(): void
    {
        $dashboardData = (new AdminExecutiveDashboardService())->dashboard();

        View::render('dashboard.admin', [
            'title' => 'Visão geral da operação',
            'dashboardData' => $dashboardData,
        ]);
    }
}
