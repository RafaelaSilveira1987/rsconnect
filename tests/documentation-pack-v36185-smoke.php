<?php

declare(strict_types=1);

$root = dirname(__DIR__) . '/docs/guias';
$required = [
    'README.md',
    'manual-administrador-cliente.md',
    'manual-super-admin.md',
    'guia-criacao-instancia.md',
    'guia-conexao-qrcode.md',
    'guia-configuracao-assistentes.md',
    'guia-filas-atendimento-humano.md',
    'guia-consumo-ia.md',
    'guia-memoria-progressiva.md',
    'matriz-permissoes.md',
    'roteiro-implantacao.md',
    'roteiro-homologacao.md',
    'material-comercial.md',
];

foreach ($required as $file) {
    $path = $root . '/' . $file;
    if (!is_file($path) || filesize($path) < 120) {
        fwrite(STDERR, "Documento ausente ou incompleto: {$file}\n");
        exit(1);
    }
}

echo "OK - pacote de apresentação e documentação operacional incluído.\n";
