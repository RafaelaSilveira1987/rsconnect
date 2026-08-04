<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Env;
use PDO;
use RuntimeException;
use Throwable;

final class ConversationAttachmentService
{
    /** @var array<string,array{kind:string,extension:string}> */
    private const MIME_MAP = [
        'image/jpeg' => ['kind' => 'image', 'extension' => 'jpg'],
        'image/png' => ['kind' => 'image', 'extension' => 'png'],
        'image/webp' => ['kind' => 'image', 'extension' => 'webp'],
        'application/pdf' => ['kind' => 'document', 'extension' => 'pdf'],
        'audio/mpeg' => ['kind' => 'audio', 'extension' => 'mp3'],
        'audio/mp3' => ['kind' => 'audio', 'extension' => 'mp3'],
        'audio/ogg' => ['kind' => 'audio', 'extension' => 'ogg'],
        'audio/opus' => ['kind' => 'audio', 'extension' => 'opus'],
        'audio/mp4' => ['kind' => 'audio', 'extension' => 'm4a'],
        'audio/x-m4a' => ['kind' => 'audio', 'extension' => 'm4a'],
        'application/ogg' => ['kind' => 'audio', 'extension' => 'ogg'],
    ];

    public function enabled(): bool
    {
        return filter_var(Env::get('CONVERSATION_ATTACHMENTS_ENABLED', true), FILTER_VALIDATE_BOOL);
    }

    public function maxBytes(): int
    {
        $megabytes = max(1, min(100, (int) Env::get('CONVERSATION_ATTACHMENT_MAX_MB', 20)));
        return $megabytes * 1024 * 1024;
    }

    /**
     * @param array<string,mixed> $file
     * @return array<string,mixed>
     */
    public function storeUploadedFile(array $file, int $tenantId, int $conversationId, int $userId): array
    {
        if (!$this->enabled()) {
            throw new RuntimeException('O envio de anexos está desativado para esta instalação.');
        }

        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw new RuntimeException($this->uploadErrorMessage($error));
        }

        $tmpPath = (string) ($file['tmp_name'] ?? '');
        if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
            throw new RuntimeException('O arquivo enviado não pôde ser validado.');
        }

        $size = (int) ($file['size'] ?? 0);
        if ($size < 1) {
            throw new RuntimeException('O arquivo está vazio.');
        }
        if ($size > $this->maxBytes()) {
            throw new RuntimeException('O arquivo ultrapassa o limite de ' . $this->maxMegabytesLabel() . '.');
        }

        $mimeType = $this->detectMime($tmpPath);
        $definition = $this->definitionForMime($mimeType);
        $originalName = $this->sanitizeOriginalName((string) ($file['name'] ?? 'arquivo.' . $definition['extension']));
        $stored = $this->allocatePath($tenantId, $definition['extension']);

        if (!move_uploaded_file($tmpPath, $stored['absolute_path'])) {
            throw new RuntimeException('Não foi possível guardar o arquivo no armazenamento privado.');
        }

        try {
            $actualSize = filesize($stored['absolute_path']);
            if ($actualSize === false || $actualSize < 1 || $actualSize > $this->maxBytes()) {
                throw new RuntimeException('O arquivo não passou pela validação final de tamanho.');
            }

            return [
                'uuid' => $this->uuidV4(),
                'tenant_id' => $tenantId,
                'conversation_id' => $conversationId,
                'message_id' => null,
                'direction' => 'outgoing',
                'kind' => $definition['kind'],
                'original_name' => $originalName,
                'stored_name' => basename($stored['absolute_path']),
                'mime_type' => $mimeType,
                'extension' => $definition['extension'],
                'size_bytes' => (int) $actualSize,
                'storage_path' => $stored['relative_path'],
                'absolute_path' => $stored['absolute_path'],
                'sha256' => hash_file('sha256', $stored['absolute_path']) ?: null,
                'status' => 'ready',
                'created_by_user_id' => $userId > 0 ? $userId : null,
                'metadata_json' => null,
            ];
        } catch (Throwable $exception) {
            @unlink($stored['absolute_path']);
            throw $exception;
        }
    }

    /**
     * @param array<string,mixed> $metadata
     * @return array<string,mixed>
     */
    public function storeIncomingBase64(
        string $base64,
        array $metadata,
        int $tenantId,
        int $conversationId,
        int $messageId
    ): array {
        if (!$this->enabled()) {
            throw new RuntimeException('O recebimento de anexos está desativado.');
        }

        $base64 = trim($base64);
        if (str_contains($base64, ',')) {
            [, $base64] = explode(',', $base64, 2);
        }
        $binary = base64_decode($base64, true);
        if ($binary === false || $binary === '') {
            throw new RuntimeException('A mídia recebida não pôde ser decodificada.');
        }
        if (strlen($binary) > $this->maxBytes()) {
            throw new RuntimeException('A mídia recebida ultrapassa o limite de ' . $this->maxMegabytesLabel() . '.');
        }

        $declaredMime = trim((string) ($metadata['mime_type'] ?? ''));
        $temporary = tempnam(sys_get_temp_dir(), 'rs-media-');
        if ($temporary === false) {
            throw new RuntimeException('Não foi possível preparar a mídia recebida.');
        }

        try {
            if (file_put_contents($temporary, $binary, LOCK_EX) === false) {
                throw new RuntimeException('Não foi possível preparar a mídia recebida.');
            }
            $detectedMime = $this->detectMime($temporary);
            $mimeType = isset(self::MIME_MAP[$detectedMime]) ? $detectedMime : $declaredMime;
            $definition = $this->definitionForMime($mimeType);
            $stored = $this->allocatePath($tenantId, $definition['extension']);
            if (!rename($temporary, $stored['absolute_path'])) {
                if (!copy($temporary, $stored['absolute_path'])) {
                    throw new RuntimeException('Não foi possível guardar a mídia recebida.');
                }
                @unlink($temporary);
            }

            $originalName = $this->sanitizeOriginalName((string) ($metadata['original_name'] ?? 'arquivo.' . $definition['extension']));
            return [
                'uuid' => $this->uuidV4(),
                'tenant_id' => $tenantId,
                'conversation_id' => $conversationId,
                'message_id' => $messageId,
                'evolution_message_id' => $metadata['evolution_message_id'] ?? null,
                'direction' => 'incoming',
                'kind' => $definition['kind'],
                'original_name' => $originalName,
                'stored_name' => basename($stored['absolute_path']),
                'mime_type' => $mimeType,
                'extension' => $definition['extension'],
                'size_bytes' => strlen($binary),
                'storage_path' => $stored['relative_path'],
                'absolute_path' => $stored['absolute_path'],
                'sha256' => hash_file('sha256', $stored['absolute_path']) ?: null,
                'status' => 'ready',
                'created_by_user_id' => null,
                'metadata_json' => isset($metadata['metadata_json'])
                    ? json_encode($metadata['metadata_json'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    : null,
            ];
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    /** @param array<string,mixed> $attachment */
    public function insert(PDO $pdo, array $attachment): int
    {
        $statement = $pdo->prepare(
            'INSERT INTO conversation_message_attachments
                (uuid, tenant_id, conversation_id, message_id, evolution_message_id, direction,
                 attachment_kind, original_name, stored_name, mime_type, extension, size_bytes,
                 storage_disk, storage_path, sha256, status, error_message, metadata_json,
                 created_by_user_id, created_at, updated_at)
             VALUES
                (:uuid, :tenant_id, :conversation_id, :message_id, :evolution_message_id, :direction,
                 :attachment_kind, :original_name, :stored_name, :mime_type, :extension, :size_bytes,
                 "local_private", :storage_path, :sha256, :status, :error_message, :metadata_json,
                 :created_by_user_id, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
        );
        $statement->execute([
            'uuid' => (string) $attachment['uuid'],
            'tenant_id' => (int) $attachment['tenant_id'],
            'conversation_id' => (int) $attachment['conversation_id'],
            'message_id' => isset($attachment['message_id']) ? (int) $attachment['message_id'] : null,
            'evolution_message_id' => $attachment['evolution_message_id'] ?? null,
            'direction' => (string) ($attachment['direction'] ?? 'incoming'),
            'attachment_kind' => (string) ($attachment['kind'] ?? 'other'),
            'original_name' => (string) ($attachment['original_name'] ?? 'arquivo'),
            'stored_name' => $attachment['stored_name'] ?? null,
            'mime_type' => (string) ($attachment['mime_type'] ?? 'application/octet-stream'),
            'extension' => $attachment['extension'] ?? null,
            'size_bytes' => (int) ($attachment['size_bytes'] ?? 0),
            'storage_path' => $attachment['storage_path'] ?? null,
            'sha256' => $attachment['sha256'] ?? null,
            'status' => (string) ($attachment['status'] ?? 'ready'),
            'error_message' => $attachment['error_message'] ?? null,
            'metadata_json' => $attachment['metadata_json'] ?? null,
            'created_by_user_id' => $attachment['created_by_user_id'] ?? null,
        ]);
        return (int) $pdo->lastInsertId();
    }

    public function recordFailure(
        PDO $pdo,
        int $tenantId,
        int $conversationId,
        int $messageId,
        ?string $evolutionMessageId,
        string $kind,
        string $originalName,
        string $mimeType,
        string $error,
        array $metadata = []
    ): int {
        return $this->insert($pdo, [
            'uuid' => $this->uuidV4(),
            'tenant_id' => $tenantId,
            'conversation_id' => $conversationId,
            'message_id' => $messageId,
            'evolution_message_id' => $evolutionMessageId,
            'direction' => 'incoming',
            'kind' => in_array($kind, ['image', 'audio', 'document', 'video', 'other'], true) ? $kind : 'other',
            'original_name' => $this->sanitizeOriginalName($originalName !== '' ? $originalName : 'arquivo'),
            'stored_name' => null,
            'mime_type' => $mimeType !== '' ? $mimeType : 'application/octet-stream',
            'extension' => null,
            'size_bytes' => 0,
            'storage_path' => null,
            'sha256' => null,
            'status' => 'failed',
            'error_message' => mb_substr($error, 0, 500),
            'created_by_user_id' => null,
            'metadata_json' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    /** @return array<string,mixed>|null */
    public function findByUuid(PDO $pdo, string $uuid): ?array
    {
        $statement = $pdo->prepare(
            'SELECT a.*
             FROM conversation_message_attachments a
             WHERE a.uuid = :uuid
             LIMIT 1'
        );
        $statement->execute(['uuid' => trim($uuid)]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $row['absolute_path'] = $this->absolutePath((string) ($row['storage_path'] ?? ''));
        return $row;
    }

    public function deleteStoredFile(array $attachment): void
    {
        $path = (string) ($attachment['absolute_path'] ?? '');
        if ($path !== '' && is_file($path)) {
            @unlink($path);
        }
    }

    public function absolutePath(string $relativePath): string
    {
        $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
        if ($relativePath === '' || str_contains($relativePath, '..')) {
            return '';
        }
        return rtrim($this->storageRoot(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    }

    public function humanSize(int $bytes): string
    {
        if ($bytes >= 1024 * 1024) {
            return number_format($bytes / (1024 * 1024), 1, ',', '.') . ' MB';
        }
        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 0, ',', '.') . ' KB';
        }
        return $bytes . ' B';
    }

    /** @return array{kind:string,extension:string} */
    private function definitionForMime(string $mimeType): array
    {
        $mimeType = strtolower(trim(explode(';', $mimeType, 2)[0]));
        if (!isset(self::MIME_MAP[$mimeType])) {
            throw new RuntimeException('Formato não permitido. Envie imagem JPG/PNG/WEBP, PDF ou áudio MP3/OGG/OPUS/M4A.');
        }
        return self::MIME_MAP[$mimeType];
    }

    private function detectMime(string $path): string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($path);
        return is_string($mime) && $mime !== '' ? strtolower($mime) : 'application/octet-stream';
    }

    /** @return array{relative_path:string,absolute_path:string} */
    private function allocatePath(int $tenantId, string $extension): array
    {
        $directory = max(1, $tenantId) . '/' . gmdate('Y/m');
        $absoluteDirectory = rtrim($this->storageRoot(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $directory);
        if (!is_dir($absoluteDirectory) && !mkdir($absoluteDirectory, 0750, true) && !is_dir($absoluteDirectory)) {
            throw new RuntimeException('Não foi possível preparar o armazenamento privado de anexos.');
        }
        $storedName = bin2hex(random_bytes(24)) . '.' . $extension;
        return [
            'relative_path' => $directory . '/' . $storedName,
            'absolute_path' => $absoluteDirectory . DIRECTORY_SEPARATOR . $storedName,
        ];
    }

    private function storageRoot(): string
    {
        $configured = trim((string) Env::get('CONVERSATION_ATTACHMENTS_PATH', ''));
        if ($configured !== '') {
            return rtrim($configured, DIRECTORY_SEPARATOR);
        }
        return dirname(__DIR__, 2) . '/storage/conversation-attachments';
    }

    private function sanitizeOriginalName(string $name): string
    {
        $name = basename(str_replace('\\', '/', trim($name)));
        $name = preg_replace('/[\x00-\x1F\x7F]+/u', '', $name) ?: 'arquivo';
        $name = preg_replace('/[^\pL\pN._()\- ]+/u', '_', $name) ?: 'arquivo';
        $name = trim($name, " .\t\n\r\0\x0B");
        return mb_substr($name !== '' ? $name : 'arquivo', 0, 190);
    }

    private function uuidV4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    private function maxMegabytesLabel(): string
    {
        return (int) ($this->maxBytes() / (1024 * 1024)) . ' MB';
    }

    private function uploadErrorMessage(int $error): string
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'O arquivo ultrapassa o limite permitido.',
            UPLOAD_ERR_PARTIAL => 'O envio do arquivo foi interrompido. Tente novamente.',
            UPLOAD_ERR_NO_FILE => 'Selecione um arquivo para enviar.',
            UPLOAD_ERR_NO_TMP_DIR => 'O servidor está sem pasta temporária para receber arquivos.',
            UPLOAD_ERR_CANT_WRITE => 'O servidor não conseguiu gravar o arquivo.',
            UPLOAD_ERR_EXTENSION => 'O envio foi bloqueado por uma extensão do servidor.',
            default => 'Não foi possível receber o arquivo enviado.',
        };
    }
}
