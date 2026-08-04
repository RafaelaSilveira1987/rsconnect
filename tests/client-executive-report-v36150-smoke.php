<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$service = file_get_contents($root . '/app/Services/TenantExecutiveReportService.php') ?: '';
$view = file_get_contents($root . '/app/Views/reports/index.php') ?: '';
$css = file_get_contents($root . '/public/assets/css/reports.css') ?: '';
$version = file_get_contents($root . '/app/Services/AppVersionService.php') ?: '';
$layout = file_get_contents($root . '/app/Views/layouts/app.php') ?: '';

$assertions = [
    'versão 36.15.0 publicada' => str_contains($version, 'RS Connect 36.15.0')
        && str_contains($version, '074_conversation_message_attachments.sql'),
    'cache global renovado' => str_contains($layout, 'app.css?v=36.15.0')
        && str_contains($layout, 'app.js?v=36.15.0'),
    'métricas executivas do tenant disponíveis' => str_contains($service, "'responded_conversations'")
        && str_contains($service, "'human_conversations'")
        && str_contains($service, "'first_responses_measured'")
        && str_contains($service, "'situations_open'"),
    'oito indicadores principais no cliente' => str_contains($view, 'Conversas iniciadas')
        && str_contains($view, 'Conversas respondidas')
        && str_contains($view, 'Atendimentos humanos')
        && str_contains($view, 'Tempo médio da 1ª resposta')
        && str_contains($view, 'Agendamentos')
        && str_contains($view, 'Comparecimento')
        && str_contains($view, 'Uso da IA')
        && str_contains($view, 'Situações que precisam atenção'),
    'gráficos principais incluídos' => str_contains($view, 'Atendimentos ao longo do tempo')
        && str_contains($view, 'Distribuição das interações')
        && str_contains($view, 'Interações por horário'),
    'equipe agenda e atenção incluídas' => str_contains($view, 'Desempenho da equipe')
        && str_contains($view, 'Resultado da agenda')
        && str_contains($view, 'Conversas que precisam de atenção'),
    'exportações rápidas disponíveis' => str_contains($view, 'Relatórios prontos para exportar')
        && str_contains($view, "['type' => 'conversations']")
        && str_contains($view, "['type' => 'leads']"),
    'uuid público preservado nas conversas' => str_contains($view, "PublicId::encode('conversation'"),
    'estilos responsivos incluídos' => str_contains($css, 'v36.15.0 — Painel executivo das empresas clientes')
        && str_contains($css, '.rs-client-attention-grid')
        && str_contains($css, '@media (max-width:600px)'),
];

$failed = [];
foreach ($assertions as $label => $ok) {
    if (!$ok) $failed[] = $label;
}

if ($failed !== []) {
    fwrite(STDERR, 'Falhas: ' . implode(', ', $failed) . PHP_EOL);
    exit(1);
}

echo "OK - painel executivo das empresas clientes validado na v36.15.0." . PHP_EOL;
