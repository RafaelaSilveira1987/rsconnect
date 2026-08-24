<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$controller = file_get_contents($root . '/app/Controllers/ContactController.php') ?: '';
$view = file_get_contents($root . '/app/Views/contacts/index.php') ?: '';
$js = file_get_contents($root . '/public/assets/js/app.js') ?: '';

$assertions = [
    !str_contains($controller, 'ct.name LIKE :search OR ct.phone LIKE :search'),
    str_contains($controller, ':search_name_'),
    str_contains($controller, ':search_phone_'),
    str_contains($controller, 'CAST(ct.tags_json AS CHAR)'),
    str_contains($controller, "preg_split('/\\s+/u'"),
    str_contains($view, 'data-contact-filter-form'),
    str_contains($view, 'data-contact-search'),
    str_contains($view, 'Nenhum contato encontrado'),
    str_contains($js, "document.querySelectorAll('[data-contact-filter-form]')"),
    str_contains($js, 'window.setTimeout(submit, 450)'),
];

if (in_array(false, $assertions, true)) {
    fwrite(STDERR, "FALHA - busca da Base de contatos incompleta.\n");
    exit(1);
}

echo "OK - busca da Base de contatos usa parâmetros únicos, telefone normalizado e filtro automático.\n";
