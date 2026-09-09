<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use Throwable;

final class AgentTriageService
{
    /**
     * Processa a triagem antes da agenda e da IA. Regras críticas são fail-closed.
     *
     * @return array<string,mixed>
     */
    public function handleIncoming(PDO $pdo, array $instance, int $contactId, int $conversationId, string $content, int $incomingMessageId = 0): array
    {
        return $this->process($pdo, $instance, $contactId, $conversationId, $content, true, false);
    }

    /** @return array<string,mixed> */
    public function schedulingGate(PDO $pdo, array $instance, int $contactId, int $conversationId, string $content): array
    {
        return $this->process($pdo, $instance, $contactId, $conversationId, $content, false, true);
    }

    /** @return array<string,mixed> */
    public function context(int $tenantId, int $conversationId): array
    {
        $pdo = Database::connection();
        if (!$this->tableExists($pdo, 'conversation_triage_sessions')) {
            return [];
        }
        $stmt = $pdo->prepare(
            'SELECT s.*, p.interaction_mode, n.name AS niche_name, b.name AS blueprint_name
             FROM conversation_triage_sessions s
             LEFT JOIN tenant_agent_profiles p ON p.tenant_id = s.tenant_id
             LEFT JOIN business_niches n ON n.id = p.niche_id
             LEFT JOIN agent_blueprints b ON b.id = p.blueprint_id
             WHERE s.tenant_id = :tenant_id AND s.conversation_id = :conversation_id LIMIT 1'
        );
        $stmt->execute(['tenant_id' => $tenantId, 'conversation_id' => $conversationId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        if ($row === []) {
            return [];
        }
        $row['collected'] = $this->decodeJson($row['collected_json'] ?? null);
        $row['missing'] = $this->decodeJson($row['missing_json'] ?? null);
        return $row;
    }

    /**
     * Método puro para testes e diagnósticos, sem banco.
     * @return array<string,mixed>
     */
    public function analyzeText(array $profile, array $collected, string $text, bool $forceScheduling = false): array
    {
        $normalized = $this->normalize($text);
        $collected = $this->extractDeterministic($collected, $text, $normalized, null);
        $schedulingIntent = $forceScheduling || $this->hasSchedulingIntent($normalized);
        $action = $schedulingIntent ? 'calendar.pre_schedule' : 'conversation';
        $decision = (new AgentPolicyEngineService())->evaluate($profile, $collected, $action);
        return [
            'collected' => $collected,
            'scheduling_intent' => $schedulingIntent,
            'decision' => $decision,
        ];
    }

    /** @return array<string,mixed> */
    private function process(PDO $pdo, array $instance, int $contactId, int $conversationId, string $content, bool $sendMessages, bool $forceScheduling): array
    {
        $result = [
            'handled' => false,
            'skip_ai' => false,
            'terminal_handled' => false,
            'allowed' => true,
            'code' => 'triage_not_configured',
            'message' => null,
            'scheduling_intent' => false,
            'collected' => [],
            'missing' => [],
            'profile' => [],
        ];
        $tenantId = (int) ($instance['tenant_id'] ?? 0);
        if ($tenantId < 1 || $contactId < 1 || $conversationId < 1 || !$this->tableExists($pdo, 'tenant_agent_profiles')) {
            return $result;
        }

        try {
            $blueprints = new AgentBlueprintService();
            $profile = $blueprints->profileForTenant($tenantId, true, $pdo);
            $result['profile'] = $profile;
            if (($profile['status'] ?? 'inactive') !== 'active' || empty($profile['capabilities']['triage.enabled'])) {
                $result['code'] = 'triage_disabled';
                return $result;
            }

            $session = $this->session($pdo, $tenantId, $conversationId);
            $collected = is_array($session['collected'] ?? null) ? $session['collected'] : [];
            $contact = $this->contact($pdo, $tenantId, $contactId);
            $contactName = trim((string) ($contact['name'] ?? ''));
            if ($this->isUsableContactName($contactName, (string) ($contact['phone'] ?? ''))) {
                $collected['requester_name'] = $collected['requester_name'] ?? $contactName;
            }

            $contextText = $this->recentIncomingContext($pdo, $conversationId, $content);
            $normalizedContext = $this->normalize($contextText);
            $currentField = trim((string) ($session['current_field_key'] ?? '')) ?: null;
            $collected = $this->extractDeterministic($collected, $contextText, $normalizedContext, $currentField, $content);

            if (!empty($collected['is_for_self']) && empty($collected['patient_name']) && !empty($collected['requester_name'])) {
                $collected['patient_name'] = $collected['requester_name'];
            }

            $schedulingIntent = $forceScheduling || $this->hasSchedulingIntent($normalizedContext)
                || in_array((string) ($session['last_intent'] ?? ''), ['schedule', 'reschedule'], true);
            $result['scheduling_intent'] = $schedulingIntent;

            $action = $schedulingIntent ? 'calendar.pre_schedule' : 'conversation';
            $decision = (new AgentPolicyEngineService())->evaluate($profile, $collected, $action);
            $fields = is_array($profile['triage_fields'] ?? null) ? $profile['triage_fields'] : [];
            $missingBeforeSchedule = (new AgentPolicyEngineService())->missingRequiredBeforeSchedule($fields, $collected);
            $missingCompletion = (new AgentPolicyEngineService())->missingForCompletion($fields, $collected);
            $missingKeys = array_values(array_map(static fn (array $field): string => (string) ($field['field_key'] ?? ''), $missingCompletion));

            $status = 'collecting';
            $eligibility = 'pending';
            $blockReason = null;
            $nextField = null;
            $lastIntent = $schedulingIntent ? 'schedule' : ((string) ($session['last_intent'] ?? '') ?: 'conversation');
            $decisionType = (string) ($decision['decision'] ?? 'allow');
            $restrictionScope = (string) (($decision['evidence']['restriction_scope'] ?? ''));
            $isScopedCalendarRestriction = $restrictionScope === 'calendar'
                && in_array($decisionType, ['block', 'warn'], true);

            if ($isScopedCalendarRestriction) {
                // Uma regra como idade mínima com ação "Não permitir agenda" não encerra
                // a conversa. Ela encerra apenas o fluxo de agenda e mantém o atendimento
                // disponível para orientação, indicação ou esclarecimentos.
                $status = 'completed';
                $eligibility = 'blocked';
                $blockReason = (string) ($decision['code'] ?? 'calendar_restricted');
                $nextField = null;
                $lastIntent = 'conversation';
            } elseif (!$decision['allowed'] && $decisionType === 'block') {
                $status = 'blocked';
                $eligibility = 'blocked';
                $blockReason = (string) ($decision['code'] ?? 'blocked');
            } elseif (!$decision['allowed'] && $decisionType === 'handoff') {
                $status = 'handoff';
                $eligibility = 'eligible';
                $blockReason = (string) ($decision['code'] ?? 'human_approval_required');
            } elseif ($schedulingIntent && $missingBeforeSchedule !== []) {
                $status = 'collecting';
                $eligibility = 'pending';
                $nextField = (string) ($missingBeforeSchedule[0]['field_key'] ?? '');
            } else {
                $eligibility = !empty($profile['capabilities']['eligibility.enabled']) ? 'eligible' : 'not_required';
                $status = $missingCompletion === [] ? 'ready' : 'collecting';
            }

            $this->saveSession(
                $pdo,
                $tenantId,
                $conversationId,
                $contactId,
                $status,
                $eligibility,
                $blockReason,
                $nextField,
                $collected,
                $missingKeys,
                $lastIntent
            );
            $this->syncContactName($pdo, $tenantId, $contactId, $contact, $collected);

            $result['collected'] = $collected;
            $result['missing'] = $missingKeys;
            $result['allowed'] = (bool) ($decision['allowed'] ?? true);
            $result['code'] = (string) ($decision['code'] ?? 'allowed');
            $result['message'] = $decision['message'] ?? null;
            $result['decision'] = $decision;

            if (!$decision['allowed'] && in_array($decisionType, ['block', 'handoff'], true)) {
                $alreadyLogged = $isScopedCalendarRestriction
                    && $this->hasPriorPolicyDecision($pdo, $tenantId, $conversationId, (string) ($decision['policy_key'] ?? ''), (string) ($decision['code'] ?? ''));
                if (!$alreadyLogged) {
                    $this->logDecision($pdo, $tenantId, $conversationId, $contactId, $decision, $collected);
                }
                $result['handled'] = true;
                $result['skip_ai'] = true;
                $result['terminal_handled'] = true;
                $result['conversation_continues'] = $isScopedCalendarRestriction;
                if ($sendMessages && trim((string) ($decision['message'] ?? '')) !== '') {
                    $send = (new ConversationAutomationMessageService())->send(
                        $pdo,
                        $instance,
                        $conversationId,
                        $contactId,
                        (string) $decision['message'],
                        $isScopedCalendarRestriction ? 'agent.policy.calendar_restricted' : 'agent.policy.blocked',
                        [
                            'policy' => $decision['policy_key'] ?? null,
                            'code' => $decision['code'] ?? null,
                            'scope' => $restrictionScope !== '' ? $restrictionScope : null,
                        ]
                    );
                    $result['message_sent'] = !empty($send['ok']);
                    $result['message_error'] = $send['error'] ?? null;
                }
                return $result;
            }

            if ($decisionType === 'warn' && $isScopedCalendarRestriction) {
                // A restrição já foi identificada, mas a conversa não fica travada. Na
                // primeira ocorrência enviamos a mensagem configurada; depois deixamos a
                // IA conversar normalmente, mantendo a agenda protegida pelo schedulingGate.
                $alreadyNotified = $this->hasPriorPolicyDecision(
                    $pdo,
                    $tenantId,
                    $conversationId,
                    (string) ($decision['policy_key'] ?? ''),
                    (string) ($decision['code'] ?? '')
                );

                if (!$alreadyNotified) {
                    $this->logDecision($pdo, $tenantId, $conversationId, $contactId, $decision, $collected);
                    $result['handled'] = true;
                    $result['skip_ai'] = true;
                    $result['terminal_handled'] = true;
                    $result['conversation_continues'] = true;
                    if ($sendMessages && trim((string) ($decision['message'] ?? '')) !== '') {
                        $send = (new ConversationAutomationMessageService())->send(
                            $pdo,
                            $instance,
                            $conversationId,
                            $contactId,
                            (string) $decision['message'],
                            'agent.policy.calendar_restricted',
                            [
                                'policy' => $decision['policy_key'] ?? null,
                                'code' => $decision['code'] ?? null,
                                'scope' => 'calendar',
                            ]
                        );
                        $result['message_sent'] = !empty($send['ok']);
                        $result['message_error'] = $send['error'] ?? null;
                    }
                    return $result;
                }

                $result['handled'] = false;
                $result['skip_ai'] = false;
                $result['terminal_handled'] = false;
                $result['allowed'] = true;
                $result['conversation_continues'] = true;
                $result['code'] = 'calendar_restriction_active';
                return $result;
            }

            if ($schedulingIntent && $missingBeforeSchedule !== []) {
                $field = $missingBeforeSchedule[0];
                $message = trim((string) ($field['prompt_text'] ?? '')) ?: 'Antes de consultar a agenda, preciso confirmar uma informação.';
                $interactionMode = strtolower(trim((string) ($profile['interaction_mode'] ?? 'hybrid')));
                $result['handled'] = true;
                $result['allowed'] = false;
                $result['code'] = 'triage_incomplete';
                $result['message'] = $message;
                $result['next_field'] = (string) ($field['field_key'] ?? '');
                $result['decision'] = [
                    'allowed' => false,
                    'decision' => 'collect',
                    'code' => 'triage_incomplete',
                    'message' => $message,
                    'policy_key' => 'required_before_schedule',
                    'evidence' => ['next_field' => $result['next_field'], 'missing_fields' => array_values(array_map(static fn (array $row): string => (string) ($row['field_key'] ?? ''), $missingBeforeSchedule))],
                ];
                $this->logDecision($pdo, $tenantId, $conversationId, $contactId, $result['decision'], $collected);

                if ($interactionMode === 'prompt' && !$sendMessages) {
                    $result['skip_ai'] = false;
                    $result['terminal_handled'] = false;
                } elseif ($interactionMode === 'prompt') {
                    // No modo Prompt Studio, a IA redige a pergunta, mas a agenda continua bloqueada.
                    $result['skip_ai'] = false;
                    $result['terminal_handled'] = false;
                    $result['prompt_mode'] = true;
                } else {
                    $result['skip_ai'] = true;
                    $result['terminal_handled'] = true;
                    if ($sendMessages) {
                        $send = (new ConversationAutomationMessageService())->send(
                            $pdo,
                            $instance,
                            $conversationId,
                            $contactId,
                            $message,
                            'agent.triage.collect',
                            ['field_key' => $result['next_field']]
                        );
                        $result['message_sent'] = !empty($send['ok']);
                        $result['message_error'] = $send['error'] ?? null;
                    }
                }
                return $result;
            }

            $result['allowed'] = true;
            $result['code'] = 'triage_ready';
            return $result;
        } catch (Throwable $exception) {
            // Se já identificamos intenção de agenda em um tenant protegido, um erro de
            // regra nunca libera a IA para improvisar uma ação crítica.
            $result['error'] = $exception->getMessage();
            $profile = is_array($result['profile'] ?? null) ? $result['profile'] : [];
            $failClosedConfigured = !array_key_exists('policy.fail_closed', (array) ($profile['capabilities'] ?? []))
                || !empty($profile['capabilities']['policy.fail_closed']);
            if (($forceScheduling || !empty($result['scheduling_intent'])) && $failClosedConfigured) {
                $result['handled'] = true;
                $result['allowed'] = false;
                $result['skip_ai'] = true;
                $result['terminal_handled'] = true;
                $result['code'] = 'policy_engine_error';
                $result['message'] = 'Não consegui validar as regras necessárias para consultar a agenda agora. Vou manter seu pedido registrado para a equipe.';
                if ($sendMessages) {
                    try {
                        $send = (new ConversationAutomationMessageService())->send(
                            $pdo,
                            $instance,
                            $conversationId,
                            $contactId,
                            (string) $result['message'],
                            'agent.policy.error',
                            ['code' => 'policy_engine_error']
                        );
                        $result['message_sent'] = !empty($send['ok']);
                    } catch (Throwable) {
                    }
                }
            }
            return $result;
        }
    }

    private function extractDeterministic(array $collected, string $contextText, string $normalized, ?string $currentField = null, ?string $latestMessage = null): array
    {
        $latestMessage ??= $contextText;
        $latestNormalized = $this->normalize($latestMessage);

        if (preg_match('/\b(minha|meu)\s+(filha|filho|esposa|esposo|mae|mãe|pai|irma|irmã|irmao|irmão|sobrinha|sobrinho|neta|neto)\b/u', mb_strtolower($contextText), $match)) {
            $collected['is_for_self'] = false;
            $collected['relationship'] = $this->normalize($match[2] ?? 'familiar');
        } elseif (preg_match('/\b(e|é)\s+para\s+mim\b|\bpara\s+mim\s+mesm[oa]\b|\batendimento\s+(e|é)\s+para\s+mim\b/u', $normalized)) {
            $collected['is_for_self'] = true;
        }

        if ($currentField === 'is_for_self') {
            if (preg_match('/^(sim|sou eu|para mim|eu mesmo|eu mesma)\b/u', $latestNormalized)) {
                $collected['is_for_self'] = true;
            } elseif (preg_match('/^(nao|não|para (minha|meu)|minha|meu)\b/u', $latestNormalized)) {
                $collected['is_for_self'] = false;
            }
        }

        $age = $this->extractAge($contextText);
        if ($age !== null) {
            $collected['patient_age'] = $age;
        }

        if (preg_match('/\bpresencial\b/u', $normalized)) {
            $collected['modality'] = 'presencial';
        } elseif (preg_match('/\bonline\b|\bmeet\b|\bvideo\b/u', $normalized)) {
            $collected['modality'] = 'online';
        }

        if (preg_match('/\bterapia\s+de\s+casal\b|\bcasal\b|\bconjugal\b/u', $normalized)) {
            $collected['couple_intent'] = true;
        }

        if ($this->hasSchedulePreference($normalized)) {
            $collected['preferred_schedule'] = $this->extractSchedulePreference($contextText);
        }

        if ($currentField !== null && $currentField !== '') {
            $value = $this->captureCurrentFieldValue($currentField, $latestMessage, $latestNormalized);
            if ($value !== null && $value !== '') {
                $collected[$currentField] = $value;
            }
        }

        if (($currentField === 'brief_demand' || (!isset($collected['brief_demand']) && preg_match('/\b(ansiedade|depress|terapia|psicolog|sofrimento|emocion|relacionamento|luto|crise|acompanhamento)\b/u', $normalized)))
            && mb_strlen(trim($latestMessage)) >= 12) {
            $collected['brief_demand'] = mb_substr(trim($latestMessage), 0, 1200);
        }

        return $collected;
    }

    private function captureCurrentFieldValue(string $fieldKey, string $message, string $normalized): mixed
    {
        $message = trim($message);
        if ($message === '') {
            return null;
        }
        return match ($fieldKey) {
            'patient_age' => $this->extractAge($message),
            'modality' => preg_match('/\bpresencial\b/u', $normalized) ? 'presencial' : (preg_match('/\bonline\b|\bmeet\b/u', $normalized) ? 'online' : null),
            'is_for_self' => preg_match('/^(sim|sou eu|para mim|eu mesm[oa])\b/u', $normalized) ? true : (preg_match('/^(nao|não|minha|meu|para minha|para meu)\b/u', $normalized) ? false : null),
            'requester_name' => $this->looksLikeSimpleName($message) ? mb_substr($message, 0, 150) : null,
            'patient_name' => $this->looksLikeSimpleName($message) ? mb_substr($message, 0, 150) : null,
            'service', 'professional', 'contact_source' => mb_strlen($message) <= 250 ? mb_substr($message, 0, 250) : null,
            'preferred_schedule' => $this->hasSchedulePreference($normalized) ? $this->extractSchedulePreference($message) : null,
            'brief_demand' => mb_strlen($message) >= 3 ? mb_substr($message, 0, 1200) : null,
            default => mb_strlen($message) <= 500 ? mb_substr($message, 0, 500) : null,
        };
    }

    private function extractAge(string $text): ?int
    {
        $text = mb_strtolower($text);
        $patterns = [
            '/\b(?:tem|tenho|idade\s*(?:de|é|e)?|vai\s+fazer|vai\s+completar|faz|fez|completou)\s*(\d{1,3})\s*anos?\b/u',
            '/\b(\d{1,3})\s*anos?\b/u',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $match)) {
                $age = (int) ($match[1] ?? 0);
                if ($age > 0 && $age < 130) {
                    return $age;
                }
            }
        }
        return null;
    }

    private function hasSchedulingIntent(string $normalized): bool
    {
        return (bool) preg_match('/\b(agendar|agendamento|marcar|remarcar|desmarcar|consulta|horario|horário|disponibilidade|encaixe|demonstracao|demonstração|reuniao|reunião)\b/u', $normalized)
            && !preg_match('/\b(nao quero agendar|não quero agendar|sem agendar)\b/u', $normalized);
    }

    private function hasSchedulePreference(string $normalized): bool
    {
        return (bool) preg_match('/\b(segunda|terca|terça|quarta|quinta|sexta|sabado|sábado|domingo|amanha|amanhã|hoje)\b/u', $normalized)
            || (bool) preg_match('/\b\d{1,2}(?::\d{2})?\s*h\b/u', $normalized)
            || (bool) preg_match('/\b\d{1,2}:\d{2}\b/u', $normalized);
    }

    private function extractSchedulePreference(string $text): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        return mb_substr($text, 0, 300);
    }

    private function recentIncomingContext(PDO $pdo, int $conversationId, string $fallback): string
    {
        try {
            $stmt = $pdo->prepare(
                'SELECT content FROM (
                    SELECT id, content FROM conversation_messages
                    WHERE conversation_id = :conversation_id AND direction = "incoming" AND message_type = "text"
                    ORDER BY sent_at DESC, id DESC LIMIT 8
                 ) recent ORDER BY id ASC'
            );
            $stmt->execute(['conversation_id' => $conversationId]);
            $parts = array_values(array_filter(array_map(static fn (array $row): string => trim((string) ($row['content'] ?? '')), $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [])));
            if ($parts !== []) {
                return implode("\n", $parts);
            }
        } catch (Throwable) {
        }
        return $fallback;
    }

    private function session(PDO $pdo, int $tenantId, int $conversationId): array
    {
        if (!$this->tableExists($pdo, 'conversation_triage_sessions')) {
            return [];
        }
        $stmt = $pdo->prepare('SELECT * FROM conversation_triage_sessions WHERE tenant_id = :tenant_id AND conversation_id = :conversation_id LIMIT 1');
        $stmt->execute(['tenant_id' => $tenantId, 'conversation_id' => $conversationId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        if ($row !== []) {
            $row['collected'] = $this->decodeJson($row['collected_json'] ?? null);
            $row['missing'] = $this->decodeJson($row['missing_json'] ?? null);
        }
        return $row;
    }

    private function saveSession(PDO $pdo, int $tenantId, int $conversationId, int $contactId, string $status, string $eligibility, ?string $blockReason, ?string $currentField, array $collected, array $missing, string $lastIntent): void
    {
        $pdo->prepare(
            'INSERT INTO conversation_triage_sessions
                (tenant_id, conversation_id, contact_id, status, eligibility_status, block_reason,
                 current_field_key, collected_json, missing_json, last_intent, last_evaluated_at)
             VALUES
                (:tenant_id, :conversation_id, :contact_id, :status, :eligibility, :block_reason,
                 :current_field, :collected_json, :missing_json, :last_intent, NOW())
             ON DUPLICATE KEY UPDATE
                contact_id = VALUES(contact_id), status = VALUES(status), eligibility_status = VALUES(eligibility_status),
                block_reason = VALUES(block_reason), current_field_key = VALUES(current_field_key),
                collected_json = VALUES(collected_json), missing_json = VALUES(missing_json), last_intent = VALUES(last_intent),
                last_evaluated_at = NOW(), updated_at = CURRENT_TIMESTAMP'
        )->execute([
            'tenant_id' => $tenantId,
            'conversation_id' => $conversationId,
            'contact_id' => $contactId,
            'status' => $status,
            'eligibility' => $eligibility,
            'block_reason' => $blockReason,
            'current_field' => $currentField !== '' ? $currentField : null,
            'collected_json' => json_encode($collected, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'missing_json' => json_encode($missing, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'last_intent' => $lastIntent,
        ]);
    }

    private function hasPriorPolicyDecision(PDO $pdo, int $tenantId, int $conversationId, string $policyKey, string $reasonCode): bool
    {
        if ($policyKey === '' || !$this->tableExists($pdo, 'conversation_policy_decisions')) {
            return false;
        }
        try {
            $stmt = $pdo->prepare(
                'SELECT 1
                 FROM conversation_policy_decisions
                 WHERE tenant_id = :tenant_id
                   AND conversation_id = :conversation_id
                   AND policy_key = :policy_key
                   AND (:reason_code = \'\' OR reason_code = :reason_code_match)
                 ORDER BY id DESC
                 LIMIT 1'
            );
            $stmt->execute([
                'tenant_id' => $tenantId,
                'conversation_id' => $conversationId,
                'policy_key' => mb_substr($policyKey, 0, 120),
                'reason_code' => mb_substr($reasonCode, 0, 120),
                'reason_code_match' => mb_substr($reasonCode, 0, 120),
            ]);
            return (bool) $stmt->fetchColumn();
        } catch (Throwable) {
            return false;
        }
    }

    private function logDecision(PDO $pdo, int $tenantId, int $conversationId, int $contactId, array $decision, array $collected): void
    {
        if (!$this->tableExists($pdo, 'conversation_policy_decisions')) {
            return;
        }
        try {
            $pdo->prepare(
                'INSERT INTO conversation_policy_decisions
                    (tenant_id, conversation_id, contact_id, policy_key, decision, reason_code, evidence_json)
                 VALUES
                    (:tenant_id, :conversation_id, :contact_id, :policy_key, :decision, :reason_code, :evidence_json)'
            )->execute([
                'tenant_id' => $tenantId,
                'conversation_id' => $conversationId,
                'contact_id' => $contactId,
                'policy_key' => mb_substr((string) ($decision['policy_key'] ?? 'triage'), 0, 120),
                'decision' => in_array((string) ($decision['decision'] ?? ''), ['allow', 'block', 'collect', 'handoff', 'warn'], true) ? (string) $decision['decision'] : 'warn',
                'reason_code' => mb_substr((string) ($decision['code'] ?? ''), 0, 120) ?: null,
                'evidence_json' => json_encode([
                    'decision_evidence' => $decision['evidence'] ?? [],
                    'collected' => $this->redactEvidence($collected),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        } catch (Throwable) {
        }
    }

    private function redactEvidence(array $collected): array
    {
        $safe = $collected;
        foreach (['brief_demand'] as $key) {
            if (isset($safe[$key])) {
                $safe[$key] = '[coletado]';
            }
        }
        return $safe;
    }

    private function contact(PDO $pdo, int $tenantId, int $contactId): array
    {
        $stmt = $pdo->prepare('SELECT id, name, phone, name_source FROM contacts WHERE id = :id AND tenant_id = :tenant_id LIMIT 1');
        $stmt->execute(['id' => $contactId, 'tenant_id' => $tenantId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    private function syncContactName(PDO $pdo, int $tenantId, int $contactId, array $contact, array $collected): void
    {
        $name = trim((string) ($collected['requester_name'] ?? ''));
        if ($name === '' || !$this->looksLikeSimpleName($name)) {
            return;
        }
        $current = trim((string) ($contact['name'] ?? ''));
        if ($this->isUsableContactName($current, (string) ($contact['phone'] ?? ''))) {
            return;
        }
        try {
            $pdo->prepare('UPDATE contacts SET name = :name, name_source = "conversation" WHERE id = :id AND tenant_id = :tenant_id')
                ->execute(['name' => mb_substr($name, 0, 150), 'id' => $contactId, 'tenant_id' => $tenantId]);
        } catch (Throwable) {
            try {
                $pdo->prepare('UPDATE contacts SET name = :name WHERE id = :id AND tenant_id = :tenant_id')
                    ->execute(['name' => mb_substr($name, 0, 150), 'id' => $contactId, 'tenant_id' => $tenantId]);
            } catch (Throwable) {
            }
        }
    }

    private function isUsableContactName(string $name, string $phone): bool
    {
        $normalized = $this->normalize($name);
        $digits = preg_replace('/\D+/', '', $name) ?: '';
        $phoneDigits = preg_replace('/\D+/', '', $phone) ?: '';
        if ($normalized === '' || preg_match('/contato sem nome|sem nome|unknown|desconhecido/u', $normalized)) {
            return false;
        }
        if ($digits !== '' && ($digits === $phoneDigits || strlen($digits) >= 8)) {
            return false;
        }
        return (bool) preg_match('/[a-zà-ÿ]/ui', $name);
    }

    private function looksLikeSimpleName(string $value): bool
    {
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > 80 || str_contains($value, '?')) {
            return false;
        }
        $normalized = $this->normalize($value);
        if (preg_match('/\b(agendar|consulta|horario|horário|filha|filho|anos|online|presencial|quero|gostaria)\b/u', $normalized)) {
            return false;
        }
        return (bool) preg_match('/^[\p{L}][\p{L}\p{M}\'\-\. ]{1,79}$/u', $value);
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = strtr($value, [
            'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a',
            'é' => 'e', 'ê' => 'e', 'í' => 'i',
            'ó' => 'o', 'ô' => 'o', 'õ' => 'o',
            'ú' => 'u', 'ü' => 'u', 'ç' => 'c',
        ]);
        return trim((string) preg_replace('/[^a-z0-9:]+/u', ' ', $value));
    }

    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function tableExists(PDO $pdo, string $table): bool
    {
        try {
            $stmt = $pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table LIMIT 1');
            $stmt->execute(['table' => $table]);
            return (bool) $stmt->fetchColumn();
        } catch (Throwable) {
            return false;
        }
    }
}
