<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/Core/Autoloader.php';
App\Core\Autoloader::register(dirname(__DIR__, 2) . '/app');

use App\Controllers\EvolutionWebhookController;

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$controller = new EvolutionWebhookController();
$preferred = new ReflectionMethod($controller, 'preferredRemoteJid');
$preferred->setAccessible(true);
$normalize = new ReflectionMethod($controller, 'normalizeContactPhone');
$normalize->setAccessible(true);
$extractName = new ReflectionMethod($controller, 'extractWhatsappName');
$extractName->setAccessible(true);
$isPhone = new ReflectionMethod($controller, 'isPhoneRemoteJid');
$isPhone->setAccessible(true);

$resolvedLid = $preferred->invoke($controller, '77296929124465@lid', '', [
    'key' => [
        'remoteJid' => '77296929124465@lid',
        'remoteJidAlt' => '5532987654321@s.whatsapp.net',
    ],
]);
$assert($resolvedLid === '5532987654321@s.whatsapp.net', 'LID deve preferir o remoteJidAlt telefônico.');

$resolvedInvalidPhoneJid = $preferred->invoke($controller, '77296929124465@s.whatsapp.net', '', [
    'key' => [
        'remoteJid' => '77296929124465@s.whatsapp.net',
        'senderPn' => '5532987654321@s.whatsapp.net',
    ],
]);
$assert($resolvedInvalidPhoneJid === '5532987654321@s.whatsapp.net', 'JID brasileiro não canônico deve ser substituído pelo senderPn telefônico.');

$assert($normalize->invoke($controller, '', '32987654321@s.whatsapp.net') === '5532987654321', 'Telefone brasileiro de 11 dígitos deve receber DDI 55.');
$assert($normalize->invoke($controller, '5532987654321', '') === '5532987654321', 'Telefone internacional já completo deve ser preservado.');
$assert($normalize->invoke($controller, '', '77296929124465@lid') === '', 'LID numérico não deve ser salvo como telefone.');
$assert($isPhone->invoke($controller, '77296929124465@s.whatsapp.net') === false, 'JID de 14 dígitos sem DDI 55 não deve ser tratado como telefone brasileiro.');

$name = $extractName->invoke($controller, [
    'pushName' => '',
    'verifiedName' => 'Rafaela Silva',
]);
$assert($name === 'Rafaela Silva', 'verifiedName deve ser usado quando pushName estiver vazio.');

$nameNested = $extractName->invoke($controller, [
    'contact' => ['name' => 'Rafaela Silva'],
]);
$assert($nameNested === 'Rafaela Silva', 'Nome deve ser recuperado de estruturas aninhadas do payload.');

$source = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Controllers/EvolutionWebhookController.php');
$service = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Services/EvolutionService.php');
$assert(str_contains($source, "'number', 'phone'"), 'A resolução de JID deve considerar number/phone como candidatos.');
$assert(str_contains($source, '$remoteJid = $this->preferredRemoteJid('), 'contacts.upsert deve passar pelo resolvedor de JID.');
$assert(str_contains($source, '$phone = $this->normalizeContactPhone('), 'Telefone deve ser normalizado antes de persistir.');
$assert(str_contains($source, '$promote = $seen >= 1;'), 'Nome confiável deve ser promovido já na primeira observação válida.');
$assert(str_contains($service, '/chat/findContacts/'), 'EvolutionService deve suportar enriquecimento por findContacts.');

if ($failures !== []) {
    fwrite(STDERR, "FALHAS:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "OK - identidade de contato Evolution: nome e telefone validados.\n";
