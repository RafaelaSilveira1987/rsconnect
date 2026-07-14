<?php

use App\Core\Router;
use App\Core\View;

$manuals = [
    [
        'title' => 'Implantar novo cliente',
        'description' => 'Ordem recomendada para cadastrar empresa, usuário, WhatsApp, IA, LGPD, agenda e monitoramento.',
        'items' => ['Criar empresa', 'Conectar instância', 'Criar agente IA', 'Finalizar checklist'],
        'url' => '/implantacao',
        'super_admin' => true,
    ],
    [
        'title' => 'Primeiros passos do cliente',
        'description' => 'Fluxo guiado para o próprio cliente entender o sistema e concluir a configuração inicial.',
        'items' => ['Dados da empresa', 'WhatsApp', 'Agente IA', 'Teste final'],
        'url' => '/primeiros-passos',
        'super_admin' => false,
    ],
    [
        'title' => 'Conversas e IA',
        'description' => 'Como acompanhar atendimentos, pausar IA, assumir conversa e revisar respostas automáticas.',
        'items' => ['Assumir conversa', 'Pausar IA', 'Reprocessar IA', 'Atualização automática'],
        'url' => '/conversations',
        'super_admin' => false,
    ],
    [
        'title' => 'Agenda e pré-agendamento',
        'description' => 'Como usar intenção de agenda, aprovação humana e mensagens configuráveis por empresa.',
        'items' => ['Pré-agendado', 'Aprovar', 'Remarcar', 'Confirmar cliente'],
        'url' => '/calendar',
        'super_admin' => false,
    ],
    [
        'title' => 'n8n e integrações',
        'description' => 'Padrão correto: RS Connect centraliza WhatsApp/IA e aciona n8n por empresa para integrações externas.',
        'items' => ['Fluxo por empresa', 'Callback', 'Templates', 'Logs'],
        'url' => '/n8n-flows',
        'super_admin' => true,
    ],
    [
        'title' => 'Backup e recuperação',
        'description' => 'Como validar rotina, callback, histórico de jobs, system_backups e plano de recuperação.',
        'items' => ['Rotina ativa', 'Job success', 'Backup registrado', 'Arquivo verificado'],
        'url' => '/backup-automatico',
        'super_admin' => true,
    ],
    [
        'title' => 'Cobrança e assinatura',
        'description' => 'Planos, assinatura, faturas, gateway e régua de cobrança sem campanhas/disparos.',
        'items' => ['Plano', 'Fatura', 'Gateway', 'Régua'],
        'url' => '/billing',
        'super_admin' => true,
    ],
    [
        'title' => 'LGPD e privacidade',
        'description' => 'Termos, aceite, exportação de dados e solicitações do titular.',
        'items' => ['Termos', 'Aceite', 'Exportação', 'Anonimização'],
        'url' => '/privacy',
        'super_admin' => false,
    ],
];

$troubleshooting = [
    ['title' => 'Botão salva, mas tela não muda', 'fix' => 'Confira Network, flash de sucesso e tabela relacionada no Adminer. Depois use Ctrl+F5.'],
    ['title' => 'Webhook retornou 403', 'fix' => 'Validar token no .env do serviço correto e fazer redeploy do RS Connect.'],
    ['title' => 'IA não respondeu', 'fix' => 'Conferir ai_automation_logs, credencial OpenAI/Gemini e cooldown do agente.'],
    ['title' => 'Backup não validou', 'fix' => 'Conferir operations_backup_jobs, operations_backup_routines.last_success_at e system_backups.'],
];
?>

<section class="hero-card docs-hero">
    <div>
        <span class="eyebrow">Documentação operacional</span>
        <h2>Central de ajuda do RS Connect.</h2>
        <p>Guias rápidos para implantação, atendimento, IA, agenda, LGPD, cobrança, monitoramento e backup. Sem campanhas e sem disparos em massa.</p>
    </div>
    <div class="hero-actions">
        <?php if (!empty($is_super_admin)): ?>
            <a class="btn btn-primary" href="<?= View::e(Router::url('/beta-comercial')) ?>">Ver beta comercial</a>
            <a class="btn btn-quiet" href="<?= View::e(Router::url('/implantacao')) ?>">Checklist implantação</a>
        <?php else: ?>
            <a class="btn btn-primary" href="<?= View::e(Router::url('/primeiros-passos')) ?>">Primeiros passos</a>
        <?php endif; ?>
    </div>
</section>

<div class="docs-grid" style="margin-top:16px">
    <?php foreach ($manuals as $manual): ?>
        <?php if (!empty($manual['super_admin']) && empty($is_super_admin)) { continue; } ?>
        <article class="card docs-card">
            <div class="docs-card-head">
                <span class="docs-icon">RS</span>
                <div>
                    <h3><?= View::e($manual['title']) ?></h3>
                    <p><?= View::e($manual['description']) ?></p>
                </div>
            </div>
            <div class="docs-tags">
                <?php foreach ($manual['items'] as $item): ?><span><?= View::e($item) ?></span><?php endforeach; ?>
            </div>
            <a class="btn btn-small btn-outline" href="<?= View::e(Router::url($manual['url'])) ?>">Abrir módulo</a>
        </article>
    <?php endforeach; ?>
</div>

<div class="operations-grid" style="margin-top:16px">
    <section class="card">
        <div class="section-heading"><div><span class="eyebrow">Fluxo principal</span><h2>Ordem recomendada para operação</h2></div></div>
        <div class="docs-timeline">
            <div><strong>1. Implantação</strong><span>Empresa, usuário, WhatsApp, IA e checklist interno.</span></div>
            <div><strong>2. Onboarding do cliente</strong><span>Cliente conclui primeiros passos e aceita termos.</span></div>
            <div><strong>3. Atendimento</strong><span>Conversas, IA, CRM, agenda e pré-agendamento.</span></div>
            <div><strong>4. Operação</strong><span>Monitoramento, backup, cobrança e revisão semanal.</span></div>
        </div>
    </section>
    <section class="card">
        <div class="section-heading"><div><span class="eyebrow">Problemas comuns</span><h2>Diagnóstico rápido</h2></div></div>
        <div class="security-list">
            <?php foreach ($troubleshooting as $item): ?>
                <div class="security-row">
                    <div><strong><?= View::e($item['title']) ?></strong><small><?= View::e($item['fix']) ?></small></div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
</div>
