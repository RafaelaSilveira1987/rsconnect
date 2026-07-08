<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Env;
use PDO;
use Throwable;

final class EvolutionWebhookController
{
    public function handle(): void
    {
        header('Content-Type: application/json; charset=UTF-8');

        try {
            $this->validateToken();
            $raw = file_get_contents('php://input') ?: '';
            $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($payload)) {
                throw new \RuntimeException('Payload inválido.');
            }

            $event = $this->normalizeEvent((string) ($payload['event'] ?? ''));
            $instance = $this->resolveInstance($payload);

            if (str_contains($event, 'messages.update')) {
                $updated = $this->applyStatusUpdate($instance, $payload);
                $this->respond(200, ['ok' => true, 'updated' => $updated]);
            }

            if ($event !== '' && !str_contains($event, 'messages.upsert') && !str_contains($event, 'send.message')) {
                $this->respond(202, ['ok' => true, 'ignored' => $event]);
            }

            $data = $payload['data'] ?? $payload;
            if (isset($data[0]) && is_array($data[0])) {
                $data = $data[0];
            }
            if (!is_array($data)) {
                throw new \RuntimeException('Dados da mensagem não encontrados.');
            }

            $key = is_array($data['key'] ?? null) ? $data['key'] : [];
            $remoteJid = trim((string) ($key['remoteJid'] ?? $data['remoteJid'] ?? ''));
            if ($remoteJid === '') {
                throw new \RuntimeException('remoteJid não informado.');
            }

            if (str_contains($remoteJid, '@g.us')) {
                $this->respond(202, ['ok' => true, 'ignored' => 'group']);
            }

            $fromMe = filter_var($key['fromMe'] ?? $data['fromMe'] ?? false, FILTER_VALIDATE_BOOL);
            $externalId = trim((string) ($key['id'] ?? $data['id'] ?? '')) ?: null;
            $pushName = trim((string) ($data['pushName'] ?? $data['senderName'] ?? ''));
            $phone = preg_replace('/\D+/', '', strstr($remoteJid, '@', true) ?: $remoteJid) ?: '';
            if ($phone === '') {
                throw new \RuntimeException('Não foi possível identificar o telefone do contato.');
            }

            [$messageType, $content] = $this->extractContent($data);
            $sentAt = $this->extractDate($data);
            $direction = $fromMe ? 'outgoing' : 'incoming';
            $senderType = $fromMe ? 'system' : 'contact';
            $status = $fromMe ? 'sent' : 'received';

            $pdo = Database::connection();
            if ($externalId !== null) {
                $duplicate = $pdo->prepare(
                    'SELECT conversation_id FROM conversation_messages
                     WHERE tenant_id = :tenant_id AND evolution_message_id = :external_id
                     LIMIT 1'
                );
                $duplicate->execute([
                    'tenant_id' => $instance['tenant_id'],
                    'external_id' => $externalId,
                ]);
                $existingConversationId = $duplicate->fetchColumn();
                if ($existingConversationId !== false) {
                    $this->respond(200, [
                        'ok' => true,
                        'duplicate' => true,
                        'conversation_id' => (int) $existingConversationId,
                    ]);
                }
            }

            $pdo->beginTransaction();

            $contactId = $this->upsertContact($pdo, $instance, $remoteJid, $phone, $pushName);
            $conversationId = $this->upsertConversation(
                $pdo,
                $instance,
                $contactId,
                $remoteJid,
                $content,
                $sentAt,
                !$fromMe
            );

            $inserted = $this->insertMessage(
                $pdo,
                (int) $instance['tenant_id'],
                $conversationId,
                $externalId,
                $direction,
                $senderType,
                $messageType,
                $content,
                $status,
                $payload,
                $sentAt
            );

            $pdo->commit();
            $this->respond(200, [
                'ok' => true,
                'conversation_id' => $conversationId,
                'message_inserted' => $inserted,
            ]);
        } catch (Throwable $exception) {
            if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $status = $exception->getCode() >= 400 && $exception->getCode() <= 599
                ? $exception->getCode()
                : 422;
            $this->respond($status, ['ok' => false, 'error' => $exception->getMessage()]);
        }
    }

    private function normalizeEvent(string $event): string
    {
        $event = mb_strtolower(trim($event));
        $event = str_replace(['_', '-'], '.', $event);
        while (str_contains($event, '..')) {
            $event = str_replace('..', '.', $event);
        }
        return $event;
    }

    private function validateToken(): void
    {
        $expected = trim((string) Env::get('EVOLUTION_WEBHOOK_TOKEN', ''));
        if ($expected === '') {
            return;
        }

        $authorization = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
        $bearer = str_starts_with($authorization, 'Bearer ') ? substr($authorization, 7) : '';
        $received = (string) (
            $_GET['token']
            ?? $_SERVER['HTTP_X_WEBHOOK_TOKEN']
            ?? $bearer
        );

        if ($received === '' || !hash_equals($expected, $received)) {
            throw new \RuntimeException('Webhook não autorizado.', 401);
        }
    }

    private function resolveInstance(array $payload): array
    {
        $pdo = Database::connection();
        $instanceId = (int) ($_GET['instance_id'] ?? 0);

        if ($instanceId > 0) {
            $statement = $pdo->prepare('SELECT * FROM evolution_instances WHERE id = :id LIMIT 1');
            $statement->execute(['id' => $instanceId]);
            $instance = $statement->fetch(PDO::FETCH_ASSOC);
            if (!$instance) {
                throw new \RuntimeException('Instância não encontrada.', 404);
            }
            return $instance;
        }

        $instanceName = trim((string) (
            $payload['instance']
            ?? $payload['data']['instance']
            ?? $payload['instanceName']
            ?? ''
        ));
        if ($instanceName === '') {
            throw new \RuntimeException('Informe instance_id na URL do webhook ou envie o nome da instância no payload.');
        }

        $statement = $pdo->prepare('SELECT * FROM evolution_instances WHERE instance_name = :name LIMIT 2');
        $statement->execute(['name' => $instanceName]);
        $matches = $statement->fetchAll(PDO::FETCH_ASSOC);
        if (count($matches) !== 1) {
            throw new \RuntimeException('A instância não foi encontrada de forma única. Use instance_id na URL do webhook.');
        }
        return $matches[0];
    }

    private function upsertContact(PDO $pdo, array $instance, string $remoteJid, string $phone, string $pushName): int
    {
        $statement = $pdo->prepare(
            'INSERT INTO contacts
                (tenant_id, evolution_instance_id, remote_jid, phone, name)
             VALUES
                (:tenant_id, :instance_id, :remote_jid, :phone, :name)
             ON DUPLICATE KEY UPDATE
                id = LAST_INSERT_ID(id),
                evolution_instance_id = VALUES(evolution_instance_id),
                remote_jid = VALUES(remote_jid),
                name = IF(VALUES(name) IS NULL OR VALUES(name) = "", name, VALUES(name))'
        );
        $statement->execute([
            'tenant_id' => $instance['tenant_id'],
            'instance_id' => $instance['id'],
            'remote_jid' => $remoteJid,
            'phone' => $phone,
            'name' => $pushName !== '' ? $pushName : null,
        ]);
        return (int) $pdo->lastInsertId();
    }

    private function upsertConversation(
        PDO $pdo,
        array $instance,
        int $contactId,
        string $remoteJid,
        string $content,
        string $sentAt,
        bool $incrementUnread
    ): int {
        $statement = $pdo->prepare(
            'INSERT INTO conversations
                (tenant_id, evolution_instance_id, contact_id, remote_jid, status,
                 attendance_mode, unread_count, last_message_at, last_message_preview)
             VALUES
                (:tenant_id, :instance_id, :contact_id, :remote_jid, "open",
                 "ai", :unread_count, :last_message_at, :preview)
             ON DUPLICATE KEY UPDATE
                id = LAST_INSERT_ID(id),
                contact_id = VALUES(contact_id),
                last_message_at = VALUES(last_message_at),
                last_message_preview = VALUES(last_message_preview),
                unread_count = unread_count + VALUES(unread_count),
                status = IF(status = "closed", "open", status)'
        );
        $statement->execute([
            'tenant_id' => $instance['tenant_id'],
            'instance_id' => $instance['id'],
            'contact_id' => $contactId,
            'remote_jid' => $remoteJid,
            'unread_count' => $incrementUnread ? 1 : 0,
            'last_message_at' => $sentAt,
            'preview' => mb_substr($content, 0, 255),
        ]);
        return (int) $pdo->lastInsertId();
    }

    private function insertMessage(
        PDO $pdo,
        int $tenantId,
        int $conversationId,
        ?string $externalId,
        string $direction,
        string $senderType,
        string $messageType,
        string $content,
        string $status,
        array $payload,
        string $sentAt
    ): bool {
        if ($externalId !== null) {
            $exists = $pdo->prepare(
                'SELECT id FROM conversation_messages
                 WHERE tenant_id = :tenant_id AND evolution_message_id = :external_id
                 LIMIT 1'
            );
            $exists->execute(['tenant_id' => $tenantId, 'external_id' => $externalId]);
            if ($exists->fetchColumn()) {
                return false;
            }
        }

        $statement = $pdo->prepare(
            'INSERT INTO conversation_messages
                (tenant_id, conversation_id, evolution_message_id, direction, sender_type,
                 message_type, content, status, raw_payload_json, sent_at)
             VALUES
                (:tenant_id, :conversation_id, :external_id, :direction, :sender_type,
                 :message_type, :content, :status, :raw_payload, :sent_at)'
        );
        $statement->execute([
            'tenant_id' => $tenantId,
            'conversation_id' => $conversationId,
            'external_id' => $externalId,
            'direction' => $direction,
            'sender_type' => $senderType,
            'message_type' => $messageType,
            'content' => $content,
            'status' => $status,
            'raw_payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'sent_at' => $sentAt,
        ]);
        return true;
    }

    private function applyStatusUpdate(array $instance, array $payload): bool
    {
        $data = $payload['data'] ?? [];
        if (isset($data[0]) && is_array($data[0])) {
            $data = $data[0];
        }
        if (!is_array($data)) {
            return false;
        }

        $externalId = trim((string) ($data['key']['id'] ?? $data['id'] ?? ''));
        if ($externalId === '') {
            return false;
        }

        $rawStatus = mb_strtolower((string) ($data['status'] ?? $data['update']['status'] ?? ''));
        $status = match (true) {
            str_contains($rawStatus, 'read'), str_contains($rawStatus, 'played') => 'read',
            str_contains($rawStatus, 'delivery'), str_contains($rawStatus, 'delivered') => 'delivered',
            str_contains($rawStatus, 'error'), str_contains($rawStatus, 'failed') => 'failed',
            default => 'sent',
        };

        $statement = Database::connection()->prepare(
            'UPDATE conversation_messages
             SET status = :status
             WHERE tenant_id = :tenant_id AND evolution_message_id = :external_id'
        );
        $statement->execute([
            'status' => $status,
            'tenant_id' => $instance['tenant_id'],
            'external_id' => $externalId,
        ]);
        return $statement->rowCount() > 0;
    }

    private function extractContent(array $data): array
    {
        $message = is_array($data['message'] ?? null) ? $data['message'] : [];
        $type = (string) ($data['messageType'] ?? '');

        $candidates = [
            'conversation' => $message['conversation'] ?? null,
            'extendedText' => $message['extendedTextMessage']['text'] ?? null,
            'image' => $message['imageMessage']['caption'] ?? null,
            'video' => $message['videoMessage']['caption'] ?? null,
            'document' => $message['documentMessage']['fileName'] ?? null,
            'buttons' => $message['buttonsResponseMessage']['selectedDisplayText'] ?? null,
            'list' => $message['listResponseMessage']['title'] ?? null,
        ];

        foreach ($candidates as $detectedType => $value) {
            if (is_scalar($value) && trim((string) $value) !== '') {
                return [$detectedType === 'conversation' || $detectedType === 'extendedText' ? 'text' : $detectedType, trim((string) $value)];
            }
        }

        $fallback = match (true) {
            str_contains(mb_strtolower($type), 'image') => ['image', '[Imagem]'],
            str_contains(mb_strtolower($type), 'audio') => ['audio', '[Áudio]'],
            str_contains(mb_strtolower($type), 'video') => ['video', '[Vídeo]'],
            str_contains(mb_strtolower($type), 'document') => ['document', '[Documento]'],
            str_contains(mb_strtolower($type), 'sticker') => ['sticker', '[Figurinha]'],
            default => ['unknown', '[Mensagem não textual]'],
        };
        return $fallback;
    }

    private function extractDate(array $data): string
    {
        $timestamp = $data['messageTimestamp'] ?? $data['timestamp'] ?? null;
        if (is_array($timestamp)) {
            $timestamp = $timestamp['low'] ?? null;
        }
        if (is_numeric($timestamp)) {
            $value = (int) $timestamp;
            if ($value > 20000000000) {
                $value = (int) floor($value / 1000);
            }
            return date('Y-m-d H:i:s', $value);
        }
        return date('Y-m-d H:i:s');
    }

    private function respond(int $status, array $body): never
    {
        http_response_code($status);
        echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
