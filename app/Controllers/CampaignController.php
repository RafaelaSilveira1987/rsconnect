<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Crypto;
use App\Core\Database;
use App\Core\Env;
use App\Core\Flash;
use App\Core\Router;
use App\Core\View;
use App\Services\EvolutionService;
use PDO;
use Throwable;

final class CampaignController
{
    private array $statusLabels = [
        'draft' => 'Rascunho',
        'queued' => 'Na fila',
        'sending' => 'Enviando',
        'paused' => 'Pausada',
        'completed' => 'Concluída',
        'cancelled' => 'Cancelada',
    ];

    private array $approvalLabels = [
        'draft' => 'Rascunho',
        'pending' => 'Aguardando aprovação',
        'approved' => 'Aprovada',
        'rejected' => 'Rejeitada',
    ];

    public function index(): void
    {
        $pdo = Database::connection();
        $filters = [
            'tenant_id' => Auth::isSuperAdmin() ? (int) ($_GET['tenant_id'] ?? 0) : (int) Auth::tenantId(),
            'status' => trim((string) ($_GET['status'] ?? '')),
            'search' => trim((string) ($_GET['search'] ?? '')),
        ];

        $where = [];
        $params = [];
        if (Auth::isSuperAdmin()) {
            if ($filters['tenant_id'] > 0) {
                $where[] = 'c.tenant_id = :tenant_id';
                $params['tenant_id'] = $filters['tenant_id'];
            }
        } else {
            $where[] = 'c.tenant_id = :tenant_id';
            $params['tenant_id'] = Auth::tenantId();
        }
        if (array_key_exists($filters['status'], $this->statusLabels)) {
            $where[] = 'c.status = :status';
            $params['status'] = $filters['status'];
        }
        if ($filters['search'] !== '') {
            $where[] = '(c.name LIKE :search OR c.description LIKE :search)';
            $params['search'] = '%' . $filters['search'] . '%';
        }
        $sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $statement = $pdo->prepare(
            'SELECT c.*, t.name AS tenant_name, i.name AS instance_name, u.name AS created_by_name,
                    COALESCE(r.total_recipients, 0) AS recipients_total,
                    COALESCE(r.queued_count, 0) AS recipients_queued,
                    COALESCE(r.sent_count, 0) AS recipients_sent,
                    COALESCE(r.failed_count, 0) AS recipients_failed,
                    COALESCE(r.skipped_count, 0) AS recipients_skipped
             FROM message_campaigns c
             INNER JOIN tenants t ON t.id = c.tenant_id
             LEFT JOIN evolution_instances i ON i.id = c.evolution_instance_id
             LEFT JOIN users u ON u.id = c.created_by
             LEFT JOIN (
                SELECT campaign_id,
                       COUNT(*) AS total_recipients,
                       SUM(status = "queued") AS queued_count,
                       SUM(status = "sent") AS sent_count,
                       SUM(status = "failed") AS failed_count,
                       SUM(status IN ("skipped", "opted_out")) AS skipped_count
                FROM message_campaign_recipients
                GROUP BY campaign_id
             ) r ON r.campaign_id = c.id
             ' . $sqlWhere . '
             ORDER BY c.created_at DESC
             LIMIT 200'
        );
        $statement->execute($params);
        $campaigns = $statement->fetchAll(PDO::FETCH_ASSOC);

        $selected = null;
        $recipients = [];
        $campaignId = (int) ($_GET['campaign_id'] ?? 0);
        if ($campaignId > 0) {
            $selected = $this->findCampaign($campaignId);
            if ($selected) {
                $recipientsStatement = $pdo->prepare(
                    'SELECT r.*, ct.name AS contact_name, ct.status AS contact_status
                     FROM message_campaign_recipients r
                     LEFT JOIN contacts ct ON ct.id = r.contact_id
                     WHERE r.campaign_id = :campaign_id
                     ORDER BY r.id DESC
                     LIMIT 250'
                );
                $recipientsStatement->execute(['campaign_id' => $campaignId]);
                $recipients = $recipientsStatement->fetchAll(PDO::FETCH_ASSOC);
            }
        }

        $tenants = [];
        if (Auth::isSuperAdmin()) {
            $tenants = $pdo->query('SELECT id, name FROM tenants WHERE status = "active" ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
        }

        $instanceSql = 'SELECT i.id, i.tenant_id, i.name, i.status, t.name AS tenant_name
                        FROM evolution_instances i
                        INNER JOIN tenants t ON t.id = i.tenant_id';
        $instanceParams = [];
        if (!Auth::isSuperAdmin()) {
            $instanceSql .= ' WHERE i.tenant_id = :tenant_id';
            $instanceParams['tenant_id'] = Auth::tenantId();
        } elseif ($filters['tenant_id'] > 0) {
            $instanceSql .= ' WHERE i.tenant_id = :tenant_id';
            $instanceParams['tenant_id'] = $filters['tenant_id'];
        }
        $instanceSql .= ' ORDER BY t.name, i.name';
        $instanceStatement = $pdo->prepare($instanceSql);
        $instanceStatement->execute($instanceParams);
        $instances = $instanceStatement->fetchAll(PDO::FETCH_ASSOC);

        $metrics = $this->metrics($filters['tenant_id']);

        View::render('campaigns.index', [
            'title' => 'Campanhas',
            'campaigns' => $campaigns,
            'selected' => $selected,
            'recipients' => $recipients,
            'tenants' => $tenants,
            'instances' => $instances,
            'filters' => $filters,
            'metrics' => $metrics,
            'statusLabels' => $this->statusLabels,
            'approvalLabels' => $this->approvalLabels,
            'canManage' => Auth::can('campaigns.manage'),
        ]);
    }

    public function store(): void
    {
        $tenantId = $this->postedTenantId();
        $name = trim((string) ($_POST['name'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $instanceId = (int) ($_POST['evolution_instance_id'] ?? 0);
        $audience = (string) ($_POST['audience_filter'] ?? 'all_leads');
        $tagFilter = trim((string) ($_POST['tag_filter'] ?? ''));
        $manualNumbers = trim((string) ($_POST['manual_numbers'] ?? ''));
        $message = trim((string) ($_POST['message_template'] ?? ''));
        $scheduledAt = trim((string) ($_POST['scheduled_at'] ?? ''));

        if ($tenantId < 1 || $name === '' || $instanceId < 1 || $message === '') {
            Flash::set('error', 'Informe empresa, nome, instância e mensagem da campanha.');
            $this->redirect('/campaigns');
        }
        if (!$this->instanceBelongsToTenant($instanceId, $tenantId)) {
            Flash::set('error', 'A instância selecionada não pertence à empresa.');
            $this->redirect('/campaigns');
        }
        if (!in_array($audience, ['all_leads', 'customers', 'tag', 'manual'], true)) {
            $audience = 'all_leads';
        }
        if ($audience === 'tag' && $tagFilter === '') {
            Flash::set('error', 'Informe a tag para criar uma campanha por tag.');
            $this->redirect('/campaigns');
        }
        if ($audience === 'manual' && $manualNumbers === '') {
            Flash::set('error', 'Informe os telefones para campanha manual.');
            $this->redirect('/campaigns');
        }

        $scheduled = null;
        if ($scheduledAt !== '') {
            $timestamp = strtotime($scheduledAt);
            $scheduled = $timestamp ? date('Y-m-d H:i:s', $timestamp) : null;
        }

        $pdo = Database::connection();
        try {
            $statement = $pdo->prepare(
                'INSERT INTO message_campaigns
                    (tenant_id, evolution_instance_id, name, description, audience_filter, tag_filter,
                     manual_numbers_text, message_template, scheduled_at, status, approval_status, created_by)
                 VALUES
                    (:tenant_id, :instance_id, :name, :description, :audience, :tag_filter,
                     :manual_numbers, :message_template, :scheduled_at, "draft", "draft", :created_by)'
            );
            $statement->execute([
                'tenant_id' => $tenantId,
                'instance_id' => $instanceId,
                'name' => $name,
                'description' => $description !== '' ? $description : null,
                'audience' => $audience,
                'tag_filter' => $tagFilter !== '' ? $tagFilter : null,
                'manual_numbers' => $manualNumbers !== '' ? $manualNumbers : null,
                'message_template' => $message,
                'scheduled_at' => $scheduled,
                'created_by' => Auth::id(),
            ]);
            $campaignId = (int) $pdo->lastInsertId();
            Audit::log('campaign.created', ['campaign_id' => $campaignId, 'audience' => $audience], $tenantId);
            Flash::set('success', 'Campanha criada como rascunho. Gere a audiência antes de aprovar o envio.');
            $this->redirect('/campaigns?campaign_id=' . $campaignId);
        } catch (Throwable $exception) {
            Flash::set('error', 'Não foi possível criar a campanha: ' . $exception->getMessage());
            $this->redirect('/campaigns');
        }
    }

    public function buildAudience(): void
    {
        $campaign = $this->postedCampaign();
        if (!$campaign) {
            Flash::set('error', 'Campanha não encontrada.');
            $this->redirect('/campaigns');
        }
        if (!in_array($campaign['status'], ['draft', 'paused', 'queued'], true)) {
            Flash::set('error', 'A audiência só pode ser gerada em campanhas em rascunho, pausadas ou na fila.');
            $this->redirect('/campaigns?campaign_id=' . (int) $campaign['id']);
        }

        $pdo = Database::connection();
        $contacts = $this->resolveAudience($campaign);

        try {
            $pdo->beginTransaction();
            $pdo->prepare('DELETE FROM message_campaign_recipients WHERE campaign_id = :campaign_id AND status IN ("queued", "skipped", "opted_out")')
                ->execute(['campaign_id' => $campaign['id']]);

            $insert = $pdo->prepare(
                'INSERT IGNORE INTO message_campaign_recipients
                    (campaign_id, tenant_id, contact_id, phone, name, personalized_message, status, error_message)
                 VALUES
                    (:campaign_id, :tenant_id, :contact_id, :phone, :name, :message, :status, :error_message)'
            );

            $queued = 0;
            $skipped = 0;
            foreach ($contacts as $contact) {
                $phone = preg_replace('/\D+/', '', (string) ($contact['phone'] ?? ''));
                if (strlen($phone) < 10) {
                    $skipped++;
                    continue;
                }
                $status = 'queued';
                $error = null;
                if (!empty($contact['opt_out_at']) || (isset($contact['marketing_opt_in']) && (int) $contact['marketing_opt_in'] === 0)) {
                    $status = 'opted_out';
                    $error = 'Contato sem permissão para campanhas.';
                    $skipped++;
                } else {
                    $queued++;
                }

                $insert->execute([
                    'campaign_id' => $campaign['id'],
                    'tenant_id' => $campaign['tenant_id'],
                    'contact_id' => $contact['id'] ?? null,
                    'phone' => $phone,
                    'name' => $contact['name'] ?? null,
                    'message' => $this->personalize((string) $campaign['message_template'], $contact, (string) $campaign['tenant_name']),
                    'status' => $status,
                    'error_message' => $error,
                ]);
            }

            $pdo->prepare(
                'UPDATE message_campaigns
                 SET status = "queued", approval_status = IF(approval_status = "draft", "pending", approval_status), total_recipients = :total,
                     queued_count = :queued, skipped_count = :skipped, updated_at = NOW()
                 WHERE id = :id'
            )->execute([
                'total' => count($contacts),
                'queued' => $queued,
                'skipped' => $skipped,
                'id' => $campaign['id'],
            ]);

            $this->log((int) $campaign['id'], (int) $campaign['tenant_id'], 'audience_built', 'success', 'Audiência gerada.', ['queued' => $queued, 'skipped' => $skipped]);
            $pdo->commit();
            Flash::set('success', 'Audiência gerada. Contatos na fila: ' . $queued . '.');
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Flash::set('error', 'Falha ao gerar audiência: ' . $exception->getMessage());
        }

        $this->redirect('/campaigns?campaign_id=' . (int) $campaign['id']);
    }

    public function approve(): void
    {
        $campaign = $this->postedCampaign();
        if (!$campaign) {
            Flash::set('error', 'Campanha não encontrada.');
            $this->redirect('/campaigns');
        }
        $queued = $this->queuedCount((int) $campaign['id']);
        if ($queued < 1) {
            Flash::set('error', 'Gere a audiência antes de aprovar a campanha.');
            $this->redirect('/campaigns?campaign_id=' . (int) $campaign['id']);
        }

        Database::connection()->prepare(
            'UPDATE message_campaigns
             SET approval_status = "approved", status = "queued", approved_by = :approved_by, approved_at = NOW(), updated_at = NOW()
             WHERE id = :id'
        )->execute(['approved_by' => Auth::id(), 'id' => $campaign['id']]);

        $this->log((int) $campaign['id'], (int) $campaign['tenant_id'], 'approved', 'success', 'Campanha aprovada para envio.');
        Flash::set('success', 'Campanha aprovada. Use Disparar lote para enviar de forma controlada.');
        $this->redirect('/campaigns?campaign_id=' . (int) $campaign['id']);
    }

    public function dispatch(): void
    {
        $campaign = $this->postedCampaign();
        if (!$campaign) {
            Flash::set('error', 'Campanha não encontrada.');
            $this->redirect('/campaigns');
        }
        if (($campaign['approval_status'] ?? '') !== 'approved') {
            Flash::set('error', 'A campanha precisa estar aprovada antes do envio.');
            $this->redirect('/campaigns?campaign_id=' . (int) $campaign['id']);
        }
        if (!in_array($campaign['status'], ['queued', 'sending'], true)) {
            Flash::set('error', 'Somente campanhas na fila podem ser enviadas.');
            $this->redirect('/campaigns?campaign_id=' . (int) $campaign['id']);
        }

        $batchSize = (int) ($_POST['batch_size'] ?? 10);
        $batchSize = max(1, min(50, $batchSize));
        $pdo = Database::connection();

        $recipientsStatement = $pdo->prepare(
            'SELECT * FROM message_campaign_recipients
             WHERE campaign_id = :campaign_id AND status = "queued"
             ORDER BY id ASC
             LIMIT ' . $batchSize
        );
        $recipientsStatement->execute(['campaign_id' => $campaign['id']]);
        $recipients = $recipientsStatement->fetchAll(PDO::FETCH_ASSOC);
        if (!$recipients) {
            $this->completeIfDone((int) $campaign['id']);
            Flash::set('success', 'Não havia contatos pendentes. Campanha revisada.');
            $this->redirect('/campaigns?campaign_id=' . (int) $campaign['id']);
        }

        $sent = 0;
        $failed = 0;
        try {
            $service = $this->serviceForCampaign($campaign);
        } catch (Throwable $exception) {
            Flash::set('error', 'Falha ao preparar a Evolution: ' . $exception->getMessage());
            $this->redirect('/campaigns?campaign_id=' . (int) $campaign['id']);
        }

        $pdo->prepare('UPDATE message_campaigns SET status = "sending", updated_at = NOW() WHERE id = :id')->execute(['id' => $campaign['id']]);

        foreach ($recipients as $recipient) {
            try {
                $result = $service->sendText((string) $recipient['phone'], (string) $recipient['personalized_message']);
                $pdo->prepare(
                    'UPDATE message_campaign_recipients
                     SET status = "sent", sent_at = NOW(), external_response_json = :external_response, error_message = NULL, updated_at = NOW()
                     WHERE id = :id'
                )->execute([
                    'external_response' => json_encode($result['body'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'id' => $recipient['id'],
                ]);
                $this->registerConversationMessage($campaign, $recipient, $result['body'] ?? []);
                $sent++;
            } catch (Throwable $exception) {
                $pdo->prepare(
                    'UPDATE message_campaign_recipients
                     SET status = "failed", error_message = :error_message, updated_at = NOW()
                     WHERE id = :id'
                )->execute([
                    'error_message' => mb_substr($exception->getMessage(), 0, 500),
                    'id' => $recipient['id'],
                ]);
                $failed++;
            }
        }

        $this->refreshCounters((int) $campaign['id']);
        $this->completeIfDone((int) $campaign['id']);
        $this->log((int) $campaign['id'], (int) $campaign['tenant_id'], 'dispatch_batch', $failed ? 'partial' : 'success', 'Lote processado.', ['sent' => $sent, 'failed' => $failed]);

        Flash::set($failed ? 'warning' : 'success', 'Lote processado. Enviadas: ' . $sent . '. Falhas: ' . $failed . '.');
        $this->redirect('/campaigns?campaign_id=' . (int) $campaign['id']);
    }

    public function status(): void
    {
        $campaign = $this->postedCampaign();
        $status = (string) ($_POST['status'] ?? '');
        if (!$campaign || !in_array($status, ['paused', 'cancelled', 'queued'], true)) {
            Flash::set('error', 'Ação inválida para campanha.');
            $this->redirect('/campaigns');
        }
        if ($status === 'queued' && ($campaign['approval_status'] ?? '') !== 'approved') {
            Flash::set('error', 'A campanha precisa estar aprovada para voltar à fila.');
            $this->redirect('/campaigns?campaign_id=' . (int) $campaign['id']);
        }

        Database::connection()->prepare('UPDATE message_campaigns SET status = :status, updated_at = NOW() WHERE id = :id')
            ->execute(['status' => $status, 'id' => $campaign['id']]);
        $this->log((int) $campaign['id'], (int) $campaign['tenant_id'], 'status_changed', 'success', 'Status alterado para ' . $status . '.');
        Flash::set('success', 'Status da campanha atualizado.');
        $this->redirect('/campaigns?campaign_id=' . (int) $campaign['id']);
    }

    private function postedCampaign(): ?array
    {
        return $this->findCampaign((int) ($_POST['campaign_id'] ?? 0));
    }

    private function findCampaign(int $id): ?array
    {
        if ($id < 1) {
            return null;
        }
        $sql = 'SELECT c.*, t.name AS tenant_name, i.name AS instance_name, i.instance_name, i.base_url, i.api_key_encrypted
                FROM message_campaigns c
                INNER JOIN tenants t ON t.id = c.tenant_id
                INNER JOIN evolution_instances i ON i.id = c.evolution_instance_id
                WHERE c.id = :id';
        $params = ['id' => $id];
        if (!Auth::isSuperAdmin()) {
            $sql .= ' AND c.tenant_id = :tenant_id';
            $params['tenant_id'] = Auth::tenantId();
        }
        $statement = Database::connection()->prepare($sql . ' LIMIT 1');
        $statement->execute($params);
        $campaign = $statement->fetch(PDO::FETCH_ASSOC);
        return $campaign ?: null;
    }

    private function resolveAudience(array $campaign): array
    {
        if ($campaign['audience_filter'] === 'manual') {
            $rows = [];
            foreach (preg_split('/[\r\n,;]+/', (string) $campaign['manual_numbers_text']) ?: [] as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $parts = array_map('trim', explode('|', $line));
                $phone = preg_replace('/\D+/', '', $parts[0] ?? '');
                if (strlen($phone) < 10) {
                    continue;
                }
                $rows[$phone] = [
                    'id' => null,
                    'name' => $parts[1] ?? '',
                    'phone' => $phone,
                    'status' => 'manual',
                    'marketing_opt_in' => 1,
                    'opt_out_at' => null,
                ];
            }
            return array_values($rows);
        }

        $where = ['tenant_id = :tenant_id', 'status <> "inactive"'];
        $params = ['tenant_id' => $campaign['tenant_id']];
        if ($campaign['audience_filter'] === 'all_leads') {
            $where[] = 'status = "lead"';
        } elseif ($campaign['audience_filter'] === 'customers') {
            $where[] = 'status = "customer"';
        } elseif ($campaign['audience_filter'] === 'tag') {
            $where[] = 'JSON_SEARCH(tags_json, "one", :tag_filter) IS NOT NULL';
            $params['tag_filter'] = (string) $campaign['tag_filter'];
        }

        $statement = Database::connection()->prepare(
            'SELECT id, name, phone, status, tags_json, marketing_opt_in, opt_out_at
             FROM contacts
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY updated_at DESC
             LIMIT 5000'
        );
        $statement->execute($params);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private function personalize(string $template, array $contact, string $tenantName): string
    {
        return strtr($template, [
            '{{nome}}' => trim((string) ($contact['name'] ?? '')) ?: 'tudo bem',
            '{{telefone}}' => (string) ($contact['phone'] ?? ''),
            '{{empresa}}' => $tenantName,
            '{{data}}' => date('d/m/Y'),
        ]);
    }

    private function serviceForCampaign(array $campaign): EvolutionService
    {
        $verifySsl = filter_var(Env::get('EVOLUTION_SSL_VERIFY', true), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        $caBundle = trim((string) Env::get('EVOLUTION_CA_BUNDLE', ''));
        return new EvolutionService(
            (string) $campaign['base_url'],
            Crypto::decrypt((string) $campaign['api_key_encrypted']),
            (string) $campaign['instance_name'],
            25,
            $verifySsl ?? true,
            $caBundle !== '' ? $caBundle : null
        );
    }

    private function registerConversationMessage(array $campaign, array $recipient, array $body): void
    {
        if (empty($recipient['contact_id'])) {
            return;
        }
        try {
            $pdo = Database::connection();
            $remoteJid = preg_replace('/\D+/', '', (string) $recipient['phone']) . '@s.whatsapp.net';
            $conversationStatement = $pdo->prepare(
                'SELECT id FROM conversations
                 WHERE evolution_instance_id = :instance_id AND contact_id = :contact_id
                 LIMIT 1'
            );
            $conversationStatement->execute([
                'instance_id' => $campaign['evolution_instance_id'],
                'contact_id' => $recipient['contact_id'],
            ]);
            $conversationId = (int) $conversationStatement->fetchColumn();
            if ($conversationId < 1) {
                $insertConversation = $pdo->prepare(
                    'INSERT INTO conversations
                        (tenant_id, evolution_instance_id, contact_id, remote_jid, status, attendance_mode, operational_status, last_message_at, last_message_preview)
                     VALUES
                        (:tenant_id, :instance_id, :contact_id, :remote_jid, "open", "human", "waiting_customer", NOW(), :preview)'
                );
                $insertConversation->execute([
                    'tenant_id' => $campaign['tenant_id'],
                    'instance_id' => $campaign['evolution_instance_id'],
                    'contact_id' => $recipient['contact_id'],
                    'remote_jid' => $remoteJid,
                    'preview' => mb_substr((string) $recipient['personalized_message'], 0, 255),
                ]);
                $conversationId = (int) $pdo->lastInsertId();
            }

            $externalId = $body['key']['id'] ?? $body['messageId'] ?? $body['id'] ?? $body['data']['key']['id'] ?? null;
            $pdo->prepare(
                'INSERT INTO conversation_messages
                    (tenant_id, conversation_id, evolution_message_id, direction, sender_type, sender_user_id, message_type, content, status, raw_payload_json, sent_at)
                 VALUES
                    (:tenant_id, :conversation_id, :external_id, "outgoing", "system", :user_id, "text", :content, "sent", :raw_payload, NOW())'
            )->execute([
                'tenant_id' => $campaign['tenant_id'],
                'conversation_id' => $conversationId,
                'external_id' => is_scalar($externalId) ? (string) $externalId : null,
                'user_id' => Auth::id(),
                'content' => $recipient['personalized_message'],
                'raw_payload' => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
            $pdo->prepare(
                'UPDATE conversations
                 SET last_message_at = NOW(), last_message_preview = :preview, status = "open", attendance_mode = "human", operational_status = "waiting_customer"
                 WHERE id = :id'
            )->execute(['preview' => mb_substr((string) $recipient['personalized_message'], 0, 255), 'id' => $conversationId]);
        } catch (Throwable) {
            // O envio já foi registrado na campanha; não bloqueia o lote.
        }
    }

    private function refreshCounters(int $campaignId): void
    {
        Database::connection()->prepare(
            'UPDATE message_campaigns c
             SET total_recipients = (SELECT COUNT(*) FROM message_campaign_recipients r WHERE r.campaign_id = c.id),
                 queued_count = (SELECT COUNT(*) FROM message_campaign_recipients r WHERE r.campaign_id = c.id AND r.status = "queued"),
                 sent_count = (SELECT COUNT(*) FROM message_campaign_recipients r WHERE r.campaign_id = c.id AND r.status = "sent"),
                 failed_count = (SELECT COUNT(*) FROM message_campaign_recipients r WHERE r.campaign_id = c.id AND r.status = "failed"),
                 skipped_count = (SELECT COUNT(*) FROM message_campaign_recipients r WHERE r.campaign_id = c.id AND r.status IN ("skipped", "opted_out")),
                 last_dispatched_at = NOW(), updated_at = NOW()
             WHERE c.id = :id'
        )->execute(['id' => $campaignId]);
    }

    private function completeIfDone(int $campaignId): void
    {
        $queued = $this->queuedCount($campaignId);
        if ($queued === 0) {
            Database::connection()->prepare(
                'UPDATE message_campaigns
                 SET status = IF(status IN ("cancelled", "paused"), status, "completed"), completed_at = IF(completed_at IS NULL, NOW(), completed_at), updated_at = NOW()
                 WHERE id = :id'
            )->execute(['id' => $campaignId]);
        } else {
            Database::connection()->prepare('UPDATE message_campaigns SET status = "queued", updated_at = NOW() WHERE id = :id AND status = "sending"')
                ->execute(['id' => $campaignId]);
        }
    }

    private function queuedCount(int $campaignId): int
    {
        $statement = Database::connection()->prepare('SELECT COUNT(*) FROM message_campaign_recipients WHERE campaign_id = :id AND status = "queued"');
        $statement->execute(['id' => $campaignId]);
        return (int) $statement->fetchColumn();
    }

    private function metrics(int $tenantId = 0): array
    {
        $where = [];
        $params = [];
        if (Auth::isSuperAdmin()) {
            if ($tenantId > 0) {
                $where[] = 'tenant_id = :tenant_id';
                $params['tenant_id'] = $tenantId;
            }
        } else {
            $where[] = 'tenant_id = :tenant_id';
            $params['tenant_id'] = Auth::tenantId();
        }
        $sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $statement = Database::connection()->prepare(
            'SELECT COUNT(*) AS total,
                    SUM(status IN ("queued", "sending")) AS active,
                    SUM(status = "completed") AS completed,
                    SUM(approval_status = "pending") AS pending_approval,
                    COALESCE(SUM(sent_count), 0) AS sent
             FROM message_campaigns ' . $sqlWhere
        );
        $statement->execute($params);
        return $statement->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    private function log(int $campaignId, int $tenantId, string $event, string $status, string $message, array $context = []): void
    {
        try {
            Database::connection()->prepare(
                'INSERT INTO message_campaign_logs (campaign_id, tenant_id, event, status, message, context_json, created_at)
                 VALUES (:campaign_id, :tenant_id, :event, :status, :message, :context_json, NOW())'
            )->execute([
                'campaign_id' => $campaignId,
                'tenant_id' => $tenantId,
                'event' => $event,
                'status' => $status,
                'message' => $message,
                'context_json' => $context ? json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            ]);
        } catch (Throwable) {
        }
    }

    private function postedTenantId(): int
    {
        return Auth::isSuperAdmin() ? (int) ($_POST['tenant_id'] ?? 0) : (int) Auth::tenantId();
    }

    private function instanceBelongsToTenant(int $instanceId, int $tenantId): bool
    {
        $statement = Database::connection()->prepare('SELECT COUNT(*) FROM evolution_instances WHERE id = :id AND tenant_id = :tenant_id');
        $statement->execute(['id' => $instanceId, 'tenant_id' => $tenantId]);
        return (int) $statement->fetchColumn() > 0;
    }

    private function redirect(string $path): never
    {
        header('Location: ' . Router::url($path));
        exit;
    }
}
