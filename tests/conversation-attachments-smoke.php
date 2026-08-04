<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$read = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);

$service = $read('app/Services/ConversationAttachmentService.php');
$evolution = $read('app/Services/EvolutionService.php');
$webhook = $read('app/Controllers/EvolutionWebhookController.php');
$controller = $read('app/Controllers/ConversationController.php');
$view = $read('app/Views/conversations/index.php');
$routes = $read('routes/web.php');
$javascript = $read('public/assets/js/app.js');
$migration = $read('database/migrations/074_conversation_message_attachments.sql');
$version = $read('app/Services/AppVersionService.php');
$dockerfile = $read('Dockerfile');

$checks = [
    'serviço de anexo com armazenamento privado' => str_contains($service, 'CONVERSATION_ATTACHMENTS_PATH')
        && str_contains($service, 'detectMime')
        && str_contains($service, "hash_file('sha256'"),
    'MIME permitido para imagens, PDF e áudios' => str_contains($service, "'image/jpeg'")
        && str_contains($service, "'application/pdf'")
        && str_contains($service, "'audio/ogg'"),
    'envio de mídia pela Evolution' => str_contains($evolution, 'sendMedia')
        && str_contains($evolution, '/message/sendMedia/'),
    'download de mídia recebida pela Evolution' => str_contains($evolution, 'downloadMediaMessage')
        && str_contains($evolution, '/chat/getBase64FromMediaMessage/'),
    'webhook persiste mídia sem interromper mensagem' => str_contains($webhook, 'persistIncomingAttachment')
        && str_contains($webhook, "'phase' => 'media_attachment'"),
    'envio e streaming autorizados' => str_contains($controller, 'public function sendAttachment')
        && str_contains($controller, 'public function attachment')
        && str_contains($controller, 'Content-Range:'),
    'rotas com autenticação, permissão e CSRF' => str_contains($routes, '/conversations/attachments/send')
        && str_contains($routes, "['auth', 'permission:conversations.manage', 'csrf']")
        && str_contains($routes, '/conversations/attachment'),
    'interface possui anexo, áudio e PDF' => str_contains($view, 'data-attachment-input')
        && str_contains($view, '<audio controls')
        && str_contains($view, '>Visualizar</a>'),
    'JavaScript envia multipart e controla velocidade' => str_contains($javascript, 'data-audio-speed')
        && str_contains($javascript, 'new FormData(composerForm)')
        && str_contains($javascript, 'dataTransfer'),
    'migration 074 cria tabela e vínculos' => str_contains($migration, 'CREATE TABLE IF NOT EXISTS conversation_message_attachments')
        && str_contains($migration, 'fk_conversation_attachments_conversation')
        && str_contains($migration, 'uq_conversation_attachment_uuid'),
    'Docker suporta upload e diretório privado' => str_contains($dockerfile, 'upload_max_filesize=25M')
        && str_contains($dockerfile, 'storage/conversation-attachments'),
    'versão e migration atualizadas' => str_contains($version, 'RS Connect 36.14.0')
        && str_contains($version, '074_conversation_message_attachments.sql'),
];

$failed = [];
foreach ($checks as $label => $ok) {
    if (!$ok) {
        $failed[] = $label;
    }
}

if ($failed !== []) {
    fwrite(STDERR, "FALHA - " . implode('; ', $failed) . PHP_EOL);
    exit(1);
}

echo "OK - anexos, áudios, imagens e documentos validados na v36.14.0." . PHP_EOL;
