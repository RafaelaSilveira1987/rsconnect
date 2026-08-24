<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use Throwable;

final class CrmAutoService
{
    public function createFromConversation(PDO $pdo, array $instance, int $contactId, int $conversationId, string $incomingContent): ?int
    {
        try {
            if (!$this->hasTable($pdo, 'crm_leads') || !$this->hasTable($pdo, 'crm_pipelines') || !$this->hasTable($pdo, 'crm_stages')) {
                return null;
            }

            $tenantId = (int) $instance['tenant_id'];
            $pipelineId = $this->ensurePipeline($pdo, $tenantId);
            $stageId = $this->firstStage($pdo, $tenantId, $pipelineId);
            if ($pipelineId < 1 || $stageId < 1) {
                return null;
            }

            $existingId = $this->findExistingOpenLead($pdo, $tenantId, $contactId, $conversationId);
            if ($existingId > 0) {
                $this->touchLead($pdo, $existingId, $incomingContent);
                $this->updateConversationLead($pdo, $conversationId, $existingId, $incomingContent);
                $this->mergeTags($pdo, $contactId, $this->detectTags($incomingContent));
                return $existingId;
            }

            $contact = $this->contact($pdo, $contactId);
            $title = 'WhatsApp - ' . trim((string) ($contact['name'] ?? ''));
            if ($title === 'WhatsApp -') {
                $title = 'WhatsApp - ' . (string) ($contact['phone'] ?? 'Lead');
            }

            $priority = $this->priorityFromContent($incomingContent);
            $hasSource = $this->hasColumn($pdo, 'crm_leads', 'source');
            $hasSourceConversation = $this->hasColumn($pdo, 'crm_leads', 'source_conversation_id');

            if ($hasSource && $hasSourceConversation) {
                $statement = $pdo->prepare(
                    'INSERT INTO crm_leads
                        (tenant_id, contact_id, pipeline_id, stage_id, owner_user_id, title, value,
                         priority, status, source, source_conversation_id)
                     VALUES
                        (:tenant_id, :contact_id, :pipeline_id, :stage_id, NULL, :title, 0,
                         :priority, "open", "whatsapp", :conversation_id)'
                );
                $statement->execute([
                    'tenant_id' => $tenantId,
                    'contact_id' => $contactId,
                    'pipeline_id' => $pipelineId,
                    'stage_id' => $stageId,
                    'title' => $title,
                    'priority' => $priority,
                    'conversation_id' => $conversationId,
                ]);
            } else {
                $statement = $pdo->prepare(
                    'INSERT INTO crm_leads
                        (tenant_id, contact_id, pipeline_id, stage_id, owner_user_id, title, value,
                         priority, status)
                     VALUES
                        (:tenant_id, :contact_id, :pipeline_id, :stage_id, NULL, :title, 0,
                         :priority, "open")'
                );
                $statement->execute([
                    'tenant_id' => $tenantId,
                    'contact_id' => $contactId,
                    'pipeline_id' => $pipelineId,
                    'stage_id' => $stageId,
                    'title' => $title,
                    'priority' => $priority,
                ]);
            }

            $leadId = (int) $pdo->lastInsertId();
            $this->addNote($pdo, $tenantId, $contactId, $leadId, 'Lead criado automaticamente a partir do WhatsApp. Primeira mensagem: ' . $this->preview($incomingContent, 500));
            $this->updateConversationLead($pdo, $conversationId, $leadId, $incomingContent);
            $this->mergeTags($pdo, $contactId, $this->detectTags($incomingContent));

            return $leadId;
        } catch (Throwable) {
            return null;
        }
    }

    private function findExistingOpenLead(PDO $pdo, int $tenantId, int $contactId, int $conversationId): int
    {
        $hasSourceConversation = $this->hasColumn($pdo, 'crm_leads', 'source_conversation_id');
        if ($hasSourceConversation) {
            $byConversation = $pdo->prepare(
                'SELECT id FROM crm_leads
                 WHERE tenant_id = :tenant_id AND source_conversation_id = :conversation_id
                   AND status = "open"
                 ORDER BY id DESC LIMIT 1'
            );
            $byConversation->execute(['tenant_id' => $tenantId, 'conversation_id' => $conversationId]);
            $id = (int) $byConversation->fetchColumn();
            if ($id > 0) {
                return $id;
            }
        }

        $byContact = $pdo->prepare(
            'SELECT id FROM crm_leads
             WHERE tenant_id = :tenant_id AND contact_id = :contact_id AND status = "open"
             ORDER BY id DESC LIMIT 1'
        );
        $byContact->execute(['tenant_id' => $tenantId, 'contact_id' => $contactId]);
        return (int) $byContact->fetchColumn();
    }

    private function touchLead(PDO $pdo, int $leadId, string $content): void
    {
        $pdo->prepare('UPDATE crm_leads SET updated_at = CURRENT_TIMESTAMP WHERE id = :id')
            ->execute(['id' => $leadId]);
    }

    private function ensurePipeline(PDO $pdo, int $tenantId): int
    {
        $statement = $pdo->prepare('SELECT id FROM crm_pipelines WHERE tenant_id = :tenant_id ORDER BY is_default DESC, id LIMIT 1');
        $statement->execute(['tenant_id' => $tenantId]);
        $pipelineId = (int) $statement->fetchColumn();
        if ($pipelineId > 0) {
            return $pipelineId;
        }

        $pdo->prepare('INSERT INTO crm_pipelines (tenant_id, name, is_default) VALUES (:tenant_id, "Funil comercial", 1)')
            ->execute(['tenant_id' => $tenantId]);
        $pipelineId = (int) $pdo->lastInsertId();

        $stages = [
            ['Novo', 'open', 'blue', 1, 10],
            ['Qualificação', 'open', 'cyan', 2, 25],
            ['Proposta', 'open', 'violet', 3, 50],
            ['Negociação', 'open', 'amber', 4, 75],
            ['Ganho', 'won', 'green', 5, 100],
            ['Perdido', 'lost', 'slate', 6, 0],
        ];
        $insert = $pdo->prepare(
            'INSERT INTO crm_stages (tenant_id, pipeline_id, name, stage_type, color_key, position, probability)
             VALUES (:tenant_id, :pipeline_id, :name, :stage_type, :color_key, :position, :probability)'
        );
        foreach ($stages as [$name, $stageType, $color, $position, $probability]) {
            $insert->execute([
                'tenant_id' => $tenantId,
                'pipeline_id' => $pipelineId,
                'name' => $name,
                'stage_type' => $stageType,
                'color_key' => $color,
                'position' => $position,
                'probability' => $probability,
            ]);
        }

        return $pipelineId;
    }

    private function firstStage(PDO $pdo, int $tenantId, int $pipelineId): int
    {
        $statement = $pdo->prepare(
            'SELECT id FROM crm_stages
             WHERE tenant_id = :tenant_id AND pipeline_id = :pipeline_id AND stage_type = "open"
             ORDER BY position LIMIT 1'
        );
        $statement->execute(['tenant_id' => $tenantId, 'pipeline_id' => $pipelineId]);
        return (int) $statement->fetchColumn();
    }

    private function contact(PDO $pdo, int $contactId): array
    {
        $statement = $pdo->prepare('SELECT * FROM contacts WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $contactId]);
        return $statement->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    private function addNote(PDO $pdo, int $tenantId, int $contactId, int $leadId, string $note): void
    {
        if (!$this->hasTable($pdo, 'crm_notes')) {
            return;
        }

        $pdo->prepare(
            'INSERT INTO crm_notes (tenant_id, contact_id, lead_id, user_id, note)
             VALUES (:tenant_id, :contact_id, :lead_id, NULL, :note)'
        )->execute([
            'tenant_id' => $tenantId,
            'contact_id' => $contactId,
            'lead_id' => $leadId,
            'note' => $note,
        ]);
    }

    private function updateConversationLead(PDO $pdo, int $conversationId, int $leadId, string $content): void
    {
        $sets = [];
        $params = ['id' => $conversationId, 'lead_id' => $leadId];
        if ($this->hasColumn($pdo, 'conversations', 'crm_lead_id')) {
            $sets[] = 'crm_lead_id = :lead_id';
        }
        if ($this->hasColumn($pdo, 'conversations', 'ai_interest_level')) {
            $sets[] = 'ai_interest_level = :interest';
            $params['interest'] = $this->interestFromContent($content);
        }
        if ($this->hasColumn($pdo, 'conversations', 'ai_next_action')) {
            $sets[] = 'ai_next_action = :next_action';
            $params['next_action'] = $this->nextActionFromContent($content);
        }
        if (!$sets) {
            return;
        }

        $pdo->prepare('UPDATE conversations SET ' . implode(', ', $sets) . ' WHERE id = :id')->execute($params);
    }

    private function mergeTags(PDO $pdo, int $contactId, array $newTags): void
    {
        if (!$newTags) {
            return;
        }

        $contact = $this->contact($pdo, $contactId);
        $current = json_decode((string) ($contact['tags_json'] ?? ''), true);
        $current = is_array($current) ? $current : [];
        $merged = array_values(array_unique(array_filter(array_merge($current, $newTags))));

        $pdo->prepare('UPDATE contacts SET tags_json = :tags WHERE id = :id')
            ->execute([
                'tags' => json_encode($merged, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'id' => $contactId,
            ]);
    }

    private function detectTags(string $content): array
    {
        $text = mb_strtolower($content);
        $tags = ['whatsapp'];
        $map = [
            'orçamento' => ['orçamento', 'orcamento', 'preço', 'preco', 'valor', 'quanto custa'],
            'suporte' => ['suporte', 'problema', 'erro', 'bug', 'ajuda'],
            'agendamento' => ['agenda', 'agendar', 'horário', 'horario', 'marcar'],
            'urgente' => ['urgente', 'agora', 'imediato'],
            'interessado' => ['quero', 'tenho interesse', 'comprar', 'contratar'],
            'humano' => ['atendente', 'humano', 'pessoa', 'consultor'],
        ];

        foreach ($map as $tag => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($text, $keyword)) {
                    $tags[] = $tag;
                    break;
                }
            }
        }

        return array_values(array_unique($tags));
    }

    private function priorityFromContent(string $content): string
    {
        $text = mb_strtolower($content);
        if (str_contains($text, 'urgente') || str_contains($text, 'agora') || str_contains($text, 'imediato')) {
            return 'high';
        }
        return str_contains($text, 'orçamento') || str_contains($text, 'valor') || str_contains($text, 'comprar') ? 'high' : 'medium';
    }

    private function interestFromContent(string $content): string
    {
        $text = mb_strtolower($content);
        if (str_contains($text, 'comprar') || str_contains($text, 'contratar') || str_contains($text, 'orçamento') || str_contains($text, 'valor')) {
            return 'quente';
        }
        if (str_contains($text, 'talvez') || str_contains($text, 'só olhando') || str_contains($text, 'so olhando')) {
            return 'frio';
        }
        return 'morno';
    }

    private function nextActionFromContent(string $content): string
    {
        $text = mb_strtolower($content);
        if (str_contains($text, 'orçamento') || str_contains($text, 'valor') || str_contains($text, 'preço') || str_contains($text, 'preco')) {
            return 'Levantar necessidade e preparar proposta/orçamento.';
        }
        if (str_contains($text, 'agendar') || str_contains($text, 'horário') || str_contains($text, 'horario')) {
            return 'Confirmar disponibilidade e sugerir horários.';
        }
        if (str_contains($text, 'atendente') || str_contains($text, 'humano')) {
            return 'Transferir para atendimento humano.';
        }
        return 'Qualificar necessidade do lead e coletar nome/objetivo.';
    }

    private function preview(string $text, int $limit): string
    {
        $text = trim($text);
        return mb_strlen($text) > $limit ? mb_substr($text, 0, $limit - 3) . '...' : $text;
    }

    private function hasTable(PDO $pdo, string $table): bool
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table'
        );
        $statement->execute(['table' => $table]);
        return (int) $statement->fetchColumn() > 0;
    }

    private function hasColumn(PDO $pdo, string $table, string $column): bool
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column'
        );
        $statement->execute(['table' => $table, 'column' => $column]);
        return (int) $statement->fetchColumn() > 0;
    }
}
