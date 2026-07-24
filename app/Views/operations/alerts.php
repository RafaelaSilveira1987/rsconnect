<?php
use App\Core\Csrf; use App\Core\Router; use App\Core\View;
$p=is_array($data['preferences']??null)?$data['preferences']:[];$notifications=$data['notifications']??[];$deliveries=$data['deliveries']??[];
$checked=static fn(string $k):string=>!empty($p[$k])?'checked':'';
?>
<section class="admin-module-hero operations-alert-hero"><div><span class="eyebrow">Operação RS</span><h2>Alertas operacionais</h2><p>Defina o que deve chamar sua atenção e acompanhe abertura, lembretes e recuperação dos incidentes.</p></div><div class="hero-actions"><span class="badge <?= (int)($data['unread']??0)>0?'badge-overdue':'badge-active' ?>"><?= (int)($data['unread']??0) ?> novo(s)</span><?php if((int)($data['unread']??0)>0):?><form method="post" action="<?= View::e(Router::url('/operacao-alertas/read-all')) ?>"><?= Csrf::input() ?><button class="btn btn-outline" type="submit">Marcar como lidos</button></form><?php endif;?></div></section>

<div class="operations-alert-layout">
<section class="card operations-alert-settings"><div class="section-heading"><div><span class="eyebrow">Regras</span><h2>Quando me avisar</h2><p class="muted-text">O mesmo incidente não gera spam. Um novo lembrete só é criado após o intervalo configurado.</p></div></div>
<form method="post" action="<?= View::e(Router::url('/operacao-alertas/save')) ?>"><?= Csrf::input() ?>
<div class="operations-alert-grid">
<label class="ops-check"><input type="checkbox" name="critical_enabled" value="1" <?= $checked('critical_enabled') ?>><span><strong>Críticos</strong><small>Falhas que exigem ação imediata.</small></span></label>
<label class="ops-check"><input type="checkbox" name="warning_enabled" value="1" <?= $checked('warning_enabled') ?>><span><strong>Atenções</strong><small>Situações que merecem revisão.</small></span></label>
<label class="ops-check"><input type="checkbox" name="evolution_enabled" value="1" <?= $checked('evolution_enabled') ?>><span><strong>WhatsApp / Evolution</strong><small>Instância desconectada ou degradada.</small></span></label>
<label class="ops-check"><input type="checkbox" name="ai_enabled" value="1" <?= $checked('ai_enabled') ?>><span><strong>IA</strong><small>OpenAI, credenciais e fila.</small></span></label>
<label class="ops-check"><input type="checkbox" name="n8n_enabled" value="1" <?= $checked('n8n_enabled') ?>><span><strong>n8n</strong><small>Fluxos, callbacks e tokens.</small></span></label>
<label class="ops-check"><input type="checkbox" name="backup_enabled" value="1" <?= $checked('backup_enabled') ?>><span><strong>Backup</strong><small>Falha, atraso ou backup inválido.</small></span></label>
<label class="ops-check"><input type="checkbox" name="routines_enabled" value="1" <?= $checked('routines_enabled') ?>><span><strong>Rotinas</strong><small>Cron, relatórios e execuções automáticas.</small></span></label>
</div>
<div class="section-heading compact"><div><span class="eyebrow">Canais</span><h3>Onde receber</h3></div></div>
<div class="operations-channel-grid">
<label class="ops-channel"><input type="checkbox" name="platform_enabled" value="1" <?= $checked('platform_enabled') ?>><span><strong>RS Connect</strong><small>Ativo agora: aparece no sino do Super Admin.</small></span></label>
<label class="ops-channel"><input type="checkbox" name="whatsapp_enabled" value="1" <?= $checked('whatsapp_enabled') ?>><span><strong>WhatsApp</strong><small>Estrutura preparada; depende do canal administrativo da RS.</small></span><input class="input" name="whatsapp_recipient" value="<?= View::e((string)($p['whatsapp_recipient']??'')) ?>" placeholder="5511999999999"></label>
<label class="ops-channel"><input type="checkbox" name="email_enabled" value="1" <?= $checked('email_enabled') ?>><span><strong>E-mail</strong><small>Estrutura preparada; depende do transportador de e-mail.</small></span><input class="input" type="email" name="email_recipient" value="<?= View::e((string)($p['email_recipient']??'')) ?>" placeholder="operacao@empresa.com"></label>
</div>
<div class="form-grid"><label>Relembrar incidente ainda ativo após<input class="input" type="number" min="1" max="72" name="reminder_hours" value="<?= (int)($p['reminder_hours']??3) ?>"><small>horas</small></label></div>
<div class="form-actions"><button class="btn btn-primary" type="submit">Salvar alertas</button></div></form></section>

<section class="card operations-alert-feed"><div class="section-heading"><div><span class="eyebrow">Central interna</span><h2>Últimos alertas</h2></div></div><div class="notification-list">
<?php foreach($notifications as $n):?><article class="notification-item notification-<?= View::e((string)($n['severity']??'info')) ?> <?= ($n['status']??'')==='unread'?'is-unread':'' ?>"><div class="notification-marker"></div><div class="notification-main"><strong><?= View::e((string)($n['title']??'')) ?></strong><p><?= View::e((string)($n['message']??'')) ?></p><small><?= View::e((string)($n['created_at']??'')) ?> · <?= View::e((string)($n['notification_kind']??'')) ?></small></div><?php if(!empty($n['action_url'])):?><a class="btn btn-small btn-outline" href="<?= View::e(Router::url((string)$n['action_url'])) ?>">Resolver</a><?php endif;?></article><?php endforeach;?>
<?php if(!$notifications):?><div class="empty-state">Nenhum alerta operacional registrado.</div><?php endif;?></div></section>
</div>
<section class="card"><div class="section-heading"><div><span class="eyebrow">Entregas</span><h2>Status dos canais</h2><p class="muted-text">WhatsApp/e-mail aparecem como “aguardando configuração” até existir um provedor administrativo conectado.</p></div></div><div class="table-responsive"><table><thead><tr><th>Incidente</th><th>Evento</th><th>Canal</th><th>Status</th><th>Destino</th><th>Data</th></tr></thead><tbody><?php foreach($deliveries as $d):?><tr><td>#<?= (int)($d['incident_id']??0) ?></td><td><?= View::e((string)($d['notification_kind']??'')) ?></td><td><?= View::e((string)($d['channel']??'')) ?></td><td><span class="badge"><?= View::e((string)($d['status']??'')) ?></span></td><td><?= View::e((string)($d['destination']??'—')) ?></td><td><?= View::e((string)($d['created_at']??'')) ?></td></tr><?php endforeach;?><?php if(!$deliveries):?><tr><td colspan="6">Nenhuma entrega registrada.</td></tr><?php endif;?></tbody></table></div></section>
