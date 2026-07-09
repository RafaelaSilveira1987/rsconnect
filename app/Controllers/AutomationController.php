<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Env;
use App\Core\View;
use PDO;

final class AutomationController
{
    public function index(): void
    {
        $pdo = Database::connection();
        $params = [];
        $where = '';

        if (!Auth::isSuperAdmin()) {
            $where = 'WHERE l.tenant_id = :tenant_id';
            $params['tenant_id'] = Auth::tenantId();
        }

        $statement = $pdo->prepare(
            'SELECT l.*, a.name AS agent_name, c.remote_jid, ct.name AS contact_name, ct.phone, t.name AS tenant_name
             FROM ai_automation_logs l
             LEFT JOIN ai_agents a ON a.id = l.agent_id
             LEFT JOIN conversations c ON c.id = l.conversation_id
             LEFT JOIN contacts ct ON ct.id = c.contact_id
             LEFT JOIN tenants t ON t.id = l.tenant_id
             ' . $where . '
             ORDER BY l.created_at DESC
             LIMIT 150'
        );
        $statement->execute($params);

        $statsStatement = $pdo->prepare(
            'SELECT status, COUNT(*) AS total
             FROM ai_automation_logs l
             ' . $where . '
             GROUP BY status'
        );
        $statsStatement->execute($params);
        $stats = [];
        foreach ($statsStatement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $stats[(string) $row['status']] = (int) $row['total'];
        }

        View::render('automations.index', [
            'title' => 'Automações',
            'logs' => $statement->fetchAll(PDO::FETCH_ASSOC),
            'stats' => $stats,
            'openaiConfigured' => trim((string) Env::get('OPENAI_API_KEY', '')) !== '',
            'geminiConfigured' => trim((string) Env::get('GEMINI_API_KEY', Env::get('GOOGLE_GEMINI_API_KEY', ''))) !== '',
            'n8nConfigured' => trim((string) Env::get('N8N_WEBHOOK_URL', '')) !== '',
            'autoReplyEnabled' => filter_var(Env::get('AI_AUTOREPLY_ENABLED', true), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) !== false,
        ]);
    }
}
