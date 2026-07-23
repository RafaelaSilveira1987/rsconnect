<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use Throwable;

final class ConversationFlowService
{
    public const GROUPS = [
        'unclassified' => 'Não identificado',
        'interested' => 'Novo interessado',
        'customer' => 'Cliente atual',
        'patient' => 'Paciente atual',
        'family' => 'Familiar',
        'couple' => 'Casal',
        'other' => 'Outro grupo',
    ];


    public const STATUS_LABELS = [
        'lead' => 'Lead / novo contato',
        'customer' => 'Cliente atual',
        'inactive' => 'Contato inativo',
    ];

    public const DEMAND_STATUSES = [
        'pending' => 'Ainda não coletada',
        'collected' => 'Demanda coletada',
        'refused' => 'Preferiu não informar',
        'not_required' => 'Não necessária neste fluxo',
    ];

    public const STAGES = [
        'identifying_contact' => 'Identificando o contato',
        'understanding_demand' => 'Entendendo a demanda',
        'collecting_demand' => 'Aguardando a demanda',
        'ready_for_scheduling' => 'Pronto para pré-agendamento',
        'scheduling' => 'Coletando data e horário',
        'awaiting_approval' => 'Aguardando aprovação',
        'human_handoff' => 'Encaminhado para atendimento humano',
        'completed' => 'Atendimento concluído',
    ];

    public function ingestIncoming(PDO $pdo, array $instance, int $contactId, int $conversationId, string $content): array
    {
        $tenantId = (int) ($instance['tenant_id'] ?? 0);
        if ($tenantId < 1 || $contactId < 1 || $conversationId < 1) {
            return [];
        }

        $contact = $this->contact($pdo, $tenantId, $contactId);
        if (!$contact) {
            return [];
        }

        $state = $this->state($pdo, $tenantId, $conversationId, $contactId);
        $group = $this->resolveGroup($contact);
        $existingPatient = $group === 'patient';
        $text = $this->normalize($content);
        $intent = $this->intent($text);
        $demandStatus = (string) ($state['demand_status'] ?? 'pending');
        $demandSummary = trim((string) ($state['demand_summary'] ?? ''));

        if ($this->isDemandRefusal($text)) {
            $demandStatus = 'refused';
            $demandSummary = 'O contato preferiu não informar a demanda neste momento.';
        } else {
            $candidate = $this->demandCandidate($content, $text);
            if ($candidate !== '') {
                $demandStatus = 'collected';
                $demandSummary = $candidate;
            }
        }

        $stage = $this->stageFor($intent, $demandStatus, $existingPatient);
        $contactStatus = (string) ($contact['status'] ?? '');
        $contactTags = $this->tags($contact['tags_json'] ?? null);
        $metadata = [
            'contact_group' => $group,
            'contact_status' => $contactStatus,
            'contact_status_label' => self::STATUS_LABELS[$contactStatus] ?? ($contactStatus !== '' ? $contactStatus : 'Não informado'),
            'tags' => $contactTags,
            'is_existing_customer' => $this->isExistingCustomer($contactStatus, $group, $contactTags),
            'last_message_preview' => mb_substr(trim($content), 0, 300),
        ];

        $statement = $pdo->prepare(
            'INSERT INTO conversation_flow_states
                (tenant_id, conversation_id, contact_id, stage, demand_status, demand_summary,
                 is_existing_patient, last_intent, source, metadata_json)
             VALUES
                (:tenant_id, :conversation_id, :contact_id, :stage, :demand_status, :demand_summary,
                 :is_existing_patient, :last_intent, "platform", :metadata_json)
             ON DUPLICATE KEY UPDATE
                contact_id = VALUES(contact_id),
                stage = VALUES(stage),
                demand_status = VALUES(demand_status),
                demand_summary = VALUES(demand_summary),
                is_existing_patient = VALUES(is_existing_patient),
                last_intent = VALUES(last_intent),
                source = "platform",
                metadata_json = VALUES(metadata_json),
                updated_at = CURRENT_TIMESTAMP'
        );
        $statement->execute([
            'tenant_id' => $tenantId,
            'conversation_id' => $conversationId,
            'contact_id' => $contactId,
            'stage' => $stage,
            'demand_status' => $demandStatus,
            'demand_summary' => $demandSummary !== '' ? mb_substr($demandSummary, 0, 3000) : null,
            'is_existing_patient' => $existingPatient ? 1 : 0,
            'last_intent' => $intent,
            'metadata_json' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        return $this->context($pdo, $tenantId, $conversationId, $contactId);
    }

    public function schedulingDecision(PDO $pdo, array $instance, int $contactId, int $conversationId, string $content, array $flow = []): array
    {
        $tenantId = (int) ($instance['tenant_id'] ?? 0);
        if ($flow === []) {
            $flow = $this->ingestIncoming($pdo, $instance, $contactId, $conversationId, $content);
        }

        $group = (string) ($flow['contact_group'] ?? 'unclassified');
        $rule = $this->ruleForInstance($pdo, $tenantId, (int) ($instance['id'] ?? 0), $group);
        $demandStatus = (string) ($flow['demand_status'] ?? 'pending');
        $isReschedule = $this->isReschedule($this->normalize($content));

        if (empty($rule['allow_pre_schedule'])) {
            return [
                'allowed' => false,
                'code' => 'group_pre_schedule_blocked',
                'message' => 'O grupo deste contato não permite pré-agendamento automático.',
                'flow' => $flow,
                'rule' => $rule,
            ];
        }

        if (in_array($demandStatus, ['collected', 'refused', 'not_required'], true)) {
            return ['allowed' => true, 'code' => 'ready', 'message' => null, 'flow' => $flow, 'rule' => $rule];
        }

        if ($group === 'patient' && $isReschedule && !empty($rule['allow_reschedule_without_demand'])) {
            $this->markDemandNotRequired($pdo, $tenantId, $conversationId, 'Paciente atual solicitou remarcação.');
            $flow = $this->context($pdo, $tenantId, $conversationId, $contactId);
            return ['allowed' => true, 'code' => 'patient_reschedule', 'message' => null, 'flow' => $flow, 'rule' => $rule];
        }

        if (empty($rule['require_demand_before_pre_schedule'])) {
            return ['allowed' => true, 'code' => 'demand_not_required_by_rule', 'message' => null, 'flow' => $flow, 'rule' => $rule];
        }

        $this->setStage($pdo, $tenantId, $conversationId, 'collecting_demand', 'agenda_intent_without_demand');
        $flow = $this->context($pdo, $tenantId, $conversationId, $contactId);

        return [
            'allowed' => false,
            'code' => 'demand_required',
            'message' => 'A plataforma aguardará a demanda ou a recusa em informá-la antes de criar o pré-agendamento.',
            'flow' => $flow,
            'rule' => $rule,
        ];
    }

    public function context(PDO $pdo, int $tenantId, int $conversationId, int $contactId = 0): array
    {
        $statement = $pdo->prepare(
            'SELECT fs.*, ct.status AS contact_status,
                    COALESCE(NULLIF(ct.contact_group, ""), "unclassified") AS contact_group,
                    ct.tags_json
             FROM conversations c
             INNER JOIN contacts ct ON ct.id = c.contact_id AND ct.tenant_id = c.tenant_id
             LEFT JOIN conversation_flow_states fs ON fs.conversation_id = c.id AND fs.tenant_id = c.tenant_id
             WHERE c.id = :conversation_id AND c.tenant_id = :tenant_id
             LIMIT 1'
        );
        $statement->execute(['conversation_id' => $conversationId, 'tenant_id' => $tenantId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
        if ($row === []) {
            return [];
        }

        $group = $this->resolveGroup($row);
        return [
            'stage' => (string) ($row['stage'] ?? 'identifying_contact'),
            'demand_status' => (string) ($row['demand_status'] ?? 'pending'),
            'demand_summary' => trim((string) ($row['demand_summary'] ?? '')),
            'is_existing_patient' => $group === 'patient' || (int) ($row['is_existing_patient'] ?? 0) === 1,
            'last_intent' => (string) ($row['last_intent'] ?? ''),
            'contact_group' => $group,
            'contact_status' => (string) ($row['contact_status'] ?? ''),
            'contact_status_label' => self::STATUS_LABELS[(string) ($row['contact_status'] ?? '')] ?? ((string) ($row['contact_status'] ?? '') !== '' ? (string) $row['contact_status'] : 'Não informado'),
            'tags' => $this->tags($row['tags_json'] ?? null),
            'is_existing_customer' => $this->isExistingCustomer((string) ($row['contact_status'] ?? ''), $group, $this->tags($row['tags_json'] ?? null)),
            'stage_label' => self::STAGES[(string) ($row['stage'] ?? '')] ?? 'Em atendimento',
            'demand_status_label' => self::DEMAND_STATUSES[(string) ($row['demand_status'] ?? '')] ?? 'Ainda não coletada',
            'contact_group_label' => self::GROUPS[$group] ?? 'Outro grupo',
        ];
    }

    public function updateManual(PDO $pdo, int $tenantId, int $conversationId, int $contactId, array $data): void
    {
        $group = (string) ($data['contact_group'] ?? 'unclassified');
        if (!array_key_exists($group, self::GROUPS)) {
            $group = 'unclassified';
        }
        $stage = (string) ($data['flow_stage'] ?? 'identifying_contact');
        if (!array_key_exists($stage, self::STAGES)) {
            $stage = 'identifying_contact';
        }
        $demandStatus = (string) ($data['demand_status'] ?? 'pending');
        if (!array_key_exists($demandStatus, self::DEMAND_STATUSES)) {
            $demandStatus = 'pending';
        }
        $summary = trim((string) ($data['demand_summary'] ?? ''));

        $pdo->prepare(
            'UPDATE contacts SET contact_group = :contact_group WHERE id = :contact_id AND tenant_id = :tenant_id'
        )->execute(['contact_group' => $group, 'contact_id' => $contactId, 'tenant_id' => $tenantId]);

        $pdo->prepare(
            'INSERT INTO conversation_flow_states
                (tenant_id, conversation_id, contact_id, stage, demand_status, demand_summary,
                 is_existing_patient, source)
             VALUES
                (:tenant_id, :conversation_id, :contact_id, :stage, :demand_status, :demand_summary,
                 :is_existing_patient, "manual")
             ON DUPLICATE KEY UPDATE
                contact_id = VALUES(contact_id),
                stage = VALUES(stage),
                demand_status = VALUES(demand_status),
                demand_summary = VALUES(demand_summary),
                is_existing_patient = VALUES(is_existing_patient),
                source = "manual",
                updated_at = CURRENT_TIMESTAMP'
        )->execute([
            'tenant_id' => $tenantId,
            'conversation_id' => $conversationId,
            'contact_id' => $contactId,
            'stage' => $stage,
            'demand_status' => $demandStatus,
            'demand_summary' => $summary !== '' ? mb_substr($summary, 0, 3000) : null,
            'is_existing_patient' => $group === 'patient' ? 1 : 0,
        ]);
    }

    public function ruleForAgent(PDO $pdo, int $tenantId, int $agentId, string $group): array
    {
        $defaults = $this->defaultRule($group);
        if ($agentId < 1) {
            return $defaults;
        }
        try {
            $statement = $pdo->prepare(
                'SELECT allow_pre_schedule, require_demand_before_pre_schedule,
                        allow_reschedule_without_demand, instructions
                 FROM ai_agent_group_rules
                 WHERE tenant_id = :tenant_id AND agent_id = :agent_id AND contact_group = :contact_group
                 LIMIT 1'
            );
            $statement->execute([
                'tenant_id' => $tenantId,
                'agent_id' => $agentId,
                'contact_group' => $group,
            ]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return $defaults;
            }
            return [
                'allow_pre_schedule' => (int) $row['allow_pre_schedule'] === 1,
                'require_demand_before_pre_schedule' => (int) $row['require_demand_before_pre_schedule'] === 1,
                'allow_reschedule_without_demand' => (int) $row['allow_reschedule_without_demand'] === 1,
                'instructions' => trim((string) ($row['instructions'] ?? '')),
            ];
        } catch (Throwable) {
            return $defaults;
        }
    }

    public function saveGroupRules(PDO $pdo, int $tenantId, int $agentId, array $postedRules): void
    {
        $statement = $pdo->prepare(
            'INSERT INTO ai_agent_group_rules
                (tenant_id, agent_id, contact_group, allow_pre_schedule,
                 require_demand_before_pre_schedule, allow_reschedule_without_demand, instructions)
             VALUES
                (:tenant_id, :agent_id, :contact_group, :allow_pre_schedule,
                 :require_demand, :allow_reschedule, :instructions)
             ON DUPLICATE KEY UPDATE
                allow_pre_schedule = VALUES(allow_pre_schedule),
                require_demand_before_pre_schedule = VALUES(require_demand_before_pre_schedule),
                allow_reschedule_without_demand = VALUES(allow_reschedule_without_demand),
                instructions = VALUES(instructions),
                updated_at = CURRENT_TIMESTAMP'
        );

        foreach (self::GROUPS as $group => $_label) {
            $row = is_array($postedRules[$group] ?? null) ? $postedRules[$group] : [];
            $defaults = $this->defaultRule($group);
            $statement->execute([
                'tenant_id' => $tenantId,
                'agent_id' => $agentId,
                'contact_group' => $group,
                'allow_pre_schedule' => array_key_exists('allow_pre_schedule', $row) ? 1 : 0,
                'require_demand' => array_key_exists('require_demand_before_pre_schedule', $row) ? 1 : 0,
                'allow_reschedule' => array_key_exists('allow_reschedule_without_demand', $row) ? 1 : 0,
                'instructions' => trim((string) ($row['instructions'] ?? '')) ?: ($defaults['instructions'] ?: null),
            ]);
        }
    }

    public function rulesForAgents(PDO $pdo, int $tenantId, array $agentIds): array
    {
        $agentIds = array_values(array_filter(array_map('intval', $agentIds), static fn (int $id): bool => $id > 0));
        if ($agentIds === []) {
            return [];
        }
        try {
            $placeholders = implode(',', array_fill(0, count($agentIds), '?'));
            $statement = $pdo->prepare(
                'SELECT * FROM ai_agent_group_rules
                 WHERE tenant_id = ? AND agent_id IN (' . $placeholders . ')'
            );
            $statement->execute(array_merge([$tenantId], $agentIds));
            $result = [];
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $result[(int) $row['agent_id']][(string) $row['contact_group']] = $row;
            }
            return $result;
        } catch (Throwable) {
            return [];
        }
    }

    private function ruleForInstance(PDO $pdo, int $tenantId, int $instanceId, string $group): array
    {
        $agentId = 0;
        try {
            $statement = $pdo->prepare(
                'SELECT id FROM ai_agents
                 WHERE tenant_id = :tenant_id AND status = "active"
                   AND (instance_id = :instance_id OR instance_id IS NULL OR is_default = 1)
                 ORDER BY (instance_id = :instance_order) DESC, is_default DESC, id DESC
                 LIMIT 1'
            );
            $statement->execute([
                'tenant_id' => $tenantId,
                'instance_id' => $instanceId,
                'instance_order' => $instanceId,
            ]);
            $agentId = (int) ($statement->fetchColumn() ?: 0);
        } catch (Throwable) {
            $agentId = 0;
        }
        return $this->ruleForAgent($pdo, $tenantId, $agentId, $group);
    }

    private function defaultRule(string $group): array
    {
        return match ($group) {
            'customer' => [
                'allow_pre_schedule' => true,
                'require_demand_before_pre_schedule' => false,
                'allow_reschedule_without_demand' => true,
                'instructions' => 'Cliente atual: use cadastro e histórico existentes e não reinicie a triagem como novo interessado. Pergunte somente o que for necessário para o pedido atual.',
            ],
            'patient' => [
                'allow_pre_schedule' => true,
                'require_demand_before_pre_schedule' => true,
                'allow_reschedule_without_demand' => true,
                'instructions' => 'Paciente atual: não peça novamente a queixa quando ele estiver apenas remarcando um atendimento.',
            ],
            'family' => [
                'allow_pre_schedule' => false,
                'require_demand_before_pre_schedule' => true,
                'allow_reschedule_without_demand' => false,
                'instructions' => 'Familiar: siga as regras da empresa antes de oferecer atendimento ou agenda.',
            ],
            'couple' => [
                'allow_pre_schedule' => false,
                'require_demand_before_pre_schedule' => true,
                'allow_reschedule_without_demand' => false,
                'instructions' => 'Casal: não abra pré-agendamento automático quando a empresa atende somente individualmente.',
            ],
            default => [
                'allow_pre_schedule' => true,
                'require_demand_before_pre_schedule' => true,
                'allow_reschedule_without_demand' => false,
                'instructions' => '',
            ],
        };
    }

    public function refreshContactContext(PDO $pdo, int $tenantId, int $contactId): void
    {
        try {
            $contact = $this->contact($pdo, $tenantId, $contactId);
            if (!$contact) {
                return;
            }
            $group = $this->resolveGroup($contact);
            $status = (string) ($contact['status'] ?? '');
            $tags = $this->tags($contact['tags_json'] ?? null);

            $statement = $pdo->prepare(
                'SELECT id, metadata_json
                 FROM conversation_flow_states
                 WHERE tenant_id = :tenant_id AND contact_id = :contact_id'
            );
            $statement->execute(['tenant_id' => $tenantId, 'contact_id' => $contactId]);
            $update = $pdo->prepare(
                'UPDATE conversation_flow_states
                 SET is_existing_patient = :is_existing_patient,
                     metadata_json = :metadata_json,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id'
            );

            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $metadata = json_decode((string) ($row['metadata_json'] ?? ''), true);
                if (!is_array($metadata)) {
                    $metadata = [];
                }
                $metadata['contact_group'] = $group;
                $metadata['contact_status'] = $status;
                $metadata['contact_status_label'] = self::STATUS_LABELS[$status] ?? ($status !== '' ? $status : 'Não informado');
                $metadata['tags'] = $tags;
                $metadata['is_existing_customer'] = $this->isExistingCustomer($status, $group, $tags);

                $update->execute([
                    'is_existing_patient' => $group === 'patient' ? 1 : 0,
                    'metadata_json' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'id' => (int) $row['id'],
                ]);
            }
        } catch (Throwable) {
            // A atualização do contato não pode falhar por ausência de tabelas antigas.
        }
    }

    private function contact(PDO $pdo, int $tenantId, int $contactId): ?array
    {
        $statement = $pdo->prepare(
            'SELECT id, tenant_id, status, contact_group, tags_json, notes
             FROM contacts WHERE id = :id AND tenant_id = :tenant_id LIMIT 1'
        );
        $statement->execute(['id' => $contactId, 'tenant_id' => $tenantId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function state(PDO $pdo, int $tenantId, int $conversationId, int $contactId): array
    {
        try {
            $statement = $pdo->prepare(
                'SELECT * FROM conversation_flow_states
                 WHERE tenant_id = :tenant_id AND conversation_id = :conversation_id LIMIT 1'
            );
            $statement->execute(['tenant_id' => $tenantId, 'conversation_id' => $conversationId]);
            return $statement->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return [];
        }
    }

    private function resolveGroup(array $contact): string
    {
        $explicit = trim((string) ($contact['contact_group'] ?? ''));
        $status = trim((string) ($contact['status'] ?? $contact['contact_status'] ?? ''));
        $tags = array_map([$this, 'normalize'], $this->tags($contact['tags_json'] ?? null));
        $taggedCustomer = false;

        foreach ($tags as $tag) {
            if (in_array($tag, ['cliente', 'customer', 'client'], true)) {
                $taggedCustomer = true;
                break;
            }
        }

        // Classificação de cliente é fonte de verdade quando o grupo ainda está como novo interessado.
        if (($status === 'customer' || $taggedCustomer)
            && in_array($explicit, ['', 'unclassified', 'interested', 'customer'], true)) {
            return 'customer';
        }

        if ($explicit !== '' && $explicit !== 'unclassified' && array_key_exists($explicit, self::GROUPS)) {
            return $explicit;
        }
        foreach ($tags as $tag) {
            if (str_contains($tag, 'paciente')) return 'patient';
            if (str_contains($tag, 'interessad') || $tag === 'lead') return 'interested';
            if (str_contains($tag, 'familiar') || str_contains($tag, 'familia')) return 'family';
            if (str_contains($tag, 'casal')) return 'couple';
        }
        return array_key_exists($explicit, self::GROUPS) ? $explicit : 'unclassified';
    }

    /** @param array<int, string> $tags */
    private function isExistingCustomer(string $status, string $group, array $tags): bool
    {
        if ($status === 'customer' || in_array($group, ['customer', 'patient'], true)) {
            return true;
        }
        foreach ($tags as $tag) {
            $normalized = $this->normalize($tag);
            if (in_array($normalized, ['cliente', 'customer', 'client', 'paciente', 'paciente atual'], true)) {
                return true;
            }
        }
        return false;
    }

    private function tags(mixed $raw): array
    {
        if (is_array($raw)) return array_values(array_filter(array_map('strval', $raw)));
        $decoded = json_decode((string) $raw, true);
        return is_array($decoded) ? array_values(array_filter(array_map('strval', $decoded))) : [];
    }

    private function stageFor(string $intent, string $demandStatus, bool $existingPatient): string
    {
        if (in_array($demandStatus, ['collected', 'refused', 'not_required'], true)) {
            return in_array($intent, ['schedule', 'reschedule'], true) ? 'scheduling' : 'ready_for_scheduling';
        }
        if (in_array($intent, ['schedule', 'reschedule'], true)) return 'collecting_demand';
        return $existingPatient ? 'understanding_demand' : 'understanding_demand';
    }

    private function intent(string $text): string
    {
        if ($this->isReschedule($text)) return 'reschedule';
        if ((bool) preg_match('/\b(agendar|agenda|horario|hora|marcar|consulta|sessao|disponibilidade|encaixe)\b/u', $text)) return 'schedule';
        if ((bool) preg_match('/\b(humano|atendente|pessoa|equipe|suporte)\b/u', $text)) return 'human_handoff';
        return 'conversation';
    }

    private function isReschedule(string $text): bool
    {
        return (bool) preg_match('/\b(remarcar|remarcacao|reagendar|trocar o horario|mudar o horario|alterar o horario|outro horario|desmarcar e marcar)\b/u', $text);
    }

    private function isDemandRefusal(string $text): bool
    {
        return (bool) preg_match('/\b(prefiro nao informar|nao quero falar|nao gostaria de explicar|nao me sinto confortavel|quero falar diretamente|prefiro conversar na consulta)\b/u', $text);
    }

    private function demandCandidate(string $original, string $text): string
    {
        if (mb_strlen(trim($original)) < 12) return '';
        if ($this->isOnlySchedulingMessage($text)) return '';

        $signals = '/\b(ansiedade|depressao|panico|insonia|luto|relacionamento|autoestima|estresse|medo|trauma|crise|angustia|tristeza|terapia|acompanhamento|ajuda|preciso|porque|motivo|queixa|dificuldade|problema|sofrendo|sinto|estou com|tenho tido)\b/u';
        if (preg_match($signals, $text)) {
            return mb_substr(trim($original), 0, 1200);
        }
        return '';
    }

    private function isOnlySchedulingMessage(string $text): bool
    {
        $without = preg_replace('/\b(agenda|agendar|marcar|horario|hora|disponibilidade|consulta|sessao|online|presencial|hoje|amanha|segunda|terca|quarta|quinta|sexta|sabado|domingo|manha|tarde|noite|as|às|de|para|quero|gostaria|pode|tem|um|uma|o|a|e|por|favor)\b/u', ' ', $text) ?? $text;
        $without = preg_replace('/\b\d{1,2}([:\/\-]\d{1,4})*\b/u', ' ', $without) ?? $without;
        $without = preg_replace('/\s+/u', ' ', trim($without)) ?? trim($without);
        return mb_strlen($without) < 10;
    }

    private function markDemandNotRequired(PDO $pdo, int $tenantId, int $conversationId, string $summary): void
    {
        $pdo->prepare(
            'UPDATE conversation_flow_states
             SET demand_status = "not_required", demand_summary = :summary,
                 stage = "scheduling", last_intent = "reschedule", updated_at = CURRENT_TIMESTAMP
             WHERE tenant_id = :tenant_id AND conversation_id = :conversation_id'
        )->execute(['summary' => $summary, 'tenant_id' => $tenantId, 'conversation_id' => $conversationId]);
    }

    private function setStage(PDO $pdo, int $tenantId, int $conversationId, string $stage, string $intent): void
    {
        $pdo->prepare(
            'UPDATE conversation_flow_states
             SET stage = :stage, last_intent = :intent, updated_at = CURRENT_TIMESTAMP
             WHERE tenant_id = :tenant_id AND conversation_id = :conversation_id'
        )->execute(['stage' => $stage, 'intent' => $intent, 'tenant_id' => $tenantId, 'conversation_id' => $conversationId]);
    }

    private function normalize(string $text): string
    {
        $text = mb_strtolower(trim($text));
        return strtr($text, [
            'á'=>'a','à'=>'a','ã'=>'a','â'=>'a','ä'=>'a','é'=>'e','è'=>'e','ê'=>'e','ë'=>'e',
            'í'=>'i','ì'=>'i','î'=>'i','ï'=>'i','ó'=>'o','ò'=>'o','õ'=>'o','ô'=>'o','ö'=>'o',
            'ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u','ç'=>'c',
        ]);
    }
}
