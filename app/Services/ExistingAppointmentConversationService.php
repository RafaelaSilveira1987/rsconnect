<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use Throwable;

/**
 * Camada determinística anterior à triagem/novo agendamento.
 * Reconhece quando o contato está falando de um compromisso que já existe e impede
 * que uma consulta de status seja confundida com uma nova busca de disponibilidade.
 */
final class ExistingAppointmentConversationService
{
    /**
     * @param array<string,mixed> $instance
     * @return array<string,mixed>
     */
    public function handleIncoming(
        PDO $pdo,
        array $instance,
        int $contactId,
        int $conversationId,
        string $content,
        int $incomingMessageId = 0,
        bool $outsideBusinessHours = false
    ): array {
        $tenantId = (int) ($instance['tenant_id'] ?? 0);
        $content = trim($content);
        if ($tenantId < 1 || $contactId < 1 || $conversationId < 1 || $content === '') {
            return $this->result(false, false, 'invalid_input');
        }

        $communication = new CalendarClientCommunicationService();
        $settings = $communication->settings($tenantId);
        if (empty($settings['lookup_enabled'])) {
            return $this->result(false, false, 'lookup_disabled');
        }

        if ($incomingMessageId > 0 && $this->alreadyHandled($pdo, $tenantId, $conversationId, $incomingMessageId)) {
            return $this->result(true, true, 'already_handled');
        }

        $appointment = $this->findRelevantAppointment($pdo, $tenantId, $contactId, $conversationId);
        $presencePending = is_array($appointment)
            && (string) ($appointment['client_confirmation_status'] ?? '') === 'pending';
        $intent = $this->detectIntent($content, $presencePending);
        if ($intent === '') {
            return $this->result(false, false, 'not_existing_appointment_intent');
        }

        if ($outsideBusinessHours && empty($settings['lookup_outside_hours'])) {
            return array_merge($this->result(false, false, 'deferred_outside_hours'), [
                'deferred' => true,
                'intent' => $intent,
            ]);
        }

        if (!$appointment) {
            // Respostas curtas de presença só fazem sentido quando há um compromisso alvo.
            if (in_array($intent, ['presence_confirm', 'presence_decline'], true)) {
                return $this->result(false, false, 'no_appointment_for_presence');
            }
            $context = $this->contactContext($pdo, $tenantId, $contactId, $conversationId);
            if (!$context) {
                return $this->result(false, false, 'no_appointment_and_contact_context_missing');
            }
            $message = trim((string) ($settings['lookup_no_appointment_message'] ?? ''))
                ?: 'Não encontrei um agendamento futuro ativo para este contato.';
            $send = $communication->sendAppointmentMessage(
                $context,
                $message,
                'calendar.existing_appointment_not_found',
                ['intent' => $intent, 'incoming_message_id' => $incomingMessageId ?: null]
            );
            $this->markHandled($pdo, $tenantId, $conversationId, $incomingMessageId, $intent, 0, $send['ok'] ?? false);
            return array_merge($this->result(true, true, 'appointment_not_found'), [
                'intent' => $intent,
                'message_sent' => !empty($send['ok']),
                'send_error' => $send['error'] ?? null,
            ]);
        }

        $appointmentId = (int) ($appointment['id'] ?? 0);

        // Enquanto o contato ainda está escolhendo/aguardando validação de uma vaga,
        // uma troca de modalidade pertence à máquina normal de pré-agendamento. Não a
        // interceptamos aqui: PreSchedulingService invalida a busca antiga, libera hold
        // e consulta novamente usando a configuração atual da empresa.
        if ($intent === 'modality_change'
            && in_array((string) ($appointment['status'] ?? ''), ['pre_scheduled', 'awaiting_approval'], true)) {
            return array_merge($this->result(false, false, 'pending_schedule_modality_change'), [
                'intent' => $intent,
                'appointment_id' => $appointmentId,
            ]);
        }

        $message = '';
        if (in_array($intent, ['status', 'details'], true)) {
            $message = $this->appointmentStatusMessage($appointment, $intent);
            $this->recordLookup($pdo, $tenantId, $appointmentId, $intent);
        } elseif ($intent === 'presence_confirm') {
            $this->updateClientConfirmation($pdo, $tenantId, $appointmentId, 'confirmed');
            $message = 'Perfeito. Registrei sua confirmação de presença para ' . $this->dateTimeLabel($appointment) . '.';
        } elseif ($intent === 'presence_decline') {
            $this->updateClientConfirmation($pdo, $tenantId, $appointmentId, 'declined');
            $message = 'Entendi. Registrei que você não poderá comparecer. A equipe foi avisada para orientar os próximos passos.';
            $this->notifyTeam($tenantId, $appointment, 'Cliente informou que não poderá comparecer', 'calendar.client_presence_declined');
        } elseif ($intent === 'modality_change') {
            $preference = (new SchedulingPreferenceResolverService())->resolve($content, true);
            $requestedModality = (string) ($preference['location_type'] ?? 'indefinida');
            $currentModality = strtolower(trim((string) ($appointment['appointment_modality'] ?? $appointment['location_type'] ?? '')));
            $behavior = new AgentConversationBehaviorService();
            $policy = $behavior->modalityPolicy($tenantId, $pdo);
            $mode = (string) ($policy['mode'] ?? 'not_applicable');

            if ($mode === 'not_applicable') {
                $message = 'A forma de atendimento não é uma opção configurada para este agendamento.';
            } elseif ($mode === 'single') {
                $fixed = strtolower(trim((string) ($policy['fixed_modality'] ?? 'presencial')));
                $label = $fixed === 'online' ? 'online' : 'presencial';
                $message = $currentModality === $fixed
                    ? 'Seu agendamento já está registrado como ' . $label . '.'
                    : 'Este atendimento está configurado somente como ' . $label . '. A equipe pode orientar caso seja necessário algum ajuste.';
            } else {
                $behaviorSettings = $behavior->settingsForTenant($tenantId, $pdo);
                $enabled = [];
                foreach (['online' => 'online', 'presencial' => 'presencial'] as $key => $label) {
                    if (!empty($behaviorSettings['modalities'][$key]['enabled'])) {
                        $enabled[$key] = $label;
                    }
                }

                if (!in_array($requestedModality, ['online', 'presencial'], true)) {
                    $labels = array_values($enabled);
                    $message = $labels !== []
                        ? 'Claro. Para qual forma de atendimento você quer alterar: ' . implode(' ou ', $labels) . '?'
                        : 'A empresa não possui outra forma de atendimento habilitada para este agendamento.';
                } elseif (!isset($enabled[$requestedModality])) {
                    $labels = array_values($enabled);
                    $message = $labels !== []
                        ? 'Essa forma de atendimento não está habilitada. As opções configuradas são: ' . implode(' e ', $labels) . '.'
                        : 'Essa forma de atendimento não está habilitada para este agendamento.';
                } elseif ($currentModality === $requestedModality) {
                    $message = 'Seu agendamento já está registrado como ' . $enabled[$requestedModality] . '.';
                } else {
                    // Em compromisso já confirmado/agendado, mudar modalidade pode alterar
                    // local, link, profissional ou disponibilidade. Por segurança não
                    // sobrescrevemos o compromisso em silêncio: registramos como ajuste e
                    // preservamos o horário atual até a validação da nova configuração.
                    $this->updateClientConfirmation($pdo, $tenantId, $appointmentId, 'reschedule_requested');
                    $message = 'Registrei seu pedido para alterar a forma de atendimento para ' . $enabled[$requestedModality]
                        . '. O agendamento atual de ' . $this->dateTimeLabel($appointment)
                        . ' permanece válido até a alteração ser confirmada.';
                    $this->notifyTeam(
                        $tenantId,
                        $appointment,
                        'Cliente solicitou troca da forma de atendimento para ' . $enabled[$requestedModality],
                        'calendar.client_modality_change_requested'
                    );
                }
            }
        } elseif ($intent === 'cancel') {
            $this->updateClientConfirmation($pdo, $tenantId, $appointmentId, 'cancel_requested');
            $message = 'Registrei seu pedido de cancelamento do atendimento de ' . $this->dateTimeLabel($appointment) . '. A equipe foi avisada e confirmará a alteração por aqui.';
            $this->notifyTeam($tenantId, $appointment, 'Cliente solicitou cancelamento', 'calendar.client_cancel_requested');
        } elseif ($intent === 'reschedule') {
            $this->updateClientConfirmation($pdo, $tenantId, $appointmentId, 'reschedule_requested');
            $message = 'Registrei seu pedido de remarcação do atendimento de ' . $this->dateTimeLabel($appointment) . '. O horário atual permanece registrado até a nova opção ser validada.';
            $this->notifyTeam($tenantId, $appointment, 'Cliente solicitou remarcação', 'calendar.client_reschedule_requested');
        }

        if ($message === '') {
            return $this->result(false, false, 'intent_without_message');
        }

        $send = $communication->sendAppointmentMessage(
            $appointment,
            $message,
            'calendar.existing_appointment_' . $intent,
            ['appointment_id' => $appointmentId, 'incoming_message_id' => $incomingMessageId ?: null]
        );
        $this->markHandled($pdo, $tenantId, $conversationId, $incomingMessageId, $intent, $appointmentId, $send['ok'] ?? false);

        return array_merge($this->result(true, true, 'existing_appointment_' . $intent), [
            'intent' => $intent,
            'appointment_id' => $appointmentId,
            'appointment_status' => (string) ($appointment['status'] ?? ''),
            'client_confirmation_status' => $intent === 'presence_confirm'
                ? 'confirmed'
                : ($intent === 'presence_decline' ? 'declined' : ($appointment['client_confirmation_status'] ?? null)),
            'message_sent' => !empty($send['ok']),
            'send_error' => $send['error'] ?? null,
        ]);
    }

    /**
     * Classificador intencionalmente conservador: só intercepta quando há sinal claro
     * de um compromisso existente, pedido de cancelamento/remarcação ou resposta a uma
     * confirmação de presença pendente.
     */
    public function detectIntent(string $content, bool $presencePending = false): string
    {
        $text = $this->normalize($content);
        if ($text === '') {
            return '';
        }

        $appointmentReference = (bool) preg_match(
            '/\b(agendamento|consulta|sessao|atendimento|horario|retorno|compromisso|reserva)\b/u',
            $text
        );

        if ((bool) preg_match('/\b(trocar|mudar|alterar|passar)\b.{0,40}\b(modalidade|forma\s+de\s+atendimento|online|presencial)\b/u', $text)
            || (bool) preg_match('/\b(em\s+vez\s+de|na\s+verdade)\b.{0,35}\b(online|presencial)\b/u', $text)) {
            return 'modality_change';
        }

        if ((bool) preg_match('/\b(remarcar|reagendar)\b/u', $text)
            || (bool) preg_match('/\b(trocar|mudar|alterar)\b.{0,24}\b(dia|data|horario|consulta|agendamento)\b/u', $text)) {
            return 'reschedule';
        }

        if ((bool) preg_match('/\b(cancelar|desmarcar|cancelamento)\b/u', $text)) {
            return 'cancel';
        }

        $cannotAttend = (bool) preg_match(
            '/\b(nao vou conseguir|nao poderei|nao consigo|nao vou|nao posso)\b.{0,28}\b(ir|comparecer|estar presente|chegar)\b/u',
            $text
        );
        if ($cannotAttend && ($presencePending || $appointmentReference)) {
            return 'presence_decline';
        }

        $explicitPresence = (bool) preg_match(
            '/\b(confirmo|confirmado|vou sim|estarei la|estarei presente|pode confirmar|pode deixar confirmado|comparecerei)\b/u',
            $text
        );
        $shortYes = (bool) preg_match('/^(sim|s|ok|okay|confirmo|combinado|certo)[.! ]*$/u', $text);
        if (($presencePending && ($shortYes || $explicitPresence))
            || ($appointmentReference && $explicitPresence)) {
            return 'presence_confirm';
        }

        if (!$appointmentReference) {
            return '';
        }

        $statusQuestion = (bool) preg_match(
            '/\b(confirmad[oa]|esta confirmad[oa]|foi confirmad[oa]|esta marcad[oa]|esta agendad[oa]|continua marcad[oa]|esta de pe|esta certo|mantido|mantida)\b/u',
            $text
        ) || (str_contains($text, 'consulta de amanha') && str_contains($text, 'confirm'));
        if ($statusQuestion) {
            return 'status';
        }

        $detailsQuestion = (bool) preg_match(
            '/\b(que horas|qual horario|qual o horario|quando|qual dia|qual data|onde|endereco|local|link|modalidade|com quem|qual profissional)\b/u',
            $text
        ) || (bool) preg_match('/\b(tenho|tenho uma|meu|minha)\b.{0,20}\b(consulta|agendamento|atendimento|retorno)\b/u', $text);
        if ($detailsQuestion) {
            return 'details';
        }

        return '';
    }

    /** @return array<string,mixed>|null */
    private function findRelevantAppointment(PDO $pdo, int $tenantId, int $contactId, int $conversationId): ?array
    {
        try {
            $statement = $pdo->prepare(
                'SELECT a.*, ct.name AS contact_name, ct.phone, ct.remote_jid,
                        ct.evolution_instance_id AS contact_instance_id,
                        c.evolution_instance_id AS conversation_instance_id,
                        u.name AS owner_name
                 FROM calendar_appointments a
                 LEFT JOIN contacts ct ON ct.id = a.contact_id AND ct.tenant_id = a.tenant_id
                 LEFT JOIN conversations c ON c.id = a.conversation_id AND c.tenant_id = a.tenant_id
                 LEFT JOIN users u ON u.id = a.owner_user_id AND u.tenant_id = a.tenant_id
                 WHERE a.tenant_id = :tenant_id
                   AND (a.contact_id = :contact_id OR a.conversation_id = :conversation_id)
                   AND a.status IN ("pre_scheduled","awaiting_approval","scheduled","confirmed","rescheduled")
                   AND COALESCE(a.ends_at, a.starts_at) >= NOW()
                 ORDER BY
                   a.starts_at ASC,
                   CASE a.status
                     WHEN "confirmed" THEN 0
                     WHEN "scheduled" THEN 1
                     WHEN "awaiting_approval" THEN 2
                     WHEN "pre_scheduled" THEN 3
                     ELSE 4
                   END,
                   a.id DESC
                 LIMIT 1'
            );
            $statement->execute([
                'tenant_id' => $tenantId,
                'contact_id' => $contactId,
                'conversation_id' => $conversationId,
            ]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array<string,mixed>|null */
    private function contactContext(PDO $pdo, int $tenantId, int $contactId, int $conversationId): ?array
    {
        try {
            $statement = $pdo->prepare(
                'SELECT :tenant_id AS tenant_id, :conversation_id AS conversation_id,
                        ct.id AS contact_id, ct.name AS contact_name, ct.phone, ct.remote_jid,
                        ct.evolution_instance_id AS contact_instance_id,
                        c.evolution_instance_id AS conversation_instance_id
                 FROM contacts ct
                 LEFT JOIN conversations c ON c.id = :conversation_lookup AND c.tenant_id = ct.tenant_id
                 WHERE ct.id = :contact_id AND ct.tenant_id = :tenant_lookup
                 LIMIT 1'
            );
            $statement->execute([
                'tenant_id' => $tenantId,
                'conversation_id' => $conversationId,
                'conversation_lookup' => $conversationId,
                'contact_id' => $contactId,
                'tenant_lookup' => $tenantId,
            ]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable) {
            return null;
        }
    }

    private function appointmentStatusMessage(array $appointment, string $intent): string
    {
        $status = (string) ($appointment['status'] ?? '');
        $when = $this->dateTimeLabel($appointment);
        $base = match ($status) {
            'confirmed' => 'Sim. Seu atendimento está confirmado para ' . $when . '.',
            'scheduled' => 'Seu atendimento está agendado para ' . $when . '.',
            'awaiting_approval' => 'Seu pré-agendamento está registrado para ' . $when . ' e ainda aguarda aprovação da equipe.',
            'pre_scheduled' => 'Sua preferência de atendimento está registrada para ' . $when . ' e ainda aguarda validação da agenda.',
            'rescheduled' => 'Seu agendamento está em processo de remarcação. O horário registrado é ' . $when . '.',
            default => 'Encontrei seu atendimento para ' . $when . '.',
        };

        $details = [];
        $owner = trim((string) ($appointment['owner_name'] ?? ''));
        if ($owner !== '') {
            $details[] = 'Profissional: ' . $owner;
        }
        $modality = match ((string) ($appointment['location_type'] ?? '')) {
            'online' => 'Online',
            'presencial' => 'Presencial',
            'telefone' => 'Telefone',
            default => '',
        };
        if ($modality !== '') {
            $details[] = 'Modalidade: ' . $modality;
        }
        $meetingUrl = trim((string) ($appointment['meeting_url'] ?? ''));
        $location = trim((string) ($appointment['location'] ?? ''));
        if ($meetingUrl !== '') {
            $details[] = 'Link: ' . $meetingUrl;
        } elseif ($location !== '') {
            $details[] = 'Local: ' . $location;
        }

        if ($intent === 'status' && $status === 'confirmed' && $details === []) {
            return $base;
        }
        return $details !== [] ? $base . "\n" . implode("\n", $details) : $base;
    }

    private function dateTimeLabel(array $appointment): string
    {
        $startsAt = trim((string) ($appointment['starts_at'] ?? ''));
        if ($startsAt === '') {
            return 'data e horário ainda não definidos';
        }
        $timestamp = strtotime($startsAt);
        return $timestamp !== false ? date('d/m/Y', $timestamp) . ' às ' . date('H:i', $timestamp) : $startsAt;
    }

    private function updateClientConfirmation(PDO $pdo, int $tenantId, int $appointmentId, string $status): void
    {
        try {
            if (!$this->hasColumn($pdo, 'calendar_appointments', 'client_confirmation_status')) {
                return;
            }
            $pdo->prepare(
                'UPDATE calendar_appointments
                 SET client_confirmation_status = :status,
                     client_confirmation_responded_at = NOW(),
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id AND tenant_id = :tenant_id'
            )->execute(['status' => $status, 'id' => $appointmentId, 'tenant_id' => $tenantId]);
        } catch (Throwable) {
        }
    }

    private function recordLookup(PDO $pdo, int $tenantId, int $appointmentId, string $intent): void
    {
        try {
            if (!$this->hasColumn($pdo, 'calendar_appointments', 'client_last_lookup_at')) {
                return;
            }
            $pdo->prepare(
                'UPDATE calendar_appointments
                 SET client_last_lookup_at = NOW(), client_last_lookup_intent = :intent
                 WHERE id = :id AND tenant_id = :tenant_id'
            )->execute(['intent' => $intent, 'id' => $appointmentId, 'tenant_id' => $tenantId]);
        } catch (Throwable) {
        }
    }

    private function notifyTeam(int $tenantId, array $appointment, string $title, string $eventKey): void
    {
        try {
            $contact = trim((string) (($appointment['contact_name'] ?? '') ?: ($appointment['phone'] ?? 'Cliente'))) ?: 'Cliente';
            (new NotificationService())->createIfEnabled(
                $tenantId,
                'calendar',
                $title,
                $contact . ' — ' . $this->dateTimeLabel($appointment) . '.',
                'warning',
                '/calendar',
                'calendar',
                $eventKey,
                'appointment',
                (int) ($appointment['id'] ?? 0),
                ['client_confirmation_status' => (string) ($appointment['client_confirmation_status'] ?? '')],
                60
            );
        } catch (Throwable) {
        }
    }

    private function alreadyHandled(PDO $pdo, int $tenantId, int $conversationId, int $incomingMessageId): bool
    {
        try {
            $statement = $pdo->prepare(
                'SELECT 1 FROM conversation_events
                 WHERE tenant_id = :tenant_id AND conversation_id = :conversation_id
                   AND event_type = "calendar.existing_appointment_intent"
                   AND metadata_json LIKE :pattern
                 LIMIT 1'
            );
            $statement->execute([
                'tenant_id' => $tenantId,
                'conversation_id' => $conversationId,
                'pattern' => '%"incoming_message_id":' . $incomingMessageId . '%',
            ]);
            return (bool) $statement->fetchColumn();
        } catch (Throwable) {
            return false;
        }
    }

    private function markHandled(PDO $pdo, int $tenantId, int $conversationId, int $incomingMessageId, string $intent, int $appointmentId, bool $sent): void
    {
        try {
            $pdo->prepare(
                'INSERT INTO conversation_events (tenant_id, conversation_id, event_type, description, metadata_json)
                 VALUES (:tenant_id, :conversation_id, "calendar.existing_appointment_intent", :description, :metadata_json)'
            )->execute([
                'tenant_id' => $tenantId,
                'conversation_id' => $conversationId,
                'description' => 'Mensagem relacionada a um agendamento existente tratada antes do fluxo de novo atendimento.',
                'metadata_json' => json_encode([
                    'incoming_message_id' => $incomingMessageId > 0 ? $incomingMessageId : null,
                    'intent' => $intent,
                    'appointment_id' => $appointmentId > 0 ? $appointmentId : null,
                    'message_sent' => $sent,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        } catch (Throwable) {
        }
    }

    private function hasColumn(PDO $pdo, string $table, string $column): bool
    {
        try {
            $statement = $pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = :table_name AND column_name = :column_name'
            );
            $statement->execute(['table_name' => $table, 'column_name' => $column]);
            return (int) $statement->fetchColumn() > 0;
        } catch (Throwable) {
            return false;
        }
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        $value = strtr($value, [
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c',
        ]);
        $value = preg_replace('/[^a-z0-9\s:!?.,\/-]+/u', ' ', $value) ?? $value;
        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    /** @return array<string,mixed> */
    private function result(bool $handled, bool $skipAi, string $reason): array
    {
        return [
            'handled' => $handled,
            'skip_ai' => $skipAi,
            'terminal_handled' => $handled,
            'existing_appointment' => $handled,
            'reason' => $reason,
        ];
    }
}
