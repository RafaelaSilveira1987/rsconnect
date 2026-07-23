<?php

use App\Core\Auth;
use App\Core\Router;
use App\Core\View;

$statusLabel = [
    'success' => 'Concluído',
    'error' => 'Precisa de atenção',
    'skipped' => 'Não executado',
];
$eventLabel = [
    'ai.replied' => 'Resposta automática enviada',
    'ai.failed' => 'Não foi possível responder automaticamente',
    'ai.skipped' => 'Resposta automática não executada',
    'ai.cooldown' => 'Mensagem aguardando intervalo entre respostas',
    'n8n.flow.test' => 'Teste de integração externa',
    'n8n.callback' => 'Retorno de integração recebido',
    'calendar.availability.requested' => 'Consulta de agenda iniciada',
    'calendar.availability.completed' => 'Horários da agenda atualizados',
];
$formatDate = static function (?string $date): string {
    if (!$date) {
        return '—';
    }
    $timestamp = strtotime($date);
    return $timestamp ? date('d/m/Y H:i:s', $timestamp) : $date;
};
$friendlyLog = static function (array $log): array {
    $meta = json_decode((string) ($log['raw_json'] ?? ''), true);
    $meta = is_array($meta) ? $meta : [];
    if (!empty($meta['calendar_handled'])) {
        if (array_key_exists('message_sent', $meta) && empty($meta['message_sent'])) {
            return ['A agenda assumiu esta etapa, mas a mensagem ao contato não pôde ser enviada.', 'Revise a conexão WhatsApp e tente novamente.', 'error', 'Atendimento tratado pela agenda'];
        }
        return ['A mensagem foi tratada corretamente pela agenda conversacional.', 'A IA geral foi ignorada de propósito para evitar uma resposta duplicada.', 'success', 'Atendimento tratado pela agenda'];
    }

    $raw = trim((string) ($log['error_message'] ?: ($log['response_preview'] ?: '')));
    $lower = mb_strtolower($raw);
    $status = (string) ($log['status'] ?? '');
    $event = (string) ($log['event'] ?? '');

    if ($status === 'success') {
        if ($event === 'ai.replied' && $raw !== '') {
            return [$raw, 'A resposta foi enviada ao contato.'];
        }
        return ['A ação foi concluída corretamente.', 'Nenhuma ação é necessária.'];
    }

    if ($event === 'ai.cooldown' || str_contains($lower, 'intervalo mínimo')) {
        return ['A mensagem chegou antes do intervalo definido para uma nova resposta automática.', 'Ela permanece na conversa e pode ser reprocessada manualmente. Ao salvar o assistente, a última pendência também é reavaliada automaticamente.'];
    }
    if (str_contains($lower, 'não existe mensagem recebida') || str_contains($lower, 'no inbound message')) {
        return ['Não há uma mensagem do cliente disponível para gerar uma nova resposta.', 'Aguarde uma mensagem recebida ou escolha outra conversa.'];
    }
    if (str_contains($lower, '403') || str_contains($lower, '<html')) {
        return ['O serviço de inteligência artificial recusou a conexão.', 'Revise a credencial e o endereço da API na configuração da empresa.'];
    }
    if (str_contains($lower, '401') || str_contains($lower, 'unauthorized') || str_contains($lower, 'invalid api key')) {
        return ['A chave de acesso da inteligência artificial não foi aceita.', 'Atualize a credencial de IA e faça um novo teste.'];
    }
    if (str_contains($lower, '429') || str_contains($lower, 'quota') || str_contains($lower, 'rate limit') || str_contains($lower, 'insufficient_quota')) {
        return ['O limite de uso ou o saldo do serviço de IA foi atingido.', 'Verifique o faturamento e os limites da conta do provedor.'];
    }
    if (str_contains($lower, 'timeout') || str_contains($lower, 'timed out') || str_contains($lower, 'tempo limite')) {
        return ['O serviço demorou mais do que o esperado para responder.', 'Aguarde alguns instantes e tente novamente.'];
    }
    if (str_contains($lower, 'connection refused') || str_contains($lower, 'econnrefused') || str_contains($lower, 'erro de conexão') || str_contains($lower, 'could not resolve')) {
        return ['Não foi possível se comunicar com o serviço externo.', 'Confirme se o serviço está online e se o endereço cadastrado está correto.'];
    }
    if (str_contains($lower, 'webhook') && (str_contains($lower, '404') || str_contains($lower, 'not registered'))) {
        return ['A integração externa não está ativa ou o endereço informado está incorreto.', 'Ative o fluxo e atualize a URL de produção.'];
    }
    if (str_contains($lower, 'input_text') || str_contains($lower, 'invalid value')) {
        return ['O serviço de IA não aceitou o formato enviado.', 'Peça à equipe RS Connect para revisar o modelo e a configuração da API.'];
    }
    if ($status === 'skipped') {
        return ['A ação não foi executada porque uma regra do atendimento impediu o envio.', 'Confira se a conversa está com IA ativa e dentro do horário configurado.'];
    }

    return ['Não foi possível concluir esta ação.', 'Tente novamente. Se continuar, envie o horário do erro para a equipe RS Connect.'];
};
?>

<div class="automation-page automation-page-pro">
    <section class="metric-strip automation-strip">
        <article class="metric-card compact-metric"><span>Assistente conectado</span><strong><?= ($openaiConfigured || $geminiConfigured) ? 'Sim' : 'Pendente' ?></strong><small><?= ($openaiConfigured || $geminiConfigured) ? 'Serviço de IA disponível' : 'A equipe RS Connect precisa revisar a credencial' ?></small></article>
        <article class="metric-card compact-metric"><span>Respostas automáticas</span><strong><?= $autoReplyEnabled ? 'Ativas' : 'Inativas' ?></strong><small>O comportamento pode ser ajustado em Assistentes de IA</small></article>
        <article class="metric-card compact-metric"><span>Integrações externas</span><strong><?= $n8nConfigured ? 'Configuradas' : 'Opcionais' ?></strong><small><?= (int) ($tenantN8nFlows ?? 0) > 0 ? ((int) $tenantN8nFlows . ' integração(ões) ativa(s)') : 'Use somente quando o atendimento precisar' ?></small></article>
        <article class="metric-card compact-metric"><span>Atividades registradas</span><strong><?= array_sum($stats) ?></strong><small><?= (int) ($stats['success'] ?? 0) ?> concluídas · <?= (int) ($stats['error'] ?? 0) ?> com atenção</small></article>
    </section>

    <section class="card automation-log-card">
        <div class="section-heading">
            <div><span class="eyebrow">Histórico</span><h2>Respostas e integrações</h2><p class="muted-text">Veja o que foi concluído e o que precisa de revisão, em linguagem simples.</p></div>
            <span class="badge"><?= count($logs) ?> registros</span>
        </div>
        <div class="automation-log-list">
            <?php foreach ($logs as $log): ?>
                <?php
                $friendly = $friendlyLog($log);
                $message = (string) ($friendly[0] ?? '');
                $nextStep = (string) ($friendly[1] ?? '');
                $displayStatus = (string) ($friendly[2] ?? $log['status']);
                $displayEvent = (string) ($friendly[3] ?? ($eventLabel[$log['event']] ?? 'Atividade automática'));
                ?>
                <article class="automation-log-item log-<?= View::e($displayStatus) ?>">
                    <div class="log-status-marker"></div>
                    <div class="log-main">
                        <div class="log-title-row">
                            <strong><?= View::e($displayEvent) ?></strong>
                            <span class="badge badge-<?= View::e($displayStatus) ?>"><?= View::e($statusLabel[$displayStatus] ?? 'Informação') ?></span>
                        </div>
                        <p><?= View::e($message) ?></p>
                        <small class="automation-next-step"><?= View::e($nextStep) ?></small>
                        <div class="log-meta-row">
                            <span><?= View::e($formatDate($log['created_at'])) ?></span>
                            <?php if (Auth::isSuperAdmin()): ?><span><?= View::e($log['tenant_name'] ?? 'Empresa não informada') ?></span><?php endif; ?>
                            <span><?= View::e($log['agent_name'] ?? 'Assistente não informado') ?></span>
                            <span><?= View::e($log['contact_name'] ?: ($log['phone'] ?? 'Contato não informado')) ?></span>
                        </div>

                        <?php if (Auth::isSuperAdmin()): ?>
                            <details class="automation-technical-details">
                                <summary>Ver detalhes técnicos</summary>
                                <div><strong>Evento:</strong> <code><?= View::e($log['event']) ?></code></div>
                                <?php if (!empty($log['error_message'])): ?><pre><?= View::e($log['error_message']) ?></pre><?php endif; ?>
                                <?php if (empty($log['error_message']) && !empty($log['response_preview']) && $log['event'] !== 'ai.replied'): ?><pre><?= View::e($log['response_preview']) ?></pre><?php endif; ?>
                            </details>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
            <?php if (!$logs): ?><div class="empty-state">Nenhuma atividade automática registrada ainda.</div><?php endif; ?>
        </div>
    </section>
</div>
