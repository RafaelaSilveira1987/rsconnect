<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Crypto;
use App\Core\Database;
use App\Core\Env;
use DateTimeImmutable;
use PDO;
use Throwable;

/**
 * Fecha o ciclo conversacional da agenda:
 * disponibilidade -> opções reais -> escolha do contato -> pré-reserva -> aprovação humana.
 */
final class CalendarConversationService
{
    /**
     * Executado depois que o callback do n8n gravou os horários.
     * Só comunica automaticamente consultas originadas pela conversa/IA.
     *
     * @param array<string,mixed> $request
     * @return array<string,mixed>
     */
    public function handleAvailabilityResult(array $request, string $diagnostic = ''): array
    {
        $tenantId = (int) ($request['tenant_id'] ?? 0);
        $appointmentId = (int) ($request['appointment_id'] ?? 0);
        $requestId = (int) ($request['id'] ?? 0);
        $origin = trim((string) ($request['origin'] ?? ''));

        if ($tenantId < 1 || $appointmentId < 1 || $requestId < 1) {
            return $this->result(false, 'invalid_request');
        }

        // Buscas feitas manualmente no painel não devem disparar WhatsApp sem intenção do contato.
        if (!in_array($origin, ['pre_schedule_ai', 'conversation', 'whatsapp_ai'], true)) {
            return $this->result(false, 'manual_origin');
        }

        $appointment = $this->appointmentForMessaging($tenantId, $appointmentId);
        if (!$appointment || (int) ($appointment['is_pre_schedule'] ?? 0) !== 1) {
            return $this->result(false, 'appointment_not_found');
        }

        $settings = (new PreSchedulingService())->settings($tenantId);
        if (empty($settings['enabled']) || empty($settings['ai_can_suggest_slots'])) {
            return $this->result(false, 'suggestions_disabled');
        }

        if ($this->requestAlreadyCommunicated($appointment, $requestId)) {
            return $this->result(false, 'already_communicated');
        }

        $slots = $this->slotsForRequest($tenantId, $appointmentId, $requestId);
        $this->assignSuggestionPositions($requestId, $slots);
        $slots = $this->slotsForRequest($tenantId, $appointmentId, $requestId);

        if (!$this->claimAvailabilityCommunication($tenantId, $appointmentId, $requestId)) {
            return $this->result(false, 'already_processing');
        }

        if ($slots === []) {
            $message = trim((string) ($settings['no_availability_message'] ?? ''))
                ?: 'Não encontrei horários disponíveis para essa preferência. Pode me informar outro dia ou período?';
            $send = $this->sendAppointmentMessage(
                $appointment,
                $message,
                'calendar.no_availability_sent',
                ['request_id' => $requestId, 'diagnostic' => $diagnostic]
            );
            $this->markOptionsCommunication($tenantId, $appointmentId, $requestId, $send['external_id'] ?? null, false, 'empty', (bool) $send['ok']);
            return array_merge($this->result(true, 'no_availability'), ['message_sent' => $send['ok'], 'send_error' => $send['error']]);
        }

        // Se a opção originalmente pedida estiver realmente disponível, pré-reserva sem pedir nova escolha.
        $exactSlot = $this->findExactRequestedSlot($appointment, $slots);
        if ($exactSlot !== null) {
            $apply = (new CalendarAvailabilityService())->applySlot($tenantId, $appointmentId, (int) $exactSlot['id']);
            if (!empty($apply['ok'])) {
                $this->finalizeSelection($tenantId, $appointmentId, $exactSlot, 'automatic_exact');
                $appointment = $this->appointmentForMessaging($tenantId, $appointmentId) ?: $appointment;
                $message = $this->selectedMessage($settings, $appointment, $exactSlot);
                $send = $this->sendAppointmentMessage(
                    $appointment,
                    $message,
                    'calendar.slot_auto_selected',
                    ['request_id' => $requestId, 'slot_id' => (int) $exactSlot['id']]
                );
                $this->markOptionsCommunication($tenantId, $appointmentId, $requestId, $send['external_id'] ?? null, true, 'slot_selected', (bool) $send['ok']);
                $this->notifyProfessional($tenantId, $appointment, $exactSlot, 'Horário pré-reservado automaticamente');
                if (empty($send['ok'])) {
                    $this->notifyFailure($tenantId, $appointment, (string) ($send['error'] ?? 'A pré-reserva foi feita, mas a mensagem não chegou ao contato.'));
                }
                return array_merge($this->result(true, 'exact_slot_selected'), [
                    'slot_id' => (int) $exactSlot['id'],
                    'message_sent' => $send['ok'],
                    'send_error' => $send['error'],
                ]);
            }

            // Se a pré-reserva exata falhar, não oferece novamente o mesmo horário como se ainda estivesse livre.
            $failedExactSlotId = (int) ($exactSlot['id'] ?? 0);
            $slots = array_values(array_filter(
                $slots,
                static fn (array $slot): bool => (int) ($slot['id'] ?? 0) !== $failedExactSlotId
            ));
            $diagnostic = trim($diagnostic . ' ' . (string) ($apply['message'] ?? 'Falha ao pré-reservar o horário solicitado.'));
            if ($slots === []) {
                $message = trim((string) ($settings['no_availability_message'] ?? ''))
                    ?: 'O horário solicitado acabou de ficar indisponível e não encontrei outra opção agora. Pode me informar outro dia ou período?';
                $send = $this->sendAppointmentMessage(
                    $appointment,
                    $message,
                    'calendar.no_availability_after_hold_failure',
                    ['request_id' => $requestId, 'slot_id' => $failedExactSlotId, 'diagnostic' => $diagnostic]
                );
                $this->markOptionsCommunication($tenantId, $appointmentId, $requestId, $send['external_id'] ?? null, false, 'empty', (bool) $send['ok']);
                $this->notifyFailure($tenantId, $appointment, (string) ($apply['message'] ?? 'Falha ao pré-reservar o horário solicitado.'));
                return array_merge($this->result(true, 'exact_hold_failed_no_alternatives'), [
                    'message_sent' => $send['ok'],
                    'send_error' => $send['error'],
                ]);
            }
        }

        $message = $this->optionsMessage($settings, $appointment, $slots);
        $send = $this->sendAppointmentMessage(
            $appointment,
            $message,
            'calendar.availability_options_sent',
            [
                'request_id' => $requestId,
                'slot_ids' => array_map(static fn (array $slot): int => (int) $slot['id'], $slots),
                'diagnostic' => $diagnostic,
            ]
        );
        $communicationStatus = !empty($send['ok']) ? 'options_sent' : 'received';
        $this->markOptionsCommunication($tenantId, $appointmentId, $requestId, $send['external_id'] ?? null, false, $communicationStatus, (bool) $send['ok']);
        if (empty($send['ok'])) {
            $this->notifyFailure($tenantId, $appointment, (string) ($send['error'] ?? 'Não foi possível enviar as alternativas ao contato.'));
        }

        return array_merge($this->result(true, !empty($send['ok']) ? 'options_sent' : 'options_send_failed'), [
            'slots' => count($slots),
            'message_sent' => $send['ok'],
            'send_error' => $send['error'],
        ]);
    }

    /**
     * Intercepta respostas como "1", "o primeiro" ou "17h" antes da IA.
     *
     * @param array<string,mixed> $instance
     * @return array<string,mixed>
     */
    public function handleIncomingSelection(
        PDO $pdo,
        array $instance,
        int $contactId,
        int $conversationId,
        string $content,
        int $incomingMessageId = 0
    ): array {
        $tenantId = (int) ($instance['tenant_id'] ?? 0);
        $content = trim($content);
        if ($tenantId < 1 || $conversationId < 1 || $content === '') {
            return $this->incomingResult(false, false, 'invalid_input');
        }

        // Idempotência por mensagem recebida: se esse comando já foi consumido pela agenda,
        // não deixa uma repetição do webhook ou um reprocessamento tardio cair na IA.
        if ($incomingMessageId > 0 && $this->incomingAlreadyHandledByCalendar($pdo, $incomingMessageId)) {
            return $this->incomingResult(true, true, 'already_handled');
        }

        $appointment = $this->appointmentWaitingSelection($pdo, $tenantId, $conversationId, $contactId);
        if (!$appointment) {
            return $this->incomingResult(false, false, 'no_pending_options');
        }

        $requestId = (int) ($appointment['availability_request_id'] ?? 0);
        $slots = $this->slotsForRequest($tenantId, (int) $appointment['id'], $requestId);
        if ($slots === []) {
            return $this->incomingResult(false, false, 'options_missing');
        }

        $selection = $this->resolveSelection($content, $slots);
        if (empty($selection['signal'])) {
            return $this->incomingResult(false, false, 'not_a_selection');
        }

        // Uma nova preferência completa (outro dia + horário) segue para o fluxo normal,
        // que atualiza o pré-agendamento e solicita nova disponibilidade.
        if (!empty($selection['new_preference'])) {
            return $this->incomingResult(false, false, 'new_preference');
        }

        if (empty($selection['slot'])) {
            $settings = (new PreSchedulingService())->settings($tenantId);
            $message = trim((string) ($settings['invalid_slot_message'] ?? ''))
                ?: 'Não consegui identificar uma dessas opções. Responda com o número ou com o horário desejado:';
            $message .= "\n\n" . $this->formatOptions($slots);
            $send = $this->sendAppointmentMessage(
                $appointment,
                $message,
                'calendar.invalid_slot_selection',
                [
                    'request_id' => $requestId,
                    'reason' => $selection['reason'] ?? 'not_found',
                    'incoming_message_id' => $incomingMessageId > 0 ? $incomingMessageId : null,
                ]
            );
            $this->markIncomingHandledByCalendar(
                $pdo,
                $instance,
                $conversationId,
                $incomingMessageId,
                'invalid_selection',
                (int) $appointment['id'],
                null
            );
            return array_merge($this->incomingResult(true, true, 'invalid_selection'), [
                'appointment_id' => (int) $appointment['id'],
                'message_sent' => $send['ok'],
                'send_error' => $send['error'],
            ]);
        }

        $slot = $selection['slot'];
        $apply = (new CalendarAvailabilityService())->applySlot($tenantId, (int) $appointment['id'], (int) $slot['id']);
        if (empty($apply['ok'])) {
            $remainingSlots = array_values(array_filter(
                $slots,
                static fn (array $candidate): bool => (int) $candidate['id'] !== (int) $slot['id']
            ));
            $message = $remainingSlots !== []
                ? 'Não consegui pré-reservar esse horário agora. Ele pode ter sido ocupado há poucos instantes. Escolha outra opção:' . "\n\n" . $this->formatOptions($remainingSlots)
                : 'Esse horário acabou de ficar indisponível e não restaram outras opções desta busca. Pode me informar outro dia ou período?';
            $send = $this->sendAppointmentMessage(
                $appointment,
                $message,
                'calendar.slot_hold_failed',
                [
                    'request_id' => $requestId,
                    'slot_id' => (int) $slot['id'],
                    'error' => $apply['message'] ?? null,
                    'incoming_message_id' => $incomingMessageId > 0 ? $incomingMessageId : null,
                ]
            );
            $this->markIncomingHandledByCalendar(
                $pdo,
                $instance,
                $conversationId,
                $incomingMessageId,
                'hold_failed',
                (int) $appointment['id'],
                (int) $slot['id']
            );
            $this->notifyFailure($tenantId, $appointment, (string) ($apply['message'] ?? 'Falha ao pré-reservar o horário.'));
            return array_merge($this->incomingResult(true, true, 'hold_failed'), [
                'appointment_id' => (int) $appointment['id'],
                'message_sent' => $send['ok'],
                'send_error' => $send['error'],
            ]);
        }

        $this->finalizeSelection($tenantId, (int) $appointment['id'], $slot, 'contact');
        $appointment = $this->appointmentForMessaging($tenantId, (int) $appointment['id']) ?: $appointment;
        $settings = (new PreSchedulingService())->settings($tenantId);
        $message = $this->selectedMessage($settings, $appointment, $slot);
        $send = $this->sendAppointmentMessage(
            $appointment,
            $message,
            'calendar.slot_selected_by_contact',
            [
                'request_id' => $requestId,
                'slot_id' => (int) $slot['id'],
                'incoming_message_id' => $incomingMessageId > 0 ? $incomingMessageId : null,
            ]
        );
        $this->markIncomingHandledByCalendar(
            $pdo,
            $instance,
            $conversationId,
            $incomingMessageId,
            'slot_selected',
            (int) $appointment['id'],
            (int) $slot['id']
        );
        $this->notifyProfessional($tenantId, $appointment, $slot, 'Cliente escolheu um horário');
        if (empty($send['ok'])) {
            $this->notifyFailure($tenantId, $appointment, (string) ($send['error'] ?? 'O horário foi selecionado, mas a mensagem não chegou ao contato.'));
        }

        return array_merge($this->incomingResult(true, true, 'slot_selected'), [
            'appointment_id' => (int) $appointment['id'],
            'slot_id' => (int) $slot['id'],
            'message_sent' => $send['ok'],
            'send_error' => $send['error'],
        ]);
    }

    /** @return array<string,mixed> */
    private function result(bool $handled, string $code): array
    {
        return ['handled' => $handled, 'code' => $code];
    }

    /** @return array<string,mixed> */
    private function incomingResult(bool $handled, bool $skipAi, string $code): array
    {
        return [
            'handled' => $handled,
            'skip_ai' => $skipAi,
            'terminal_handled' => $handled && $skipAi,
            'calendar_selection' => true,
            'code' => $code,
            'availability_request_needed' => false,
            'appointment_event_payload' => null,
        ];
    }

    private function incomingAlreadyHandledByCalendar(PDO $pdo, int $incomingMessageId): bool
    {
        try {
            $statement = $pdo->prepare(
                'SELECT 1
                 FROM ai_automation_logs
                 WHERE incoming_message_id = :incoming_message_id
                   AND event = "ai.skipped"
                   AND raw_json LIKE "%calendar_handled%"
                 LIMIT 1'
            );
            $statement->execute(['incoming_message_id' => $incomingMessageId]);
            return (bool) $statement->fetchColumn();
        } catch (Throwable) {
            return false;
        }
    }

    private function markIncomingHandledByCalendar(
        PDO $pdo,
        array $instance,
        int $conversationId,
        int $incomingMessageId,
        string $code,
        int $appointmentId,
        ?int $slotId
    ): void {
        if ($incomingMessageId < 1) {
            return;
        }

        try {
            if ($this->incomingAlreadyHandledByCalendar($pdo, $incomingMessageId)) {
                return;
            }

            $tenantId = (int) ($instance['tenant_id'] ?? 0);
            $instanceId = (int) ($instance['id'] ?? 0);
            $agentId = null;
            if ($tenantId > 0) {
                $agentStatement = $pdo->prepare(
                    'SELECT id
                     FROM ai_agents
                     WHERE tenant_id = :tenant_id
                       AND status = "active"
                       AND auto_reply_enabled = 1
                       AND (instance_id = :instance_id OR instance_id IS NULL OR is_default = 1)
                     ORDER BY (instance_id = :instance_id_order) DESC, is_default DESC, id DESC
                     LIMIT 1'
                );
                $agentStatement->execute([
                    'tenant_id' => $tenantId,
                    'instance_id' => $instanceId,
                    'instance_id_order' => $instanceId,
                ]);
                $value = $agentStatement->fetchColumn();
                $agentId = $value !== false ? (int) $value : null;
            }

            $raw = json_encode([
                'calendar_handled' => true,
                'calendar_code' => $code,
                'appointment_id' => $appointmentId,
                'slot_id' => $slotId,
                'skip_ai' => true,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $pdo->prepare(
                'INSERT INTO ai_automation_logs
                    (tenant_id, conversation_id, agent_id, incoming_message_id, event, status,
                     response_preview, error_message, raw_json)
                 VALUES
                    (:tenant_id, :conversation_id, :agent_id, :incoming_message_id,
                     "ai.skipped", "skipped", :response_preview, NULL, :raw_json)'
            )->execute([
                'tenant_id' => $tenantId,
                'conversation_id' => $conversationId,
                'agent_id' => $agentId,
                'incoming_message_id' => $incomingMessageId,
                'response_preview' => 'Mensagem tratada pela agenda conversacional; IA não acionada.',
                'raw_json' => $raw,
            ]);
        } catch (Throwable) {
            // O marcador melhora a idempotência e a fila, mas nunca interrompe o agendamento.
        }
    }

    /** @return array<string,mixed>|null */
    private function appointmentForMessaging(int $tenantId, int $appointmentId): ?array
    {
        $statement = Database::connection()->prepare(
            'SELECT a.*, ct.name AS contact_name, ct.phone, ct.remote_jid,
                    ct.evolution_instance_id AS contact_instance_id,
                    c.evolution_instance_id AS conversation_instance_id
             FROM calendar_appointments a
             LEFT JOIN contacts ct ON ct.id = a.contact_id AND ct.tenant_id = a.tenant_id
             LEFT JOIN conversations c ON c.id = a.conversation_id AND c.tenant_id = a.tenant_id
             WHERE a.id = :id AND a.tenant_id = :tenant_id
             LIMIT 1'
        );
        $statement->execute(['id' => $appointmentId, 'tenant_id' => $tenantId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** @return array<string,mixed>|null */
    private function appointmentWaitingSelection(PDO $pdo, int $tenantId, int $conversationId, int $contactId): ?array
    {
        $sql = 'SELECT a.*, ct.name AS contact_name, ct.phone, ct.remote_jid,
                       ct.evolution_instance_id AS contact_instance_id,
                       c.evolution_instance_id AS conversation_instance_id
                FROM calendar_appointments a
                LEFT JOIN contacts ct ON ct.id = a.contact_id AND ct.tenant_id = a.tenant_id
                LEFT JOIN conversations c ON c.id = a.conversation_id AND c.tenant_id = a.tenant_id
                WHERE a.tenant_id = :tenant_id
                  AND a.is_pre_schedule = 1
                  AND a.status IN ("pre_scheduled", "awaiting_approval", "rescheduled")
                  AND a.availability_status = "options_sent"
                  AND a.chosen_availability_slot_id IS NULL
                  AND (a.availability_selection_expires_at IS NULL OR a.availability_selection_expires_at >= NOW())
                  AND (a.conversation_id = :conversation_id OR a.contact_id = :contact_id)
                ORDER BY (a.conversation_id = :conversation_id_order) DESC, a.updated_at DESC, a.id DESC
                LIMIT 1';
        try {
            $statement = $pdo->prepare($sql);
            $statement->execute([
                'tenant_id' => $tenantId,
                'conversation_id' => $conversationId,
                'contact_id' => $contactId,
                'conversation_id_order' => $conversationId,
            ]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable) {
            // Compatibilidade antes da migration 046: usa apenas o estado já existente.
            $statement = $pdo->prepare(
                'SELECT a.*, ct.name AS contact_name, ct.phone, ct.remote_jid,
                        ct.evolution_instance_id AS contact_instance_id,
                        c.evolution_instance_id AS conversation_instance_id
                 FROM calendar_appointments a
                 LEFT JOIN contacts ct ON ct.id = a.contact_id AND ct.tenant_id = a.tenant_id
                 LEFT JOIN conversations c ON c.id = a.conversation_id AND c.tenant_id = a.tenant_id
                 WHERE a.tenant_id = :tenant_id
                   AND a.is_pre_schedule = 1
                   AND a.status IN ("pre_scheduled", "awaiting_approval", "rescheduled")
                   AND a.availability_status = "options_sent"
                   AND a.chosen_availability_slot_id IS NULL
                   AND (a.conversation_id = :conversation_id OR a.contact_id = :contact_id)
                 ORDER BY (a.conversation_id = :conversation_id_order) DESC, a.updated_at DESC, a.id DESC
                 LIMIT 1'
            );
            $statement->execute([
                'tenant_id' => $tenantId,
                'conversation_id' => $conversationId,
                'contact_id' => $contactId,
                'conversation_id_order' => $conversationId,
            ]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        }
    }

    /** @return array<int,array<string,mixed>> */
    private function slotsForRequest(int $tenantId, int $appointmentId, int $requestId): array
    {
        if ($requestId < 1) {
            return [];
        }
        $max = max(1, min(20, (int) ((new CalendarAvailabilityService())->settings($tenantId)['max_suggestions'] ?? 5)));
        try {
            $statement = Database::connection()->prepare(
                'SELECT *
                 FROM calendar_availability_slots
                 WHERE tenant_id = :tenant_id
                   AND appointment_id = :appointment_id
                   AND request_id = :request_id
                   AND event_state IN ("available", "selected", "held")
                 ORDER BY COALESCE(suggestion_position, 9999), starts_at ASC, id ASC
                 LIMIT ' . $max
            );
            $statement->execute([
                'tenant_id' => $tenantId,
                'appointment_id' => $appointmentId,
                'request_id' => $requestId,
            ]);
            return $statement->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            $statement = Database::connection()->prepare(
                'SELECT *
                 FROM calendar_availability_slots
                 WHERE tenant_id = :tenant_id
                   AND appointment_id = :appointment_id
                   AND request_id = :request_id
                   AND event_state IN ("available", "selected", "held")
                 ORDER BY starts_at ASC, id ASC
                 LIMIT ' . $max
            );
            $statement->execute([
                'tenant_id' => $tenantId,
                'appointment_id' => $appointmentId,
                'request_id' => $requestId,
            ]);
            return $statement->fetchAll(PDO::FETCH_ASSOC);
        }
    }

    /** @param array<int,array<string,mixed>> $slots */
    private function assignSuggestionPositions(int $requestId, array $slots): void
    {
        try {
            $statement = Database::connection()->prepare(
                'UPDATE calendar_availability_slots
                 SET suggestion_position = :position, suggested_at = NOW()
                 WHERE id = :id AND request_id = :request_id'
            );
            $position = 1;
            foreach ($slots as $slot) {
                $statement->execute([
                    'position' => $position++,
                    'id' => (int) $slot['id'],
                    'request_id' => $requestId,
                ]);
            }
        } catch (Throwable) {
            // A feature continua usando a ordem por data antes da migration 046.
        }
    }

    /** @param array<int,array<string,mixed>> $slots */
    private function findExactRequestedSlot(array $appointment, array $slots): ?array
    {
        $requested = trim((string) ($appointment['starts_at'] ?? ''));
        if ($requested === '') {
            return null;
        }
        $requestedKey = date('Y-m-d H:i', strtotime($requested));
        foreach ($slots as $slot) {
            if (date('Y-m-d H:i', strtotime((string) $slot['starts_at'])) === $requestedKey) {
                return $slot;
            }
        }
        return null;
    }

    /** @param array<int,array<string,mixed>> $slots @return array<string,mixed> */
    private function resolveSelection(string $content, array $slots): array
    {
        $normalized = $this->normalize($content);
        $intent = (new PreSchedulingService())->detectIntent($content);
        $position = null;

        $ordinals = [
            'primeiro' => 1, 'primeira' => 1,
            'segundo' => 2, 'segunda opcao' => 2,
            'terceiro' => 3, 'terceira' => 3,
            'quarto' => 4, 'quarta opcao' => 4,
            'quinto' => 5, 'quinta opcao' => 5,
        ];
        foreach ($ordinals as $needle => $candidate) {
            if (str_contains($normalized, $needle)) {
                $position = $candidate;
                break;
            }
        }
        if ($position === null && preg_match('/(?:^|\b)(?:opcao\s*)?(\d{1,2})(?:\b|$)/u', $normalized, $match)) {
            $candidate = (int) $match[1];
            if ($candidate >= 1 && $candidate <= count($slots)) {
                $position = $candidate;
            }
        }
        if ($position !== null) {
            foreach ($slots as $index => $slot) {
                $slotPosition = (int) ($slot['suggestion_position'] ?? ($index + 1));
                if ($slotPosition === $position) {
                    return ['signal' => true, 'slot' => $slot, 'reason' => 'position'];
                }
            }
            return ['signal' => true, 'slot' => null, 'reason' => 'position_not_found'];
        }

        $time = trim((string) ($intent['preferred_time'] ?? ''));
        $date = trim((string) ($intent['preferred_date'] ?? ''));
        $day = trim((string) ($intent['preferred_day'] ?? ''));
        $hasDayOrDate = $date !== '' || $day !== '';
        if ($time === '') {
            return ['signal' => false, 'slot' => null, 'reason' => 'no_selection_signal'];
        }

        $candidates = [];
        foreach ($slots as $slot) {
            $start = new DateTimeImmutable((string) $slot['starts_at']);
            $slotTime = $start->format('H:i');
            if ($slotTime !== $time) {
                continue;
            }
            if ($date !== '' && $start->format('Y-m-d') !== $date) {
                continue;
            }
            if ($day !== '' && $this->weekdayLabel((int) $start->format('w')) !== $day) {
                continue;
            }
            $candidates[] = $slot;
        }

        if (count($candidates) === 1) {
            return ['signal' => true, 'slot' => $candidates[0], 'reason' => 'time'];
        }
        if (count($candidates) > 1) {
            return ['signal' => true, 'slot' => null, 'reason' => 'ambiguous_time'];
        }

        if ($hasDayOrDate) {
            return ['signal' => true, 'slot' => null, 'reason' => 'new_preference', 'new_preference' => true];
        }
        return ['signal' => true, 'slot' => null, 'reason' => 'time_not_found'];
    }

    /** @param array<int,array<string,mixed>> $slots */
    private function optionsMessage(array $settings, array $appointment, array $slots): string
    {
        $template = trim((string) ($settings['availability_options_message'] ?? ''))
            ?: "O horário solicitado não está disponível. Encontrei estas opções:\n\n{{opcoes}}\n\nResponda com o número ou com o horário que prefere.";
        return trim(strtr($template, [
            '{{opcoes}}' => $this->formatOptions($slots),
            '{{dia_preferido}}' => (string) ($appointment['preferred_day_text'] ?? ''),
            '{{horario_preferido}}' => (string) ($appointment['preferred_time_text'] ?? ''),
            '{{nome}}' => (string) ($appointment['contact_name'] ?? ''),
        ]));
    }

    /** @param array<int,array<string,mixed>> $slots */
    private function formatOptions(array $slots): string
    {
        $lines = [];
        foreach ($slots as $index => $slot) {
            $position = (int) ($slot['suggestion_position'] ?? ($index + 1));
            $lines[] = $position . '. ' . $this->slotHumanLabel($slot);
        }
        return implode("\n", $lines);
    }

    private function selectedMessage(array $settings, array $appointment, array $slot): string
    {
        $template = trim((string) ($settings['slot_selected_message'] ?? ''))
            ?: 'Perfeito. Pré-reservei {{data}} às {{hora}}. O horário está aguardando validação da profissional. Você receberá a confirmação por aqui.';
        $start = new DateTimeImmutable((string) $slot['starts_at']);
        return trim(strtr($template, [
            '{{nome}}' => (string) ($appointment['contact_name'] ?? ''),
            '{{data}}' => $this->humanDate($start),
            '{{hora}}' => $start->format('H:i'),
            '{{inicio}}' => $this->humanDate($start) . ' às ' . $start->format('H:i'),
            '{{modalidade}}' => ucfirst((string) ($slot['modality'] ?? $appointment['appointment_modality'] ?? '')),
            '{{local}}' => '',
        ]));
    }

    private function slotHumanLabel(array $slot): string
    {
        $start = new DateTimeImmutable((string) $slot['starts_at']);
        $modality = trim((string) ($slot['modality'] ?? ''));
        $suffix = in_array($modality, ['online', 'presencial'], true) ? ' · ' . ucfirst($modality) : '';
        return $this->humanDate($start) . ' às ' . $start->format('H:i') . $suffix;
    }

    private function humanDate(DateTimeImmutable $date): string
    {
        return $this->weekdayLabel((int) $date->format('w')) . ', ' . $date->format('d/m/Y');
    }

    private function weekdayLabel(int $weekday): string
    {
        return [
            0 => 'domingo',
            1 => 'segunda-feira',
            2 => 'terça-feira',
            3 => 'quarta-feira',
            4 => 'quinta-feira',
            5 => 'sexta-feira',
            6 => 'sábado',
        ][$weekday] ?? '';
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));
        return strtr($value, [
            'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a',
            'é' => 'e', 'ê' => 'e', 'í' => 'i',
            'ó' => 'o', 'ô' => 'o', 'õ' => 'o',
            'ú' => 'u', 'ç' => 'c',
        ]);
    }

    private function finalizeSelection(int $tenantId, int $appointmentId, array $slot, string $selectedBy): void
    {
        $start = new DateTimeImmutable((string) $slot['starts_at']);
        $params = [
            'tenant_id' => $tenantId,
            'appointment_id' => $appointmentId,
            'preferred_day_text' => $start->format('d/m/Y'),
            'preferred_time_text' => $start->format('H:i'),
            'selected_by' => $selectedBy,
        ];
        try {
            Database::connection()->prepare(
                'UPDATE calendar_appointments
                 SET status = "awaiting_approval",
                     approval_status = "pending",
                     preferred_day_text = :preferred_day_text,
                     preferred_time_text = :preferred_time_text,
                     availability_selected_at = NOW(),
                     availability_selected_by = :selected_by,
                     availability_selection_expires_at = NULL,
                     availability_error = NULL,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :appointment_id AND tenant_id = :tenant_id'
            )->execute($params);
        } catch (Throwable) {
            Database::connection()->prepare(
                'UPDATE calendar_appointments
                 SET status = "awaiting_approval",
                     approval_status = "pending",
                     preferred_day_text = :preferred_day_text,
                     preferred_time_text = :preferred_time_text,
                     availability_error = NULL,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :appointment_id AND tenant_id = :tenant_id'
            )->execute([
                'tenant_id' => $tenantId,
                'appointment_id' => $appointmentId,
                'preferred_day_text' => $start->format('d/m/Y'),
                'preferred_time_text' => $start->format('H:i'),
            ]);
        }
        Audit::log('calendar.availability_selected_by_' . $selectedBy, [
            'appointment_id' => $appointmentId,
            'slot_id' => (int) ($slot['id'] ?? 0),
        ], $tenantId);
    }

    private function markOptionsCommunication(
        int $tenantId,
        int $appointmentId,
        int $requestId,
        ?string $externalId,
        bool $selected,
        string $status,
        bool $sent
    ): void {
        $expiresAt = $selected ? null : date('Y-m-d H:i:s', time() + 24 * 3600);
        try {
            Database::connection()->prepare(
                'UPDATE calendar_appointments
                 SET availability_status = :status,
                     status = :appointment_status,
                     availability_options_request_id = :request_id,
                     availability_options_sent_at = :sent_at,
                     availability_options_message_id = :message_id,
                     availability_selection_expires_at = :expires_at,
                     availability_error = NULL,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :appointment_id AND tenant_id = :tenant_id'
            )->execute([
                'status' => $status,
                'appointment_status' => $selected ? 'awaiting_approval' : 'pre_scheduled',
                'request_id' => (!$selected && !$sent) ? null : $requestId,
                'sent_at' => $sent ? date('Y-m-d H:i:s') : null,
                'message_id' => $externalId,
                'expires_at' => $expiresAt,
                'appointment_id' => $appointmentId,
                'tenant_id' => $tenantId,
            ]);
        } catch (Throwable) {
            Database::connection()->prepare(
                'UPDATE calendar_appointments
                 SET availability_status = :status,
                     status = :appointment_status,
                     availability_error = NULL,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :appointment_id AND tenant_id = :tenant_id'
            )->execute([
                'status' => $status,
                'appointment_status' => $selected ? 'awaiting_approval' : 'pre_scheduled',
                'appointment_id' => $appointmentId,
                'tenant_id' => $tenantId,
            ]);
        }
    }

    private function claimAvailabilityCommunication(int $tenantId, int $appointmentId, int $requestId): bool
    {
        try {
            $statement = Database::connection()->prepare(
                'UPDATE calendar_appointments
                 SET availability_options_request_id = :request_id_set,
                     availability_status = "communicating",
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :appointment_id
                   AND tenant_id = :tenant_id
                   AND (
                        availability_options_request_id IS NULL
                        OR availability_options_request_id <> :request_id_compare
                        OR (availability_options_request_id = :request_id_retry
                            AND availability_status = "communicating"
                            AND updated_at < DATE_SUB(NOW(), INTERVAL 2 MINUTE))
                   )'
            );
            $statement->execute([
                'request_id_set' => $requestId,
                'appointment_id' => $appointmentId,
                'tenant_id' => $tenantId,
                'request_id_compare' => $requestId,
                'request_id_retry' => $requestId,
            ]);
            return $statement->rowCount() > 0;
        } catch (Throwable) {
            // Antes da migration 046, mantém a proteção existente por evento de conversa.
            return true;
        }
    }

    private function requestAlreadyCommunicated(array $appointment, int $requestId): bool
    {
        if ((int) ($appointment['availability_options_request_id'] ?? 0) === $requestId
            && !empty($appointment['availability_options_sent_at'])) {
            return true;
        }
        try {
            $statement = Database::connection()->prepare(
                'SELECT 1
                 FROM conversation_events
                 WHERE tenant_id = :tenant_id
                   AND conversation_id = :conversation_id
                   AND event_type IN ("calendar.availability_options_sent", "calendar.slot_auto_selected", "calendar.no_availability_sent", "calendar.no_availability_after_hold_failure")
                   AND metadata_json LIKE :request_pattern
                 LIMIT 1'
            );
            $statement->execute([
                'tenant_id' => (int) ($appointment['tenant_id'] ?? 0),
                'conversation_id' => (int) ($appointment['conversation_id'] ?? 0),
                'request_pattern' => '%"request_id":' . $requestId . '%',
            ]);
            return (bool) $statement->fetchColumn();
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array{ok:bool,error:?string,external_id:?string} */
    private function sendAppointmentMessage(array $appointment, string $message, string $eventType, array $metadata = []): array
    {
        $message = trim($message);
        $tenantId = (int) ($appointment['tenant_id'] ?? 0);
        $conversationId = (int) ($appointment['conversation_id'] ?? 0);
        $phoneSource = trim((string) (($appointment['phone'] ?? '') ?: ($appointment['remote_jid'] ?? '')));
        $phone = preg_replace('/\D+/', '', $phoneSource) ?: '';
        if ($tenantId < 1 || $conversationId < 1 || $phone === '' || $message === '') {
            return ['ok' => false, 'error' => 'Contato, conversa ou mensagem inválida.', 'external_id' => null];
        }

        if ($this->recentOutgoingSameMessage($conversationId, $message)) {
            return ['ok' => true, 'error' => null, 'external_id' => null];
        }

        try {
            $instance = $this->instanceForMessaging($appointment, $tenantId);
            if (!$instance) {
                throw new \RuntimeException('Nenhuma instância Evolution disponível para enviar a mensagem de agenda.');
            }

            $verifySsl = filter_var(Env::get('EVOLUTION_SSL_VERIFY', true), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
            $caBundle = trim((string) Env::get('EVOLUTION_CA_BUNDLE', ''));
            $service = new EvolutionService(
                (string) $instance['base_url'],
                Crypto::decrypt((string) $instance['api_key_encrypted']),
                (string) $instance['instance_name'],
                24,
                $verifySsl ?? true,
                $caBundle !== '' ? $caBundle : null
            );
            $response = $service->sendText($phone, $message);
            $externalId = $this->extractMessageId(is_array($response['body'] ?? null) ? $response['body'] : []);
            $sentAt = date('Y-m-d H:i:s');
            $pdo = Database::connection();
            $pdo->prepare(
                'INSERT INTO conversation_messages
                    (tenant_id, conversation_id, evolution_message_id, direction, sender_type,
                     message_type, content, status, raw_payload_json, sent_at)
                 VALUES
                    (:tenant_id, :conversation_id, :external_id, "outgoing", "system",
                     "text", :content, "sent", :raw_payload, :sent_at)'
            )->execute([
                'tenant_id' => $tenantId,
                'conversation_id' => $conversationId,
                'external_id' => $externalId,
                'content' => $message,
                'raw_payload' => json_encode($response['body'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'sent_at' => $sentAt,
            ]);
            $pdo->prepare(
                'UPDATE conversations
                 SET last_message_at = :sent_at,
                     last_message_preview = :preview,
                     status = IF(status = "closed", "open", status),
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id AND tenant_id = :tenant_id'
            )->execute([
                'sent_at' => $sentAt,
                'preview' => mb_substr($message, 0, 255),
                'id' => $conversationId,
                'tenant_id' => $tenantId,
            ]);
            $pdo->prepare(
                'INSERT INTO conversation_events
                    (tenant_id, conversation_id, event_type, description, metadata_json)
                 VALUES
                    (:tenant_id, :conversation_id, :event_type, :description, :metadata_json)'
            )->execute([
                'tenant_id' => $tenantId,
                'conversation_id' => $conversationId,
                'event_type' => mb_substr($eventType, 0, 100),
                'description' => mb_substr($message, 0, 500),
                'metadata_json' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
            return ['ok' => true, 'error' => null, 'external_id' => $externalId];
        } catch (Throwable $exception) {
            try {
                Database::connection()->prepare(
                    'UPDATE calendar_appointments
                     SET availability_error = :error, updated_at = CURRENT_TIMESTAMP
                     WHERE id = :id AND tenant_id = :tenant_id'
                )->execute([
                    'error' => mb_substr('Mensagem da agenda não enviada: ' . $exception->getMessage(), 0, 700),
                    'id' => (int) ($appointment['id'] ?? 0),
                    'tenant_id' => $tenantId,
                ]);
            } catch (Throwable) {
            }
            return ['ok' => false, 'error' => $exception->getMessage(), 'external_id' => null];
        }
    }

    /** @return array<string,mixed>|null */
    private function instanceForMessaging(array $appointment, int $tenantId): ?array
    {
        $instanceId = (int) (($appointment['conversation_instance_id'] ?? 0) ?: ($appointment['contact_instance_id'] ?? 0));
        if ($instanceId > 0) {
            $statement = Database::connection()->prepare(
                'SELECT * FROM evolution_instances WHERE id = :id AND tenant_id = :tenant_id LIMIT 1'
            );
            $statement->execute(['id' => $instanceId, 'tenant_id' => $tenantId]);
            $instance = $statement->fetch(PDO::FETCH_ASSOC);
            if ($instance) {
                return $instance;
            }
        }
        $statement = Database::connection()->prepare(
            'SELECT * FROM evolution_instances
             WHERE tenant_id = :tenant_id
             ORDER BY (status = "connected") DESC, is_default DESC, id DESC
             LIMIT 1'
        );
        $statement->execute(['tenant_id' => $tenantId]);
        $instance = $statement->fetch(PDO::FETCH_ASSOC);
        return $instance ?: null;
    }

    private function recentOutgoingSameMessage(int $conversationId, string $message): bool
    {
        try {
            $statement = Database::connection()->prepare(
                'SELECT 1 FROM conversation_messages
                 WHERE conversation_id = :conversation_id
                   AND direction = "outgoing"
                   AND content = :content
                   AND sent_at >= DATE_SUB(NOW(), INTERVAL 2 MINUTE)
                 LIMIT 1'
            );
            $statement->execute(['conversation_id' => $conversationId, 'content' => $message]);
            return (bool) $statement->fetchColumn();
        } catch (Throwable) {
            return false;
        }
    }

    private function extractMessageId(array $body): ?string
    {
        $id = $body['key']['id'] ?? $body['messageId'] ?? $body['id'] ?? $body['data']['key']['id'] ?? null;
        return is_scalar($id) && trim((string) $id) !== '' ? trim((string) $id) : null;
    }

    private function notifyProfessional(int $tenantId, array $appointment, array $slot, string $title): void
    {
        $name = trim((string) ($appointment['contact_name'] ?? 'Cliente')) ?: 'Cliente';
        (new NotificationService())->createIfEnabled(
            $tenantId,
            'calendar',
            $title,
            $name . ' escolheu ' . $this->slotHumanLabel($slot) . '. O pré-agendamento aguarda aprovação.',
            'info',
            '/calendar?section=availability&tenant_id=' . $tenantId,
            'calendar',
            'calendar.awaiting_professional_approval',
            'appointment',
            (int) ($appointment['id'] ?? 0),
            [
                'conversation_id' => (int) ($appointment['conversation_id'] ?? 0),
                'slot_id' => (int) ($slot['id'] ?? 0),
            ]
        );
    }

    private function notifyFailure(int $tenantId, array $appointment, string $error): void
    {
        (new NotificationService())->createIfEnabled(
            $tenantId,
            'calendar',
            'Falha ao pré-reservar horário',
            mb_substr($error, 0, 500),
            'warning',
            '/calendar?section=availability&tenant_id=' . $tenantId,
            'calendar',
            'calendar.slot_hold_failed',
            'appointment',
            (int) ($appointment['id'] ?? 0),
            ['conversation_id' => (int) ($appointment['conversation_id'] ?? 0)],
            300
        );
    }
}
