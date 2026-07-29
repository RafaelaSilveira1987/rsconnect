<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$auth = file_get_contents($root . '/app/Controllers/AuthController.php') ?: '';
$js = file_get_contents($root . '/public/assets/js/app.js') ?: '';
$version = file_get_contents($root . '/app/Services/AppVersionService.php') ?: '';
$layout = file_get_contents($root . '/app/Views/layouts/app.php') ?: '';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FALHA: {$message}\n");
        exit(1);
    }
};

$assert(!str_contains($auth, 'Bem-vinda ao RS Connect'), 'A saudação fixa com gênero foi removida.');
$assert(str_contains($auth, "'Bom dia'"), 'Existe saudação de manhã.');
$assert(str_contains($auth, "'Boa tarde'"), 'Existe saudação de tarde.');
$assert(str_contains($auth, "'Boa noite'"), 'Existe saudação de noite.');
$assert(str_contains($auth, "Auth::user()['name']"), 'A saudação usa a identificação do usuário autenticado.');
$assert(str_contains($js, 'const summary = { added: 0, incoming: 0, outgoing: 0 };'), 'O polling contabiliza mensagens por direção.');
$assert(str_contains($js, "message.direction === 'incoming'"), 'Somente incoming entra no contador de recebidas.');
$assert(str_contains($js, "showToast('Nova mensagem recebida.')"), 'O aviso singular de recebimento está configurado.');
$assert(str_contains($js, "showToast(payload.message || 'Mensagem enviada.')"), 'A confirmação de envio permanece configurada.');
$assert(!str_contains($js, 'nova(s) mensagem(ns) recebida(s)'), 'O contador genérico que classificava qualquer mensagem como recebida foi removido.');
$assert(str_contains($version, 'RS Connect 36.7.1'), 'A versão do pacote foi atualizada.');
$assert(str_contains($layout, 'app.js?v=36.7.1'), 'O cache do JavaScript foi renovado.');

echo "OK: saudação neutra e notificações por direção validadas.\n";
