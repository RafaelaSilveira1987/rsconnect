<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;

final class PreSchedulingService
{
    public function handleIncoming(PDO $pdo, array $instance, int $contactId, int $conversationId, string $content): void
    {
        $tenantId = (int) ($instance['tenant_id'] ?? 0);
        $content = trim($content);
        if ($tenantId < 1 || $content === '' || !$this->isEnabled($tenantId)) {
            return;
        }

        $intent = $this->detectIntent($content);
        if (!$intent['has_intent']) {
            return;
        }

        $this->markConversationIntent($pdo, $tenantId, $conversationId, $intent);

        $existing = $this->pendingPreSchedule($pdo, $tenantId, $conversationId);
        if ($existing !== null) {
            $this->updatePendingPreSchedule($pdo, $tenantId, $existing, $intent, $content);
            return;
        }

        if (!$this->hasColumn($pdo, 'calendar_appointments', 'is_pre_schedule')) {
            return;
        }

        $settings = $this->settings($tenantId);
        $contact = $this->findContact($pdo, $tenantId, $contactId);
        $period = $this->periodFromIntent($intent, (int) ($settings['default_duration_minutes'] ?? 50));
        $titleName = trim((string) ($contact['name'] ?? '')) ?: trim((string) ($contact['phone'] ?? 'Paciente'));
        $title = 'Pré-agendamento - ' . mb_substr($titleName, 0, 90);
        $description = $this->buildDescription($content, $intent);

        $statement = $pdo->prepare(
            'INSERT INTO calendar_appointments
                (tenant_id, contact_id, conversation_id, title, description, starts_at, ends_at, timezone, status,
                 location_type, location, reminder_minutes, sync_status, is_pre_schedule, pre_schedule_source,
                 preferred_day_text, preferred_time_text, approval_status, approval_notes)
             VALUES
                (:tenant_id, :contact_id, :conversation_id, :title, :description, :starts_at, :ends_at, :timezone, "pre_scheduled",
                 :location_type, :location, 60, "pending", 1, "ai_whatsapp",
                 :preferred_day_text, :preferred_time_text, "pending", :approval_notes)'
        );
        $statement->execute([
            'tenant_id' => $tenantId,
            'contact_id' => $contactId,
            'conversation_id' => $conversationId,
            'title' => $title,
            'description' => $description,
            'starts_at' => $period['starts_at'],
            'ends_at' => $period['ends_at'],
            'timezone' => 'America/Sao_Paulo',
            'location_type' => $intent['location_type'],
            'location' => $intent['modality'] !== '' ? $intent['modality'] : null,
            'preferred_day_text' => $this->displayDay($intent),
            'preferred_time_text' => $this->displayTime($intent),
            'approval_notes' => 'Criado automaticamente a partir da intenção de agenda detectada na conversa #' . $conversationId,
        ]);
        $appointmentId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO conversation_events (tenant_id, conversation_id, event_type, description, metadata_json)
             VALUES (:tenant_id, :conversation_id, "calendar.pre_scheduled", :description, :metadata_json)'
        )->execute([
            'tenant_id' => $tenantId,
            'conversation_id' => $conversationId,
            'description' => 'Pré-agendamento criado para aprovação humana.',
            'metadata_json' => json_encode(['appointment_id' => $appointmentId, 'intent' => $intent], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        (new AutomationWebhookService())->dispatch('appointment.pre_scheduled', [
            'tenant_id' => $tenantId,
            'conversation_id' => $conversationId,
            'contact' => $contact,
            'appointment_id' => $appointmentId,
            'preferred_day' => $this->displayDay($intent),
            'preferred_time' => $this->displayTime($intent),
            'preferred_date' => $intent['preferred_date'],
            'modality' => $intent['modality'],
            'message' => $content,
        ], null, $tenantId);
    }

    public function isEnabled(int $tenantId): bool
    {
        if (!$this->tableExists('tenant_pre_schedule_settings')) {
            return false;
        }
        try {
            $statement = Database::connection()->prepare(
                'SELECT enabled FROM tenant_pre_schedule_settings WHERE tenant_id = :tenant_id LIMIT 1'
            );
            $statement->execute(['tenant_id' => $tenantId]);
            $value = $statement->fetchColumn();
            return (int) $value === 1;
        } catch (Throwable) {
            return false;
        }
    }

    public function settings(int $tenantId): array
    {
        $defaults = [
            'enabled' => 0,
            'require_human_approval' => 1,
            'ai_can_suggest_slots' => 1,
            'ai_can_confirm' => 0,
            'send_approval_message' => 1,
            'default_duration_minutes' => 50,
            'default_message' => 'Vou registrar sua preferência e encaminhar para confirmação da profissional.',
            'collect_message' => 'Certo. Me informe, por favor, o melhor dia e período ou horário para atendimento.',
            'approved_message' => 'Seu agendamento foi confirmado para {{data}} às {{hora}}. {{local}}',
            'rejected_message' => 'No momento não conseguimos confirmar esse horário. Pode me enviar outra opção de dia ou período?',
            'reschedule_message' => 'Precisamos ajustar sua preferência de horário. Pode me enviar outra opção de dia ou período?',
        ];
        if ($tenantId < 1 || !$this->tableExists('tenant_pre_schedule_settings')) {
            return $defaults;
        }
        try {
            $statement = Database::connection()->prepare('SELECT * FROM tenant_pre_schedule_settings WHERE tenant_id = :tenant_id LIMIT 1');
            $statement->execute(['tenant_id' => $tenantId]);
            $row = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
            return array_merge($defaults, $row);
        } catch (Throwable) {
            return $defaults;
        }
    }

    public function saveSettings(int $tenantId, array $data): void
    {
        if ($tenantId < 1 || !$this->tableExists('tenant_pre_schedule_settings')) {
            return;
        }
        $duration = (int) ($data['default_duration_minutes'] ?? 50);
        $duration = max(15, min(240, $duration));

        $messages = [
            'default_message' => 'Vou registrar sua preferência e encaminhar para confirmação da profissional.',
            'collect_message' => 'Certo. Me informe, por favor, o melhor dia e período ou horário para atendimento.',
            'approved_message' => 'Seu agendamento foi confirmado para {{data}} às {{hora}}. {{local}}',
            'rejected_message' => 'No momento não conseguimos confirmar esse horário. Pode me enviar outra opção de dia ou período?',
            'reschedule_message' => 'Precisamos ajustar sua preferência de horário. Pode me enviar outra opção de dia ou período?',
        ];
        foreach ($messages as $field => $fallback) {
            $messages[$field] = trim((string) ($data[$field] ?? '')) ?: $fallback;
        }

        $statement = Database::connection()->prepare(
            'INSERT INTO tenant_pre_schedule_settings
                (tenant_id, enabled, require_human_approval, ai_can_suggest_slots, ai_can_confirm, send_approval_message,
                 default_duration_minutes, default_message, collect_message, approved_message, rejected_message, reschedule_message)
             VALUES
                (:tenant_id, :enabled, :require_human_approval, :ai_can_suggest_slots, :ai_can_confirm, :send_approval_message,
                 :default_duration_minutes, :default_message, :collect_message, :approved_message, :rejected_message, :reschedule_message)
             ON DUPLICATE KEY UPDATE
                enabled = VALUES(enabled),
                require_human_approval = VALUES(require_human_approval),
                ai_can_suggest_slots = VALUES(ai_can_suggest_slots),
                ai_can_confirm = VALUES(ai_can_confirm),
                send_approval_message = VALUES(send_approval_message),
                default_duration_minutes = VALUES(default_duration_minutes),
                default_message = VALUES(default_message),
                collect_message = VALUES(collect_message),
                approved_message = VALUES(approved_message),
                rejected_message = VALUES(rejected_message),
                reschedule_message = VALUES(reschedule_message),
                updated_at = CURRENT_TIMESTAMP'
        );
        $statement->execute([
            'tenant_id' => $tenantId,
            'enabled' => !empty($data['enabled']) ? 1 : 0,
            'require_human_approval' => !empty($data['require_human_approval']) ? 1 : 0,
            'ai_can_suggest_slots' => !empty($data['ai_can_suggest_slots']) ? 1 : 0,
            'ai_can_confirm' => !empty($data['ai_can_confirm']) ? 1 : 0,
            'send_approval_message' => !empty($data['send_approval_message']) ? 1 : 0,
            'default_duration_minutes' => $duration,
            'default_message' => $messages['default_message'],
            'collect_message' => $messages['collect_message'],
            'approved_message' => $messages['approved_message'],
            'rejected_message' => $messages['rejected_message'],
            'reschedule_message' => $messages['reschedule_message'],
        ]);
    }

    public function renderMessage(string $template, array $appointment): string
    {
        $date = !empty($appointment['starts_at']) ? date('d/m/Y', strtotime((string) $appointment['starts_at'])) : '';
        $hour = !empty($appointment['starts_at']) ? date('H:i', strtotime((string) $appointment['starts_at'])) : '';
        $location = trim((string) (($appointment['meeting_url'] ?? '') ?: ($appointment['location'] ?? '')));
        $locationText = $location !== '' ? 'Local/link: ' . $location : '';

        $replacements = [
            '{{nome}}' => (string) ($appointment['contact_name'] ?? $appointment['name'] ?? ''),
            '{{telefone}}' => (string) ($appointment['phone'] ?? ''),
            '{{titulo}}' => (string) ($appointment['title'] ?? ''),
            '{{data}}' => $date,
            '{{hora}}' => $hour,
            '{{inicio}}' => trim($date . ' ' . $hour),
            '{{local}}' => $locationText,
            '{{modalidade}}' => (string) ($appointment['location_type'] ?? ''),
            '{{dia_preferido}}' => (string) ($appointment['preferred_day_text'] ?? ''),
            '{{horario_preferido}}' => (string) ($appointment['preferred_time_text'] ?? ''),
        ];

        return trim(strtr($template, $replacements));
    }

    public function detectIntent(string $content): array
    {
        $text = $this->normalizeText($content);
        $preferredDate = $this->extractDateText($text);
        $preferredDay = $this->extractDayText($text);
        $preferredTime = $this->extractTimeText($text);
        $directAgenda = (bool) preg_match('/\b(agenda|agendar|marcar|marca|marcamos|horario|hora|disponibilidade|encaixe|retorno|consulta|sessao|atendimento)\b/u', $text);
        $serviceInterest = (bool) preg_match('/\b(consulta|atendimento|sessao|terapia|avaliacao|psicologa|psicologo)\b/u', $text);
        $hasPreference = $preferredDate !== '' || $preferredDay !== '' || $preferredTime !== '';
        $hasIntent = $directAgenda || ($serviceInterest && $hasPreference);
        $modality = $this->extractModality($text);

        return [
            'has_intent' => $hasIntent,
            'preferred_date' => $preferredDate,
            'preferred_day' => $preferredDay,
            'preferred_time' => $preferredTime,
            'modality' => $modality,
            'location_type' => $modality === 'Presencial' ? 'presencial' : ($modality === 'Telefone' ? 'telefone' : 'online'),
        ];
    }

    private function pendingPreSchedule(PDO $pdo, int $tenantId, int $conversationId): ?array
    {
        if (!$this->hasColumn($pdo, 'calendar_appointments', 'is_pre_schedule')) {
            return null;
        }
        $statement = $pdo->prepare(
            'SELECT * FROM calendar_appointments
             WHERE tenant_id = :tenant_id
               AND conversation_id = :conversation_id
               AND is_pre_schedule = 1
               AND status IN ("pre_scheduled", "awaiting_approval")
             ORDER BY id DESC
             LIMIT 1'
        );
        $statement->execute(['tenant_id' => $tenantId, 'conversation_id' => $conversationId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function updatePendingPreSchedule(PDO $pdo, int $tenantId, array $appointment, array $intent, string $content): void
    {
        $settings = $this->settings($tenantId);
        $period = $this->periodFromIntent($intent, (int) ($settings['default_duration_minutes'] ?? 50));
        $day = $this->displayDay($intent);
        $time = $this->displayTime($intent);
        $description = (string) ($appointment['description'] ?? '');
        $newLine = 'Nova informação do lead: ' . mb_substr($content, 0, 300);
        if (!str_contains($description, $newLine)) {
            $description = trim($description . "\n" . $newLine);
        }

        $params = [
            'id' => (int) $appointment['id'],
            'tenant_id' => $tenantId,
            'description' => mb_substr($description, 0, 2000),
            'starts_at' => $period['starts_at'],
            'ends_at' => $period['ends_at'],
            'preferred_day_text' => $day !== '' ? $day : ($appointment['preferred_day_text'] ?? null),
            'preferred_time_text' => $time !== '' ? $time : ($appointment['preferred_time_text'] ?? null),
            'location_type' => $intent['location_type'] ?: ($appointment['location_type'] ?? 'online'),
            'location' => $intent['modality'] !== '' ? $intent['modality'] : ($appointment['location'] ?? null),
        ];

        $pdo->prepare(
            'UPDATE calendar_appointments
             SET description = :description,
                 starts_at = :starts_at,
                 ends_at = :ends_at,
                 preferred_day_text = :preferred_day_text,
                 preferred_time_text = :preferred_time_text,
                 location_type = :location_type,
                 location = :location,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND tenant_id = :tenant_id'
        )->execute($params);
    }

    private function buildDescription(string $content, array $intent): string
    {
        return implode("\n", array_filter([
            'Preferência recebida pelo WhatsApp/IA. Necessita aprovação humana antes de confirmar.',
            'Dia/período informado: ' . ($this->displayDay($intent) ?: 'não informado'),
            'Horário/período informado: ' . ($this->displayTime($intent) ?: 'não informado'),
            'Modalidade: ' . ($intent['modality'] ?: 'não informada'),
            'Mensagem do lead: ' . mb_substr($content, 0, 500),
        ]));
    }

    private function normalizeText(string $content): string
    {
        $text = mb_strtolower($content);
        $map = ['á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ç' => 'c'];
        return strtr($text, $map);
    }

    private function extractDateText(string $text): string
    {
        if (preg_match('/\b(\d{1,2})[\/\-](\d{1,2})(?:[\/\-](\d{2,4}))?\b/u', $text, $match)) {
            $day = str_pad($match[1], 2, '0', STR_PAD_LEFT);
            $month = str_pad($match[2], 2, '0', STR_PAD_LEFT);
            $year = $match[3] ?? date('Y');
            if (strlen($year) === 2) {
                $year = '20' . $year;
            }
            return $year . '-' . $month . '-' . $day;
        }
        return '';
    }

    private function extractDayText(string $text): string
    {
        foreach (['segunda-feira' => 'segunda-feira', 'segunda feira' => 'segunda-feira', 'segunda' => 'segunda-feira', 'terca-feira' => 'terça-feira', 'terca feira' => 'terça-feira', 'terca' => 'terça-feira', 'quarta-feira' => 'quarta-feira', 'quarta feira' => 'quarta-feira', 'quarta' => 'quarta-feira', 'quinta-feira' => 'quinta-feira', 'quinta feira' => 'quinta-feira', 'quinta' => 'quinta-feira', 'sexta-feira' => 'sexta-feira', 'sexta feira' => 'sexta-feira', 'sexta' => 'sexta-feira', 'sabado' => 'sábado', 'domingo' => 'domingo'] as $needle => $label) {
            if (str_contains($text, $needle)) {
                return $label;
            }
        }
        if (str_contains($text, 'amanha')) {
            return 'amanhã';
        }
        if (str_contains($text, 'hoje')) {
            return 'hoje';
        }
        return '';
    }

    private function extractTimeText(string $text): string
    {
        // Remove datas como 13/07 para não confundir o dia com horário 13:00.
        $timeText = preg_replace('/\b\d{1,2}[\/\-]\d{1,2}(?:[\/\-]\d{2,4})?\b/u', ' ', $text) ?? $text;
        if (preg_match('/\b(?:as|às|a|ap[oó]s|depois das)?\s*([01]?\d|2[0-3])\s*(?:h|:)?\s*([0-5]\d)?\b/u', $timeText, $match)) {
            $hour = str_pad((string) (int) $match[1], 2, '0', STR_PAD_LEFT);
            $minute = isset($match[2]) && $match[2] !== '' ? $match[2] : '00';
            if ((int) $hour >= 6 && (int) $hour <= 23) {
                return $hour . ':' . $minute;
            }
        }
        if (preg_match('/\b(manha|tarde|noite)\b/u', $text, $match)) {
            return match ($match[1]) {
                'manha' => 'manhã',
                'tarde' => 'tarde',
                'noite' => 'noite',
                default => '',
            };
        }
        return '';
    }

    private function extractModality(string $text): string
    {
        if (str_contains($text, 'presencial') || str_contains($text, 'consultorio')) {
            return 'Presencial';
        }
        if (str_contains($text, 'telefone') || str_contains($text, 'ligacao') || str_contains($text, 'ligar')) {
            return 'Telefone';
        }
        if (str_contains($text, 'online') || str_contains($text, 'meet') || str_contains($text, 'video') || str_contains($text, 'remoto')) {
            return 'Online';
        }
        return '';
    }

    private function periodFromIntent(array $intent, int $durationMinutes = 50): array
    {
        $durationMinutes = max(15, min(240, $durationMinutes));
        $timezone = new DateTimeZone('America/Sao_Paulo');
        $now = new DateTimeImmutable('now', $timezone);
        $date = $this->dateFromIntent($intent, $now);
        $time = $this->timeFromText((string) $intent['preferred_time']);
        $start = new DateTimeImmutable($date->format('Y-m-d') . ' ' . $time, $timezone);
        if ($start <= $now) {
            $start = $start->add(new DateInterval('P7D'));
        }
        $end = $start->add(new DateInterval('PT' . $durationMinutes . 'M'));
        return ['starts_at' => $start->format('Y-m-d H:i:s'), 'ends_at' => $end->format('Y-m-d H:i:s')];
    }

    private function dateFromIntent(array $intent, DateTimeImmutable $now): DateTimeImmutable
    {
        $preferredDate = (string) ($intent['preferred_date'] ?? '');
        if ($preferredDate !== '') {
            try {
                return new DateTimeImmutable($preferredDate . ' 12:00:00', $now->getTimezone());
            } catch (Throwable) {
                // segue para dia textual
            }
        }
        return $this->dateFromDay((string) ($intent['preferred_day'] ?? ''), $now);
    }

    private function dateFromDay(string $day, DateTimeImmutable $now): DateTimeImmutable
    {
        if ($day === 'hoje') {
            return $now;
        }
        if ($day === 'amanhã') {
            return $now->add(new DateInterval('P1D'));
        }
        $map = ['domingo' => 0, 'segunda-feira' => 1, 'terça-feira' => 2, 'quarta-feira' => 3, 'quinta-feira' => 4, 'sexta-feira' => 5, 'sábado' => 6];
        if (isset($map[$day])) {
            $current = (int) $now->format('w');
            $target = $map[$day];
            $days = ($target - $current + 7) % 7;
            if ($days === 0) {
                $days = 7;
            }
            return $now->add(new DateInterval('P' . $days . 'D'));
        }
        return $now->add(new DateInterval('P1D'));
    }

    private function timeFromText(string $text): string
    {
        if (preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $text, $match)) {
            return str_pad((string) (int) $match[1], 2, '0', STR_PAD_LEFT) . ':' . $match[2] . ':00';
        }
        return match ($text) {
            'manhã' => '09:00:00',
            'tarde' => '14:00:00',
            'noite' => '19:00:00',
            default => '09:00:00',
        };
    }

    private function displayDay(array $intent): string
    {
        $date = (string) ($intent['preferred_date'] ?? '');
        if ($date !== '') {
            try {
                return (new DateTimeImmutable($date))->format('d/m/Y');
            } catch (Throwable) {
                return $date;
            }
        }
        return (string) ($intent['preferred_day'] ?? '');
    }

    private function displayTime(array $intent): string
    {
        return (string) ($intent['preferred_time'] ?? '');
    }

    private function markConversationIntent(PDO $pdo, int $tenantId, int $conversationId, array $intent): void
    {
        if (!$this->hasColumn($pdo, 'conversations', 'agenda_intent_detected')) {
            return;
        }
        $note = trim(implode(' | ', array_filter([
            'Dia: ' . ($this->displayDay($intent) ?: 'não informado'),
            'Horário: ' . ($this->displayTime($intent) ?: 'não informado'),
            'Modalidade: ' . ($intent['modality'] ?: 'não informada'),
        ])));
        $pdo->prepare(
            'UPDATE conversations
             SET agenda_intent_detected = 1,
                 agenda_intent_at = NOW(),
                 agenda_intent_note = :note
             WHERE id = :id AND tenant_id = :tenant_id'
        )->execute(['note' => mb_substr($note, 0, 500), 'id' => $conversationId, 'tenant_id' => $tenantId]);
    }

    private function findContact(PDO $pdo, int $tenantId, int $contactId): array
    {
        $statement = $pdo->prepare('SELECT id, name, phone, email FROM contacts WHERE id = :id AND tenant_id = :tenant_id LIMIT 1');
        $statement->execute(['id' => $contactId, 'tenant_id' => $tenantId]);
        return $statement->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    private function hasColumn(PDO $pdo, string $table, string $column): bool
    {
        try {
            $statement = $pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column'
            );
            $statement->execute(['table' => $table, 'column' => $column]);
            return (int) $statement->fetchColumn() > 0;
        } catch (Throwable) {
            return false;
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
