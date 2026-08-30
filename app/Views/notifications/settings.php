<?php

use App\Core\Csrf;
use App\Core\Router;
use App\Core\View;

$preferences = is_array($preferences ?? null) ? $preferences : [];
$canManagePreferences = !empty($canManagePreferences);

$preferenceCards = [
    [
        'field' => 'messages_enabled',
        'title' => 'Novas mensagens',
        'description' => 'Avise no sininho quando um contato enviar uma nova mensagem pelo WhatsApp.',
        'icon' => '<path d="M5 6h14v9H8l-3 3V6Z"/>',
    ],
    [
        'field' => 'ai_errors_enabled',
        'title' => 'Assistente virtual',
        'description' => 'Avise quando o assistente não conseguir responder ou precisar de atenção.',
        'icon' => '<path d="M12 3l2.4 5 5.6.8-4 3.9.9 5.5L12 15.6 7.1 18.2l.9-5.5-4-3.9 5.6-.8L12 3Z"/>',
    ],
    [
        'field' => 'automation_errors_enabled',
        'title' => 'Integrações e automações',
        'description' => 'Avise quando uma automação ou conexão com outro serviço não for concluída.',
        'icon' => '<path d="M6 7h4v4H6zM14 13h4v4h-4zM10 9h2a2 2 0 0 1 2 2v2"/>',
    ],
    [
        'field' => 'calendar_enabled',
        'title' => 'Agenda',
        'description' => 'Avise sobre novos pré-agendamentos e mudanças importantes nos compromissos.',
        'icon' => '<path d="M7 3v4M17 3v4M4 9h16M5 5h14v16H5z"/>',
    ],
    [
        'field' => 'billing_enabled',
        'title' => 'Financeiro e assinatura',
        'description' => 'Avise sobre vencimentos, atrasos, pagamentos e alterações da assinatura.',
        'icon' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 10h18M7 15h3"/>',
    ],
    [
        'field' => 'system_enabled',
        'title' => 'Avisos importantes',
        'description' => 'Receba comunicados essenciais sobre a conta e o funcionamento do RS Connect.',
        'icon' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/><path d="m9 12 2 2 4-4"/>',
    ],
];

$automationRules = is_array($automationRules ?? null) ? $automationRules : [];
$automationDefinitions = is_array($automationDefinitions ?? null) ? $automationDefinitions : [];
$deliveryStats = is_array($deliveryStats ?? null) ? $deliveryStats : [];
$automationReady = $automationRules !== [] && (bool) array_filter($automationRules, static fn (array $rule): bool => !empty($rule['ready']));
$automationGroups = ['agenda' => [], 'comercial' => []];
foreach ($automationDefinitions as $eventKey => $definition) {
    $group = (string) ($definition['group'] ?? 'agenda');
    $automationGroups[$group][$eventKey] = $definition;
}
?>

<section class="card hero-card notification-hero notification-hero-configurable">
    <div>
        <span class="eyebrow light">Configurações</span>
        <h2>Notificações e alertas</h2>
        <p>Defina quais eventos geram avisos, para quem o WhatsApp será enviado e quando lembretes e escalonamentos devem acontecer.</p>
    </div>
    <div class="hero-actions">
        <a class="btn btn-outline" href="<?= View::e(Router::url('/notifications')) ?>">Voltar aos avisos</a>
    </div>
</section>

<section class="card notification-automation-card" id="automatic-notifications">
    <div class="section-heading notification-preferences-heading">
        <div>
            <span class="eyebrow">Automação</span>
            <h2>Notificações de agenda e orçamento</h2>
            <p class="muted-text">Crie avisos internos e envie WhatsApp para a equipe sem depender de alguém abrir a conversa.</p>
        </div>
        <div class="notification-delivery-summary">
            <span class="badge"><?= (int) ($deliveryStats['pending'] ?? 0) ?> pendente(s)</span>
            <span class="badge badge-warning"><?= (int) ($deliveryStats['retry'] ?? 0) ?> nova(s) tentativa(s)</span>
            <span class="badge badge-active"><?= (int) ($deliveryStats['sent'] ?? 0) ?> enviada(s)</span>
            <span class="badge badge-overdue"><?= (int) ($deliveryStats['failed'] ?? 0) ?> falha(s)</span>
        </div>
    </div>

    <?php if (!$automationReady): ?>
        <div class="inline-warning">Execute a migration <strong>092_notification_orchestration.sql</strong> para liberar a fila e os canais automáticos.</div>
    <?php endif; ?>

    <form method="post" action="<?= View::e(Router::url('/notifications/rules')) ?>" class="notification-automation-form">
        <?= Csrf::input() ?>
        <?php foreach ($automationGroups as $groupKey => $groupEvents): ?>
            <div class="notification-rule-group">
                <div class="section-heading compact">
                    <div>
                        <span class="eyebrow"><?= $groupKey === 'agenda' ? 'Agenda' : 'Comercial' ?></span>
                        <h3><?= $groupKey === 'agenda' ? 'Agendamentos e lembretes' : 'Orçamentos e prazos' ?></h3>
                    </div>
                </div>
                <div class="notification-rule-grid">
                    <?php foreach ($groupEvents as $eventKey => $definition): ?>
                        <?php
                        $rule = is_array($automationRules[$eventKey] ?? null) ? $automationRules[$eventKey] : [];
                        $inputKey = (string) ($definition['input_key'] ?? 'rule');
                        $enabled = (int) ($rule['enabled'] ?? 1) === 1;
                        $inApp = (int) ($rule['in_app_enabled'] ?? 1) === 1;
                        $whatsapp = (int) ($rule['whatsapp_enabled'] ?? 0) === 1;
                        ?>
                        <article class="notification-rule-card <?= $enabled ? 'is-enabled' : '' ?>">
                            <div class="notification-rule-title">
                                <div>
                                    <strong><?= View::e((string) ($definition['label'] ?? $eventKey)) ?></strong>
                                    <small><?= View::e((string) ($definition['description'] ?? '')) ?></small>
                                </div>
                                <label class="notification-switch" title="Ativar evento">
                                    <input type="checkbox" name="rules[<?= View::e($inputKey) ?>][enabled]" value="1" <?= $enabled ? 'checked' : '' ?> <?= (!$canManagePreferences || !$automationReady) ? 'disabled' : '' ?>>
                                    <span aria-hidden="true"></span>
                                </label>
                            </div>

                            <div class="notification-rule-channels">
                                <label class="check-option"><input type="checkbox" name="rules[<?= View::e($inputKey) ?>][in_app_enabled]" value="1" <?= $inApp ? 'checked' : '' ?> <?= (!$canManagePreferences || !$automationReady) ? 'disabled' : '' ?>><span>Aviso no sistema</span></label>
                                <label class="check-option"><input type="checkbox" name="rules[<?= View::e($inputKey) ?>][whatsapp_enabled]" value="1" <?= $whatsapp ? 'checked' : '' ?> <?= (!$canManagePreferences || !$automationReady) ? 'disabled' : '' ?>><span>WhatsApp para equipe</span></label>
                            </div>

                            <label class="field compact-field">
                                <span>WhatsApp que receberá o aviso</span>
                                <input type="text" inputmode="tel" name="rules[<?= View::e($inputKey) ?>][recipient_phone]" value="<?= View::e((string) ($rule['recipient_phone'] ?? '')) ?>" placeholder="Ex.: 5532987073537" <?= (!$canManagePreferences || !$automationReady) ? 'disabled' : '' ?>>
                                <small>Se ficar vazio, usa o WhatsApp comercial quando ele for diferente do próprio número conectado. Para maior segurança, informe o celular do responsável.</small>
                            </label>

                            <?php if (!empty($definition['supports_reminder'])): ?>
                                <label class="field compact-field"><span>Avisar quantos minutos antes?</span><input type="number" min="5" max="10080" name="rules[<?= View::e($inputKey) ?>][reminder_minutes]" value="<?= (int) ($rule['reminder_minutes'] ?? 120) ?>" <?= (!$canManagePreferences || !$automationReady) ? 'disabled' : '' ?>></label>
                            <?php endif; ?>
                            <?php if (!empty($definition['supports_escalation'])): ?>
                                <label class="field compact-field"><span>Escalar quantos minutos após o prazo?</span><input type="number" min="5" max="10080" name="rules[<?= View::e($inputKey) ?>][escalation_minutes]" value="<?= (int) ($rule['escalation_minutes'] ?? 30) ?>" <?= (!$canManagePreferences || !$automationReady) ? 'disabled' : '' ?>></label>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>

        <?php if ($canManagePreferences && $automationReady): ?>
            <div class="notification-preferences-actions">
                <span>Avisos imediatos são processados automaticamente. Lembretes futuros continuam protegidos pelo agendador do servidor.</span>
                <button class="btn btn-primary" type="submit">Salvar notificações automáticas</button>
            </div>
        <?php endif; ?>
    </form>

    <?php if ($canManagePreferences && $automationReady): ?>
        <form method="post" action="<?= View::e(Router::url('/notifications/process')) ?>" class="notification-process-form">
            <?= Csrf::input() ?>
            <button class="btn btn-outline" type="submit">Processar fila agora</button>
        </form>
    <?php endif; ?>
</section>

<section class="card notification-preferences-card">
    <div class="section-heading notification-preferences-heading">
        <div>
            <span class="eyebrow">Preferências</span>
            <h2>Quais avisos devem aparecer?</h2>
            <p class="muted-text">Você pode alterar essas opções a qualquer momento.</p>
        </div>
        <?php if (!$canManagePreferences): ?>
            <span class="badge badge-info">Somente o administrador da empresa pode alterar</span>
        <?php endif; ?>
    </div>

    <form method="post" action="<?= View::e(Router::url('/notifications/preferences')) ?>" class="notification-preferences-form">
        <?= Csrf::input() ?>
        <div class="notification-preference-grid">
            <?php foreach ($preferenceCards as $card): ?>
                <?php $enabled = (int) ($preferences[$card['field']] ?? 1) === 1; ?>
                <label class="notification-preference-option <?= $enabled ? 'is-enabled' : '' ?> <?= !$canManagePreferences ? 'is-readonly' : '' ?>">
                    <span class="notification-preference-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><?= $card['icon'] ?></svg>
                    </span>
                    <span class="notification-preference-copy">
                        <strong><?= View::e($card['title']) ?></strong>
                        <small><?= View::e($card['description']) ?></small>
                    </span>
                    <span class="notification-switch">
                        <input
                            type="checkbox"
                            name="<?= View::e($card['field']) ?>"
                            value="1"
                            <?= $enabled ? 'checked' : '' ?>
                            <?= !$canManagePreferences ? 'disabled' : '' ?>
                            aria-label="Ativar <?= View::e($card['title']) ?>"
                        >
                        <span aria-hidden="true"></span>
                    </span>
                </label>
            <?php endforeach; ?>
        </div>

        <?php if ($canManagePreferences): ?>
            <div class="notification-preferences-actions">
                <span>As mudanças valem para toda a equipe desta empresa.</span>
                <button class="btn btn-primary" type="submit">Salvar preferências</button>
            </div>
        <?php endif; ?>
    </form>
</section>
