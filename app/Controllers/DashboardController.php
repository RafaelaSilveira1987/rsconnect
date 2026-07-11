<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\View;
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
        ]);
    }


    public function live(): void
    {
        $pdo = Database::connection();
        $notificationService = new NotificationService();

        if (Auth::isSuperAdmin()) {
            $conversationUnread = (int) $pdo->query('SELECT COALESCE(SUM(unread_count), 0) FROM conversations')->fetchColumn();
            $openConversations = (int) $pdo->query('SELECT COUNT(*) FROM conversations WHERE status = "open"')->fetchColumn();
            $humanConversations = (int) $pdo->query('SELECT COUNT(*) FROM conversations WHERE attendance_mode = "human" AND status <> "closed"')->fetchColumn();

            $this->json([
                'ok' => true,
                'conversation_unread' => $conversationUnread,
                'notification_unread' => 0,
                'dashboard' => [
                    'open_conversations' => $openConversations,
                    'unread_conversations' => $conversationUnread,
                    'human_conversations' => $humanConversations,
                ],
                'notifications' => [],
            ]);
        }

        $tenantId = (int) Auth::tenantId();
        $conversationStatement = $pdo->prepare(
            'SELECT COUNT(*) AS total,
                    COALESCE(SUM(status = "open"), 0) AS open_count,
                    COALESCE(SUM(unread_count), 0) AS unread_count,
                    COALESCE(SUM(attendance_mode = "human" AND status <> "closed"), 0) AS human_count
             FROM conversations
             WHERE tenant_id = :tenant_id'
        );
        $conversationStatement->execute(['tenant_id' => $tenantId]);
        $conversation = $conversationStatement->fetch(PDO::FETCH_ASSOC) ?: [];

        $latest = array_map(static function (array $notification): array {
            return [
                'id' => (int) ($notification['id'] ?? 0),
                'title' => (string) ($notification['title'] ?? ''),
                'message' => (string) ($notification['message'] ?? ''),
                'severity' => (string) ($notification['severity'] ?? 'info'),
                'status' => (string) ($notification['status'] ?? 'read'),
                'action_url' => (string) ($notification['action_url'] ?? '/notifications'),
                'created_at' => (string) ($notification['created_at'] ?? ''),
            ];
        }, $notificationService->latestForTenant($tenantId, 5));

        $this->json([
            'ok' => true,
            'conversation_unread' => (int) ($conversation['unread_count'] ?? 0),
            'notification_unread' => $notificationService->unreadCount($tenantId),
            'dashboard' => [
                'open_conversations' => (int) ($conversation['open_count'] ?? 0),
                'unread_conversations' => (int) ($conversation['unread_count'] ?? 0),
                'human_conversations' => (int) ($conversation['human_count'] ?? 0),
            ],
            'notifications' => $latest,
        ]);
    }

    private function json(array $payload, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    private function adminDashboard(): void
    {
        $pdo = Database::connection();
        $metrics = [
            'tenants' => (int) $pdo->query('SELECT COUNT(*) FROM tenants')->fetchColumn(),
            'activeTenants' => (int) $pdo->query('SELECT COUNT(*) FROM tenants WHERE status = "active"')->fetchColumn(),
            'users' => (int) $pdo->query('SELECT COUNT(*) FROM users WHERE status = "active"')->fetchColumn(),
            'instances' => (int) $pdo->query('SELECT COUNT(*) FROM evolution_instances')->fetchColumn(),
            'agents' => (int) $pdo->query('SELECT COUNT(*) FROM ai_agents WHERE status = "active"')->fetchColumn(),
            'onboarded' => (int) $pdo->query('SELECT COUNT(*) FROM tenants WHERE onboarding_completed_at IS NOT NULL')->fetchColumn(),
            'conversations' => (int) $pdo->query('SELECT COUNT(*) FROM conversations')->fetchColumn(),
            'unread' => (int) $pdo->query('SELECT COALESCE(SUM(unread_count), 0) FROM conversations')->fetchColumn(),
        ];

        $tenants = $pdo->query(
            'SELECT t.id, t.name, t.slug, t.plan, t.status, t.onboarding_step, t.onboarding_completed_at, t.created_at,
                    COUNT(DISTINCT u.id) AS users_count,
                    COUNT(DISTINCT i.id) AS instances_count,
                    COUNT(DISTINCT a.id) AS agents_count,
                    COUNT(DISTINCT c.id) AS conversations_count
             FROM tenants t
             LEFT JOIN users u ON u.tenant_id = t.id
             LEFT JOIN evolution_instances i ON i.tenant_id = t.id
             LEFT JOIN ai_agents a ON a.tenant_id = t.id
             LEFT JOIN conversations c ON c.tenant_id = t.id
             GROUP BY t.id
             ORDER BY t.created_at DESC
             LIMIT 10'
        )->fetchAll(PDO::FETCH_ASSOC);

        View::render('dashboard.admin', [
            'title' => 'Painel RS',
            'metrics' => $metrics,
            'tenants' => $tenants,
        ]);
    }
}
