<?php

use App\Core\Csrf;
use App\Core\Router;
use App\Core\View;
?>
<form class="drawer-form admin-company-create-form" method="post" action="<?= View::e(Router::url('/companies')) ?>">
    <?= Csrf::input() ?>

    <section class="drawer-section">
        <div class="drawer-section-title"><div><span class="eyebrow">1. Empresa</span><h3>Dados principais</h3><small>Informações usadas para identificar o cliente na plataforma.</small></div></div>
        <div class="drawer-form-grid">
            <label class="field drawer-span"><span>Nome da empresa</span><input name="name" placeholder="Clínica Exemplo" required></label>
            <label class="field drawer-span"><span>Razão social</span><input name="legal_name" placeholder="Clínica Exemplo Ltda."></label>
            <label class="field"><span>CNPJ/CPF</span><input name="document" placeholder="00.000.000/0001-00"></label>
            <label class="field"><span>Plano inicial</span><select name="plan"><option value="starter">Inicial</option><option value="pro">Profissional</option><option value="business">Empresarial</option><option value="custom">Personalizado</option></select></label>
            <label class="field drawer-span"><span>E-mail comercial</span><input type="email" name="email" placeholder="contato@empresa.com"></label>
            <label class="field"><span>Telefone</span><input name="phone" placeholder="(11) 99999-9999"></label>
            <label class="field"><span>Nicho da empresa</span><select name="business_niche_id" data-company-niche>
                <option value="">Sem blueprint automático</option>
                <?php foreach (($businessNiches ?? []) as $niche): ?>
                    <option value="<?= (int) ($niche['id'] ?? 0) ?>"><?= View::e((string) ($niche['name'] ?? '')) ?></option>
                <?php endforeach; ?>
            </select><small class="field-hint">O nicho habilita a arquitetura inicial de triagem, políticas e ações do agente.</small></label>
            <label class="field"><span>Blueprint do agente</span><select name="agent_blueprint_id" data-company-blueprint>
                <option value="">Selecionar automaticamente pelo nicho</option>
                <?php foreach (($agentBlueprints ?? []) as $blueprint): ?>
                    <option value="<?= (int) ($blueprint['id'] ?? 0) ?>" data-niche-id="<?= (int) ($blueprint['niche_id'] ?? 0) ?>"><?= View::e((string) (($blueprint['niche_name'] ?? '') . ' — ' . ($blueprint['name'] ?? ''))) ?></option>
                <?php endforeach; ?>
            </select></label>
            <label class="field drawer-span"><span>Segmento livre / observação</span><input name="segment" placeholder="Opcional. Ex.: Psicologia infantil, barbearia premium..."><small class="field-hint">Se ficar vazio, o RS Connect usa o nome do nicho selecionado.</small></label>
        </div>
    </section>

    <section class="drawer-section">
        <div class="drawer-section-title"><div><span class="eyebrow">2. Primeiro acesso</span><h3>Administrador do cliente</h3><small>Essa pessoa receberá acesso para configurar a equipe e usar o sistema.</small></div></div>
        <div class="drawer-form-grid">
            <label class="field drawer-span"><span>Nome do responsável</span><input name="owner_name" placeholder="Responsável pela conta" required></label>
            <label class="field drawer-span"><span>E-mail de acesso</span><input type="email" name="owner_email" placeholder="admin@empresa.com" required></label>
            <label class="field drawer-span"><span>Senha inicial</span><input type="password" name="owner_password" minlength="8" placeholder="Mínimo de 8 caracteres" required><small class="field-hint">Oriente o cliente a alterar a senha no primeiro acesso.</small></label>
        </div>
    </section>

    <div class="drawer-savebar">
        <button class="btn btn-quiet" type="button" data-close-panel="admin-company-create-drawer">Cancelar</button>
        <button class="btn btn-primary" type="submit">Criar empresa e acesso</button>
    </div>
</form>

<script>
(function () {
    const niche = document.querySelector('[data-company-niche]');
    const blueprint = document.querySelector('[data-company-blueprint]');
    if (!niche || !blueprint) return;
    const sync = () => {
        const nicheId = niche.value;
        let firstVisible = '';
        [...blueprint.options].forEach((option, index) => {
            if (index === 0) return;
            const visible = !nicheId || option.dataset.nicheId === nicheId;
            option.hidden = !visible;
            if (visible && !firstVisible) firstVisible = option.value;
        });
        const selected = blueprint.selectedOptions[0];
        if (selected && selected.value && selected.hidden) blueprint.value = '';
    };
    niche.addEventListener('change', sync);
    sync();
})();
</script>
