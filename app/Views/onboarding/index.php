<?php

use App\Core\Csrf;
use App\Core\Router;
use App\Core\View;

$step = (int) ($company['onboarding_step'] ?? 1);
$completed = !empty($company['onboarding_completed_at']);
$segment = trim((string) ($company['segment'] ?? 'atendimento'));
$currentAgent = $agents[0] ?? [];
$defaultPrompt = "Você é o assistente virtual da empresa {$company['name']}.\n"
    . "Atue no segmento de {$segment}. Responda em português do Brasil, de forma curta, educada e objetiva.\n"
    . "Entenda a necessidade do contato, colete nome e objetivo do atendimento e encaminhe para uma pessoa quando não tiver segurança.\n"
    . "Não invente preços, prazos, políticas ou informações que não estejam na base da empresa.";
?>
<div class="hero-card onboarding-hero <?= $completed ? 'hero-complete' : '' ?>">
    <div>
        <span class="eyebrow light">Assistente de implantação</span>
        <h2><?= $completed ? 'Configuração inicial concluída.' : 'Prepare o RS Connect em três etapas.' ?></h2>
        <p><?= $completed
            ? 'Você pode revisar os dados abaixo. A execução real do agente será conectada ao provedor de IA e ao n8n no ZIP 05.'
            : 'Complete os dados empresariais, escolha a conexão do WhatsApp e defina o comportamento do primeiro agente.' ?></p>
    </div>
    <span class="hero-badge"><?= $completed ? '100% concluído' : 'Etapa ' . min($step, 3) . ' de 3' ?></span>
</div>

<div class="onboarding-progress" aria-label="Progresso do onboarding">
    <div class="progress-item <?= $step >= 2 ? 'is-complete' : 'is-current' ?>"><span>1</span><div><strong>Empresa</strong><small>Identidade e segmento</small></div></div>
    <i></i>
    <div class="progress-item <?= $step >= 3 ? 'is-complete' : ($step === 2 ? 'is-current' : '') ?>"><span>2</span><div><strong>WhatsApp</strong><small>Instância Evolution</small></div></div>
    <i></i>
    <div class="progress-item <?= $step >= 4 ? 'is-complete' : ($step === 3 ? 'is-current' : '') ?>"><span>3</span><div><strong>Agente IA</strong><small>Prompt inicial</small></div></div>
</div>

<section class="wizard-section <?= $step === 1 ? 'is-current' : '' ?>">
    <div class="wizard-heading"><span class="wizard-number">1</span><div><span class="eyebrow">Dados empresariais</span><h2>Identifique a operação</h2><p>Essas informações ajudam a contextualizar os próximos módulos.</p></div><span class="badge <?= $step >= 2 ? 'badge-active' : 'badge-pending' ?>"><?= $step >= 2 ? 'Concluída' : 'Pendente' ?></span></div>
    <form class="card wizard-card" method="post" action="<?= View::e(Router::url('/onboarding/company')) ?>">
        <?= Csrf::input() ?>
        <div class="form-grid two">
            <label class="field"><span>Nome de exibição</span><input name="name" value="<?= View::e($company['name']) ?>" required></label>
            <label class="field"><span>Razão social</span><input name="legal_name" value="<?= View::e($company['legal_name'] ?? '') ?>"></label>
            <label class="field"><span>CNPJ/CPF</span><input name="document" value="<?= View::e($company['document'] ?? '') ?>"></label>
            <label class="field"><span>Segmento</span><input name="segment" value="<?= View::e($company['segment'] ?? '') ?>" placeholder="Ex.: clínica, imobiliária, barbearia" required></label>
            <label class="field"><span>E-mail comercial</span><input type="email" name="email" value="<?= View::e($company['email'] ?? '') ?>"></label>
            <label class="field"><span>Telefone</span><input name="phone" value="<?= View::e($company['phone'] ?? '') ?>"></label>
        </div>
        <label class="field"><span>Site</span><input type="url" name="website" value="<?= View::e($company['website'] ?? '') ?>" placeholder="https://empresa.com.br"></label>
        <div class="form-actions"><button class="btn btn-primary" type="submit"><?= $step >= 2 ? 'Atualizar e continuar' : 'Concluir etapa 1' ?></button></div>
    </form>
</section>

<section class="wizard-section <?= $step === 2 ? 'is-current' : '' ?>">
    <div class="wizard-heading"><span class="wizard-number">2</span><div><span class="eyebrow">Canal de atendimento</span><h2>Escolha a instância do WhatsApp</h2><p>Use uma conexão já cadastrada ou adicione a Evolution API agora.</p></div><span class="badge <?= $step >= 3 ? 'badge-active' : 'badge-pending' ?>"><?= $step >= 3 ? 'Concluída' : 'Pendente' ?></span></div>
    <div class="content-grid two-columns wizard-columns">
        <form class="card wizard-card" method="post" action="<?= View::e(Router::url('/onboarding/instance')) ?>">
            <?= Csrf::input() ?>
            <input type="hidden" name="mode" value="existing">
            <span class="eyebrow">Opção A</span><h3>Usar instância cadastrada</h3>
            <label class="field"><span>Instância</span><select name="instance_id" required <?= !$instances ? 'disabled' : '' ?>><option value="">Selecione</option><?php foreach ($instances as $instance): ?><option value="<?= (int) $instance['id'] ?>" <?= (int) $instance['is_default'] === 1 ? 'selected' : '' ?>><?= View::e($instance['name']) ?> — <?= View::e(ucfirst($instance['status'])) ?></option><?php endforeach; ?></select></label>
            <button class="btn btn-primary btn-block" type="submit" <?= !$instances ? 'disabled' : '' ?>>Definir como padrão</button>
            <?php if (!$instances): ?><p class="field-hint">Nenhuma instância cadastrada. Use a opção B.</p><?php endif; ?>
        </form>

        <form class="card wizard-card" method="post" action="<?= View::e(Router::url('/onboarding/instance')) ?>">
            <?= Csrf::input() ?>
            <input type="hidden" name="mode" value="new">
            <span class="eyebrow">Opção B</span><h3>Cadastrar nova instância</h3>
            <label class="field"><span>Nome interno</span><input name="name" placeholder="WhatsApp Comercial" required></label>
            <label class="field"><span>Nome na Evolution</span><input name="instance_name" placeholder="empresa-comercial" required></label>
            <label class="field"><span>URL base</span><input type="url" name="base_url" value="<?= View::e($defaultUrl) ?>" placeholder="https://evolution.seudominio.com" required></label>
            <label class="field"><span>API Key</span><input type="password" name="api_key" required></label>
            <button class="btn btn-secondary btn-block" type="submit">Cadastrar e continuar</button>
        </form>
    </div>
</section>

<section class="wizard-section <?= $step === 3 ? 'is-current' : '' ?>">
    <div class="wizard-heading"><span class="wizard-number">3</span><div><span class="eyebrow">Inteligência artificial</span><h2>Crie o primeiro agente</h2><p>Salve o comportamento inicial. As credenciais do modelo serão configuradas no módulo de IA.</p></div><span class="badge <?= $completed ? 'badge-active' : 'badge-pending' ?>"><?= $completed ? 'Concluída' : 'Pendente' ?></span></div>
    <form class="card wizard-card" method="post" action="<?= View::e(Router::url('/onboarding/agent')) ?>">
        <?= Csrf::input() ?>
        <div class="form-grid two">
            <label class="field"><span>Instância vinculada</span><select name="instance_id" required <?= !$instances ? 'disabled' : '' ?>><option value="">Selecione</option><?php foreach ($instances as $instance): ?><option value="<?= (int) $instance['id'] ?>" <?= (int) $instance['is_default'] === 1 ? 'selected' : '' ?>><?= View::e($instance['name']) ?></option><?php endforeach; ?></select></label>
            <label class="field"><span>Nome do agente</span><input name="name" value="<?= View::e($currentAgent['name'] ?? ('Assistente ' . $company['name'])) ?>" required></label>
            <label class="field"><span>Segmento</span><input name="segment" value="<?= View::e($currentAgent['segment'] ?? ($company['segment'] ?? '')) ?>" required></label>
            <label class="field"><span>Provedor</span><select name="model_provider"><?php foreach (['google' => 'Google Gemini', 'openai' => 'OpenAI', 'anthropic' => 'Anthropic', 'custom' => 'Personalizado'] as $providerValue => $providerLabel): ?><option value="<?= $providerValue ?>" <?= ($currentAgent['model_provider'] ?? 'google') === $providerValue ? 'selected' : '' ?>><?= $providerLabel ?></option><?php endforeach; ?></select></label>
            <label class="field"><span>Modelo</span><input name="model_name" value="<?= View::e($currentAgent['model_name'] ?? 'gemini-2.0-flash') ?>" required></label>
            <label class="field"><span>Temperatura</span><input type="number" name="temperature" value="<?= View::e($currentAgent['temperature'] ?? '0.2') ?>" min="0" max="1" step="0.1"></label>
        </div>
        <label class="field"><span>Prompt do sistema</span><textarea name="system_prompt" rows="9" required><?= View::e($currentAgent['system_prompt'] ?? $defaultPrompt) ?></textarea></label>
        <div class="form-actions"><button class="btn btn-primary" type="submit" <?= !$instances ? 'disabled' : '' ?>>Salvar agente e concluir</button></div>
    </form>
</section>

<?php if ($agents): ?>
    <article class="card completion-card">
        <div><span class="eyebrow">Agente configurado</span><h2><?= View::e($agents[0]['name']) ?></h2><p><?= View::e($agents[0]['model_provider']) ?> · <?= View::e($agents[0]['model_name']) ?> · <?= View::e($agents[0]['instance_name'] ?? 'Sem instância') ?></p></div>
        <a class="btn btn-soft" href="<?= View::e(Router::url('/agents')) ?>">Gerenciar agentes</a>
    </article>
<?php endif; ?>
