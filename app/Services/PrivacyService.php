<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use PDO;
use Throwable;

final class PrivacyService
{
    /** @return array<string,mixed> */
    public function settings(int $tenantId): array
    {
        if ($tenantId < 1 || !$this->tableExists('tenant_privacy_settings')) {
            return $this->defaultSettings($tenantId);
        }

        $statement = Database::connection()->prepare(
            'SELECT * FROM tenant_privacy_settings WHERE tenant_id = :tenant_id LIMIT 1'
        );
        $statement->execute(['tenant_id' => $tenantId]);
        $settings = $statement->fetch(PDO::FETCH_ASSOC);

        if (!$settings) {
            $this->ensureSettings($tenantId);
            $statement->execute(['tenant_id' => $tenantId]);
            $settings = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
        }

        return array_merge($this->defaultSettings($tenantId), $settings ?: []);
    }

    /** @return array<string,mixed> */
    public function defaultSettings(int $tenantId = 0): array
    {
        return [
            'tenant_id' => $tenantId,
            'require_company_acceptance' => 1,
            'policy_version' => 'v1',
            'privacy_policy_title' => 'Política de Privacidade',
            'privacy_policy_text' => 'Esta empresa utiliza o RS Connect para atendimento, CRM, agenda, automações e gestão de relacionamento. Os dados são tratados para executar o atendimento solicitado, manter histórico operacional, cumprir obrigações contratuais e melhorar a qualidade do serviço. Ajuste este texto conforme a operação da empresa.',
            'terms_title' => 'Termos de Uso e Tratamento de Dados',
            'terms_text' => 'Ao acessar o painel, a empresa e seus usuários declaram ciência sobre o uso do RS Connect para tratamento de dados pessoais necessários ao atendimento, gestão de contatos, conversas, agenda, cobrança, automações e registros de auditoria. Ajuste este termo antes de operar comercialmente.',
            'dpo_name' => '',
            'dpo_email' => '',
            'retention_days' => 365,
            'allow_export_requests' => 1,
            'allow_delete_requests' => 1,
        ];
    }

    /** @param array<string,mixed> $data */
    public function saveSettings(int $tenantId, array $data): void
    {
        if ($tenantId < 1 || !$this->tableExists('tenant_privacy_settings')) {
            return;
        }

        $policyVersion = trim((string) ($data['policy_version'] ?? 'v1')) ?: 'v1';
        $retentionDays = max(30, min(3650, (int) ($data['retention_days'] ?? 365)));

        $statement = Database::connection()->prepare(
            'INSERT INTO tenant_privacy_settings
                (tenant_id, require_company_acceptance, policy_version, privacy_policy_title, privacy_policy_text,
                 terms_title, terms_text, dpo_name, dpo_email, retention_days,
                 allow_export_requests, allow_delete_requests, updated_by)
             VALUES
                (:tenant_id, :require_company_acceptance, :policy_version, :privacy_policy_title, :privacy_policy_text,
                 :terms_title, :terms_text, :dpo_name, :dpo_email, :retention_days,
                 :allow_export_requests, :allow_delete_requests, :updated_by)
             ON DUPLICATE KEY UPDATE
                require_company_acceptance = VALUES(require_company_acceptance),
                policy_version = VALUES(policy_version),
                privacy_policy_title = VALUES(privacy_policy_title),
                privacy_policy_text = VALUES(privacy_policy_text),
                terms_title = VALUES(terms_title),
                terms_text = VALUES(terms_text),
                dpo_name = VALUES(dpo_name),
                dpo_email = VALUES(dpo_email),
                retention_days = VALUES(retention_days),
                allow_export_requests = VALUES(allow_export_requests),
                allow_delete_requests = VALUES(allow_delete_requests),
                updated_by = VALUES(updated_by),
                updated_at = CURRENT_TIMESTAMP'
        );
        $statement->execute([
            'tenant_id' => $tenantId,
            'require_company_acceptance' => !empty($data['require_company_acceptance']) ? 1 : 0,
            'policy_version' => $policyVersion,
            'privacy_policy_title' => trim((string) ($data['privacy_policy_title'] ?? 'Política de Privacidade')) ?: 'Política de Privacidade',
            'privacy_policy_text' => trim((string) ($data['privacy_policy_text'] ?? '')),
            'terms_title' => trim((string) ($data['terms_title'] ?? 'Termos de Uso e Tratamento de Dados')) ?: 'Termos de Uso e Tratamento de Dados',
            'terms_text' => trim((string) ($data['terms_text'] ?? '')),
            'dpo_name' => trim((string) ($data['dpo_name'] ?? '')) ?: null,
            'dpo_email' => filter_var((string) ($data['dpo_email'] ?? ''), FILTER_VALIDATE_EMAIL) ? mb_strtolower(trim((string) $data['dpo_email'])) : null,
            'retention_days' => $retentionDays,
            'allow_export_requests' => !empty($data['allow_export_requests']) ? 1 : 0,
            'allow_delete_requests' => !empty($data['allow_delete_requests']) ? 1 : 0,
            'updated_by' => Auth::id(),
        ]);
    }

    public function requiresAcceptance(?int $tenantId, ?int $userId): bool
    {
        if (!$tenantId || !$userId || !$this->tableExists('tenant_terms_acceptances')) {
            return false;
        }

        $settings = $this->settings($tenantId);
        if (empty($settings['require_company_acceptance'])) {
            return false;
        }

        return !$this->hasAcceptedCurrentPolicy($tenantId, $userId);
    }

    public function hasAcceptedCurrentPolicy(int $tenantId, int $userId): bool
    {
        $settings = $this->settings($tenantId);
        $hash = $this->termsHash($settings);
        $statement = Database::connection()->prepare(
            'SELECT COUNT(*) FROM tenant_terms_acceptances
             WHERE tenant_id = :tenant_id AND user_id = :user_id AND policy_version = :policy_version AND terms_hash = :terms_hash'
        );
        $statement->execute([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'policy_version' => (string) ($settings['policy_version'] ?? 'v1'),
            'terms_hash' => $hash,
        ]);
        return (int) $statement->fetchColumn() > 0;
    }

    public function acceptCurrentPolicy(int $tenantId, int $userId): void
    {
        $settings = $this->settings($tenantId);
        $statement = Database::connection()->prepare(
            'INSERT INTO tenant_terms_acceptances
                (tenant_id, user_id, policy_version, terms_hash, accepted_at, ip_address, user_agent)
             VALUES
                (:tenant_id, :user_id, :policy_version, :terms_hash, NOW(), :ip_address, :user_agent)
             ON DUPLICATE KEY UPDATE
                terms_hash = VALUES(terms_hash),
                accepted_at = VALUES(accepted_at),
                ip_address = VALUES(ip_address),
                user_agent = VALUES(user_agent)'
        );
        $statement->execute([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'policy_version' => (string) ($settings['policy_version'] ?? 'v1'),
            'terms_hash' => $this->termsHash($settings),
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
        ]);
    }

    /** @return array<string,mixed> */
    public function dashboard(?int $tenantId = null): array
    {
        $pdo = Database::connection();
        $params = [];
        $where = '';
        if ($tenantId) {
            $where = ' WHERE tenant_id = :tenant_id';
            $params['tenant_id'] = $tenantId;
        }

        $counts = [
            'open_requests' => 0,
            'completed_requests' => 0,
            'acceptances' => 0,
            'consents' => 0,
            'companies_without_settings' => 0,
        ];

        try {
            if ($this->tableExists('privacy_requests')) {
                $statement = $pdo->prepare('SELECT COUNT(*) FROM privacy_requests' . $where . ($where ? ' AND status IN ("open", "processing")' : ' WHERE status IN ("open", "processing")'));
                $statement->execute($params);
                $counts['open_requests'] = (int) $statement->fetchColumn();

                $statement = $pdo->prepare('SELECT COUNT(*) FROM privacy_requests' . $where . ($where ? ' AND status = "completed"' : ' WHERE status = "completed"'));
                $statement->execute($params);
                $counts['completed_requests'] = (int) $statement->fetchColumn();
            }
            if ($this->tableExists('tenant_terms_acceptances')) {
                $statement = $pdo->prepare('SELECT COUNT(*) FROM tenant_terms_acceptances' . $where);
                $statement->execute($params);
                $counts['acceptances'] = (int) $statement->fetchColumn();
            }
            if ($this->tableExists('privacy_consents')) {
                $statement = $pdo->prepare('SELECT COUNT(*) FROM privacy_consents' . $where);
                $statement->execute($params);
                $counts['consents'] = (int) $statement->fetchColumn();
            }
            if (!$tenantId && $this->tableExists('tenant_privacy_settings')) {
                $counts['companies_without_settings'] = (int) $pdo->query('SELECT COUNT(*) FROM tenants t LEFT JOIN tenant_privacy_settings ps ON ps.tenant_id = t.id WHERE ps.tenant_id IS NULL')->fetchColumn();
            }
        } catch (Throwable) {
            // Mantém painel resiliente caso alguma tabela ainda não exista.
        }

        return $counts;
    }

    /** @return array<int,array<string,mixed>> */
    public function requests(?int $tenantId = null, int $limit = 80): array
    {
        if (!$this->tableExists('privacy_requests')) {
            return [];
        }
        $sql = 'SELECT pr.*, t.name AS tenant_name, c.name AS contact_name
                FROM privacy_requests pr
                INNER JOIN tenants t ON t.id = pr.tenant_id
                LEFT JOIN contacts c ON c.id = pr.contact_id';
        $params = [];
        if ($tenantId) {
            $sql .= ' WHERE pr.tenant_id = :tenant_id';
            $params['tenant_id'] = $tenantId;
        }
        $sql .= ' ORDER BY pr.requested_at DESC LIMIT ' . max(10, min(300, $limit));
        $statement = Database::connection()->prepare($sql);
        $statement->execute($params);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<int,array<string,mixed>> */
    public function acceptances(int $tenantId, int $limit = 50): array
    {
        if (!$this->tableExists('tenant_terms_acceptances')) {
            return [];
        }
        $statement = Database::connection()->prepare(
            'SELECT a.*, u.name AS user_name, u.email
             FROM tenant_terms_acceptances a
             INNER JOIN users u ON u.id = a.user_id
             WHERE a.tenant_id = :tenant_id
             ORDER BY a.accepted_at DESC
             LIMIT ' . max(10, min(200, $limit))
        );
        $statement->execute(['tenant_id' => $tenantId]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<int,array<string,mixed>> */
    public function tenantsOverview(): array
    {
        if (!$this->tableExists('tenant_privacy_settings')) {
            return [];
        }
        $statement = Database::connection()->query(
            'SELECT t.id, t.name, t.email, t.status,
                    ps.policy_version, ps.require_company_acceptance, ps.dpo_email, ps.retention_days,
                    (SELECT COUNT(*) FROM tenant_terms_acceptances a WHERE a.tenant_id = t.id) AS acceptances_count,
                    (SELECT COUNT(*) FROM privacy_requests pr WHERE pr.tenant_id = t.id AND pr.status IN ("open", "processing")) AS open_requests_count
             FROM tenants t
             LEFT JOIN tenant_privacy_settings ps ON ps.tenant_id = t.id
             ORDER BY t.name ASC'
        );
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @param array<string,mixed> $data */
    public function createRequest(int $tenantId, array $data): void
    {
        if (!$this->tableExists('privacy_requests')) {
            return;
        }
        $type = (string) ($data['request_type'] ?? 'export');
        if (!in_array($type, ['export', 'delete', 'anonymize', 'consent_review', 'other'], true)) {
            $type = 'export';
        }
        $statement = Database::connection()->prepare(
            'INSERT INTO privacy_requests
                (tenant_id, contact_id, requester_name, requester_email, requester_phone, request_type, notes, requested_by)
             VALUES
                (:tenant_id, :contact_id, :requester_name, :requester_email, :requester_phone, :request_type, :notes, :requested_by)'
        );
        $statement->execute([
            'tenant_id' => $tenantId,
            'contact_id' => ((int) ($data['contact_id'] ?? 0)) ?: null,
            'requester_name' => trim((string) ($data['requester_name'] ?? '')) ?: null,
            'requester_email' => filter_var((string) ($data['requester_email'] ?? ''), FILTER_VALIDATE_EMAIL) ? mb_strtolower(trim((string) $data['requester_email'])) : null,
            'requester_phone' => preg_replace('/\D+/', '', (string) ($data['requester_phone'] ?? '')) ?: null,
            'request_type' => $type,
            'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
            'requested_by' => Auth::id(),
        ]);
    }

    public function updateRequest(int $requestId, string $status, string $responseSummary = ''): void
    {
        if (!$this->tableExists('privacy_requests') || !in_array($status, ['open', 'processing', 'completed', 'rejected'], true)) {
            return;
        }
        $statement = Database::connection()->prepare(
            'UPDATE privacy_requests
             SET status = :status,
                 response_summary = :response_summary,
                 processed_by = :processed_by,
                 processed_at = CASE WHEN :done = 1 THEN NOW() ELSE processed_at END
             WHERE id = :id'
        );
        $statement->execute([
            'status' => $status,
            'response_summary' => $responseSummary !== '' ? $responseSummary : null,
            'processed_by' => Auth::id(),
            'done' => in_array($status, ['completed', 'rejected'], true) ? 1 : 0,
            'id' => $requestId,
        ]);
    }

    /** @return array<string,mixed> */
    public function exportContactData(int $tenantId, int $contactId): array
    {
        $pdo = Database::connection();
        $contactStatement = $pdo->prepare('SELECT * FROM contacts WHERE tenant_id = :tenant_id AND id = :id LIMIT 1');
        $contactStatement->execute(['tenant_id' => $tenantId, 'id' => $contactId]);
        $contact = $contactStatement->fetch(PDO::FETCH_ASSOC) ?: [];
        if (!$contact) {
            return [];
        }
        $conversationStatement = $pdo->prepare('SELECT * FROM conversations WHERE tenant_id = :tenant_id AND contact_id = :contact_id ORDER BY created_at DESC LIMIT 100');
        $conversationStatement->execute(['tenant_id' => $tenantId, 'contact_id' => $contactId]);
        $conversations = $conversationStatement->fetchAll(PDO::FETCH_ASSOC);
        $messageStatement = $pdo->prepare(
            'SELECT m.id, m.conversation_id, m.direction, m.sender_type, m.message_type, m.content, m.status, m.sent_at
             FROM conversation_messages m
             INNER JOIN conversations c ON c.id = m.conversation_id
             WHERE m.tenant_id = :tenant_id AND c.contact_id = :contact_id
             ORDER BY m.sent_at DESC LIMIT 500'
        );
        $messageStatement->execute(['tenant_id' => $tenantId, 'contact_id' => $contactId]);

        return [
            'generated_at' => date('c'),
            'tenant_id' => $tenantId,
            'contact' => $contact,
            'conversations' => $conversations,
            'messages' => $messageStatement->fetchAll(PDO::FETCH_ASSOC),
        ];
    }

    /** @param array<string,mixed> $settings */
    public function termsHash(array $settings): string
    {
        return hash('sha256', implode('|', [
            (string) ($settings['policy_version'] ?? 'v1'),
            (string) ($settings['privacy_policy_text'] ?? ''),
            (string) ($settings['terms_text'] ?? ''),
        ]));
    }

    private function ensureSettings(int $tenantId): void
    {
        try {
            $defaults = $this->defaultSettings($tenantId);
            $this->saveSettings($tenantId, $defaults);
        } catch (Throwable) {
            // Se a migration ainda não foi executada, não quebra navegação.
        }
    }

    private function tableExists(string $table): bool
    {
        try {
            $statement = Database::connection()->prepare(
                'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table'
            );
            $statement->execute(['table' => $table]);
            return (int) $statement->fetchColumn() > 0;
        } catch (Throwable) {
            return false;
        }
    }
}
