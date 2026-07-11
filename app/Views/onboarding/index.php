<?php

use App\Core\Csrf;
use App\Core\Router;
use App\Core\View;

$step = (int) ($company['onboarding_step'] ?? 1);
$completed = !empty($company['onboarding_completed_at']);
$segment = trim((string) ($company['segment'] ?? ($builder['segment'] ?? 'atendimento')));
$currentAgent = $agents[0] ?? [];
$builder = is_array($builder ?? null) ? $builder : [];
$generatedPrompt = (string) ($generatedPrompt ?? '');
$instanceConnected = false;
foreach ($instances as $instance) {
    if (($instance['status'] ?? '') === 'connected') {
        $instanceConnected = true;
        break;
    }
}
$promptReady = $generatedPrompt !== '' || !empty($currentAgent);
$integrationStatus = $completed ? 'Aguardando revisão técnica da RS Connect' : 'Será liberada após concluir o assistente';
$val = static fn (string $key, string $fallback = ''): string => (string) ($builder[$key] ?? $fallback);
?>
<div class="hero-card onboarding-hero <?= $completed ? 'hero-complete' : '' ?>">
    <div>
        <span class="eyebrow light">Implantação guiada</span>
        <h2><?= $completed ? 'Configuração do cliente concluída.' : 'Configure sua empresa sem depender do suporte.' ?></h2>
        <p><?= $completed
            ? 'Você pode revisar os dados, reconectar o WhatsApp e ajustar o prompt de atendimento sempre que necessário. Fluxos n8n, credenciais avançadas e integrações externas seguem sob responsabilidade da RS Connect.'
            : 'Complete os dados da empresa, conecte o WhatsApp e monte o prompt do assistente com um formulário guiado.' ?></p>
    </div>
    <span class="hero-badge"><?= $completed ? 'Cliente pronto' : 'Etapa ' . min($step, 4) . ' de 4' ?></span>
</div>

<section class="onboarding-status-grid">
    <article class="status-card <?= $step >= 2 ? 'is-ok' : 'is-pending' ?>">
        <span>Empresa</span>
        <strong><?= $step >= 2 ? 'Dados preenchidos' : 'Pendente' ?></strong>
        <small>Identidade, segmento e contato comercial.</small>
    </article>
    <article class="status-card <?= $instances ? ($instanceConnected ? 'is-ok' : 'is-warning') : 'is-pending' ?>">
        <span>WhatsApp</span>
        <strong><?= $instances ? ($instanceConnected ? 'Conectado' : 'Instância criada') : 'Pendente' ?></strong>
        <small>Conexão Evolution vinculada à empresa.</small>
    </article>
    <article class="status-card <?= $promptReady ? 'is-ok' : 'is-pending' ?>">
        <span>Assistente</span>
        <strong><?= $promptReady ? 'Prompt configurado' : 'Pendente' ?></strong>
        <small>Atendimento guiado pelo cliente.</small>
    </article>
    <article class="status-card is-rs">
        <span>Integrações RS</span>
        <strong><?= View::e($integrationStatus) ?></strong>
        <small>n8n, credenciais e fluxos externos são configurados pela RS Connect.</small>
    </article>
</section>

<div class="onboarding-progress" aria-label="Progresso do onboarding">
    <div class="progress-item <?= $step >= 2 ? 'is-complete' : 'is-current' ?>"><span>1</span><div><strong>Empresa</strong><small>Dados principais</small></div></div>
    <i></i>
    <div class="progress-item <?= $step >= 3 ? 'is-complete' : ($step === 2 ? 'is-current' : '') ?>"><span>2</span><div><strong>WhatsApp</strong><small>Instância vinculada</small></div></div>
    <i></i>
    <div class="progress-item <?= $step >= 4 ? 'is-complete' : ($step === 3 ? 'is-current' : '') ?>"><span>3</span><div><strong>Assistente</strong><small>Prompt guiado</small></div></div>
    <i></i>
    <div class="progress-item is-locked"><span>4</span><div><strong>RS Connect</strong><small>n8n e integrações</small></div></div>
</div>

<section class="wizard-section <?= $step === 1 ? 'is-current' : '' ?>">
    <div class="wizard-heading"><span class="wizard-number">1</span><div><span class="eyebrow">Dados empresariais</span><h2>Identifique a operação</h2><p>Essas informações ajudam o assistente a entender o contexto da empresa.</p></div><span class="badge <?= $step >= 2 ? 'badge-active' : 'badge-pending' ?>"><?= $step >= 2 ? 'Concluída' : 'Pendente' ?></span></div>
    <form class="card wizard-card" method="post" action="<?= View::e(Router::url('/onboarding/company')) ?>">
        <?= Csrf::input() ?>
        <div class="form-grid two">
            <label class="field"><span>Nome de exibição</span><input name="name" value="<?= View::e($company['name'] ?? '') ?>" required></label>
            <label class="field"><span>Razão social</span><input name="legal_name" value="<?= View::e($company['legal_name'] ?? '') ?>"></label>
            <label class="field"><span>CNPJ/CPF</span><input name="document" value="<?= View::e($company['document'] ?? '') ?>"></label>
            <label class="field"><span>Segmento</span><input name="segment" value="<?= View::e($company['segment'] ?? '') ?>" placeholder="Ex.: clínica, imobiliária, barbearia" required></label>
            <label class="field"><span>E-mail comercial</span><input type="email" name="email" value="<?= View::e($company['email'] ?? '') ?>"></label>
            <label class="field"><span>Telefone</span><input name="phone" value="<?= View::e($company['phone'] ?? '') ?>"></label>
        </div>
        <label class="field"><span>Site</span><input type="url" name="website" value="<?= View::e($company['website'] ?? '') ?>" placeholder="https://empresa.com.br"></label>
        <div class="form-actions"><button class="btn btn-primary" type="submit"><?= $step >= 2 ? 'Atualizar dados' : 'Concluir etapa 1' ?></button></div>
    </form>
</section>

<section class="wizard-section <?= $step === 2 ? 'is-current' : '' ?>">
    <div class="wizard-heading"><span class="wizard-number">2</span><div><span class="eyebrow">Canal de atendimento</span><h2>Conecte o WhatsApp</h2><p>O cliente pode escanear o QR Code na tela de Instâncias depois que a RS cadastrar ou vincular a instância.</p></div><span class="badge <?= $step >= 3 ? 'badge-active' : 'badge-pending' ?>"><?= $step >= 3 ? 'Concluída' : 'Pendente' ?></span></div>
    <div class="content-grid two-columns wizard-columns">
        <form class="card wizard-card" method="post" action="<?= View::e(Router::url('/onboarding/instance')) ?>">
            <?= Csrf::input() ?>
            <input type="hidden" name="mode" value="existing">
            <span class="eyebrow">Instância da empresa</span><h3>Selecionar WhatsApp cadastrado</h3>
            <label class="field"><span>Instância</span><select name="instance_id" required <?= !$instances ? 'disabled' : '' ?>><option value="">Selecione</option><?php foreach ($instances as $instance): ?><option value="<?= (int) $instance['id'] ?>" <?= (int) $instance['is_default'] === 1 ? 'selected' : '' ?>><?= View::e($instance['name']) ?> — <?= View::e(ucfirst((string) $instance['status'])) ?></option><?php endforeach; ?></select></label>
            <button class="btn btn-primary btn-block" type="submit" <?= !$instances ? 'disabled' : '' ?>>Definir como padrão</button>
            <?php if (!$instances): ?><p class="field-hint">Ainda não há instância vinculada. A RS Connect pode cadastrar a instância e depois o cliente gera o QR Code na tela de Instâncias.</p><?php endif; ?>
        </form>

        <article class="card wizard-card locked-card">
            <span class="eyebrow">Responsabilidade RS Connect</span><h3>Cadastro técnico da Evolution</h3>
            <p>A criação da instância, URL base, chave global, webhook Evolution e fluxos n8n devem ficar no painel RS. Assim o cliente ganha autonomia para conectar o WhatsApp sem acessar configurações sensíveis.</p>
            <a class="btn btn-soft btn-block" href="<?= View::e(Router::url('/instances')) ?>">Abrir tela de Instâncias</a>
        </article>
    </div>
</section>

<section class="wizard-section <?= $step >= 3 ? 'is-current' : '' ?>">
    <div class="wizard-heading"><span class="wizard-number">3</span><div><span class="eyebrow">Assistente do cliente</span><h2>Construtor de prompt de atendimento</h2><p>O cliente responde um formulário simples e o RS Connect monta o prompt base da empresa.</p></div><span class="badge <?= $completed ? 'badge-active' : 'badge-pending' ?>"><?= $completed ? 'Concluída' : 'Pendente' ?></span></div>
    <form class="card wizard-card prompt-builder-card" method="post" action="<?= View::e(Router::url('/onboarding/agent')) ?>" data-prompt-builder>
        <?= Csrf::input() ?>
        <input type="hidden" name="name" value="<?= View::e($val('assistant_name', $currentAgent['name'] ?? '')) ?>">
        <div class="prompt-layout">
            <div class="prompt-form-stack">
                <div class="form-grid two">
                    <label class="field"><span>Instância vinculada</span><select name="instance_id" required <?= !$instances ? 'disabled' : '' ?>><option value="">Selecione</option><?php foreach ($instances as $instance): ?><option value="<?= (int) $instance['id'] ?>" <?= (int) $instance['is_default'] === 1 ? 'selected' : '' ?>><?= View::e($instance['name']) ?></option><?php endforeach; ?></select></label>
                    <label class="field"><span>Nome do assistente</span><input name="assistant_name" data-prompt-field="assistant_name" value="<?= View::e($val('assistant_name', $currentAgent['name'] ?? ('Assistente ' . ($company['name'] ?? '')))) ?>" required></label>
                    <label class="field"><span>Segmento</span><input name="segment" data-prompt-field="segment" value="<?= View::e($val('segment', $segment)) ?>" required></label>
                    <label class="field"><span>Tom de atendimento</span><select name="tone" data-prompt-field="tone"><option <?= $val('tone') === 'Profissional, claro e acolhedor' ? 'selected' : '' ?>>Profissional, claro e acolhedor</option><option <?= $val('tone') === 'Comercial, direto e consultivo' ? 'selected' : '' ?>>Comercial, direto e consultivo</option><option <?= $val('tone') === 'Humanizado, calmo e cuidadoso' ? 'selected' : '' ?>>Humanizado, calmo e cuidadoso</option><option <?= $val('tone') === 'Técnico, objetivo e seguro' ? 'selected' : '' ?>>Técnico, objetivo e seguro</option></select></label>
                </div>

                <label class="field"><span>Objetivo principal do assistente</span><input name="main_goal" data-prompt-field="main_goal" value="<?= View::e($val('main_goal')) ?>" placeholder="Ex.: atender dúvidas, qualificar leads e encaminhar para humano"></label>
                <label class="field"><span>Público atendido</span><input name="audience" data-prompt-field="audience" value="<?= View::e($val('audience')) ?>" placeholder="Ex.: novos clientes, pacientes, alunos, lojistas"></label>
                <label class="field"><span>Resumo do negócio</span><textarea name="business_summary" rows="3" data-prompt-field="business_summary" placeholder="Explique em poucas linhas o que a empresa faz."><?= View::e($val('business_summary')) ?></textarea></label>
                <label class="field"><span>Produtos ou serviços</span><textarea name="products_services" rows="4" data-prompt-field="products_services" placeholder="Liste os principais serviços, pacotes, produtos ou especialidades."><?= View::e($val('products_services')) ?></textarea></label>
                <label class="field"><span>Região, canais ou modalidade de atendimento</span><textarea name="service_area" rows="2" data-prompt-field="service_area" placeholder="Ex.: presencial em Muriaé, online pelo Google Meet, atendimento em todo Brasil."><?= View::e($val('service_area')) ?></textarea></label>
                <label class="field"><span>Preços, condições e políticas autorizadas</span><textarea name="prices_policy" rows="3" data-prompt-field="prices_policy" placeholder="Preencha apenas o que o assistente pode informar. Se não houver, deixe claro que deve encaminhar para humano."><?= View::e($val('prices_policy')) ?></textarea></label>
                <label class="field"><span>Perguntas frequentes e respostas</span><textarea name="common_questions" rows="4" data-prompt-field="common_questions" placeholder="Ex.: horários, formas de pagamento, localização, garantia, entrega."><?= View::e($val('common_questions')) ?></textarea></label>
                <label class="field"><span>Informações que o assistente deve coletar</span><input name="collect_fields" data-prompt-field="collect_fields" value="<?= View::e($val('collect_fields')) ?>"></label>
                <div class="form-grid two">
                    <label class="field"><span>Palavras para atendimento humano</span><input name="handoff_keywords" data-prompt-field="handoff_keywords" value="<?= View::e($val('handoff_keywords')) ?>"></label>
                    <label class="field"><span>Mensagem de transferência</span><input name="human_handoff_message" data-prompt-field="human_handoff_message" value="<?= View::e($val('human_handoff_message')) ?>"></label>
                </div>
                <label class="field"><span>Mensagem fora do horário</span><input name="after_hours_message" data-prompt-field="after_hours_message" value="<?= View::e($val('after_hours_message')) ?>"></label>
                <label class="field"><span>Regras, limites e assuntos proibidos</span><textarea name="restrictions" rows="3" data-prompt-field="restrictions"><?= View::e($val('restrictions')) ?></textarea></label>
                <label class="field"><span>Contexto adicional</span><textarea name="extra_context" rows="3" data-prompt-field="extra_context" placeholder="Informações extras que ajudam o assistente a responder melhor."><?= View::e($val('extra_context')) ?></textarea></label>
            </div>

            <aside class="prompt-preview-panel">
                <div class="prompt-preview-head">
                    <div><span class="eyebrow">Prompt gerado</span><h3>Base de atendimento</h3><p>Revise antes de salvar. O texto pode ser editado manualmente.</p></div>
                    <button class="btn btn-soft" type="button" data-generate-prompt>Gerar prompt</button>
                </div>
                <label class="field"><span>Prompt final do sistema</span><textarea name="system_prompt" rows="24" data-prompt-output required><?= View::e($generatedPrompt) ?></textarea></label>
                <div class="prompt-rules-box">
                    <strong>O cliente configura</strong>
                    <span>Tom, informações do negócio, perguntas frequentes e regras do atendimento.</span>
                    <strong>A RS Connect configura</strong>
                    <span>Credenciais de IA, n8n, webhooks, gateways, fluxos externos e integrações sensíveis.</span>
                </div>
                <div class="form-actions"><button class="btn btn-primary btn-block" type="submit" <?= !$instances ? 'disabled' : '' ?>>Salvar assistente</button></div>
            </aside>
        </div>
    </form>
</section>

<section class="wizard-section is-current">
    <div class="wizard-heading"><span class="wizard-number">4</span><div><span class="eyebrow">Operação RS Connect</span><h2>Integrações técnicas ficam com a RS</h2><p>O cliente enxerga status e usa o sistema, mas não precisa acessar URLs, tokens e fluxos sensíveis.</p></div><span class="badge badge-info">Restrito</span></div>
    <div class="content-grid two-columns wizard-columns">
        <article class="card locked-card">
            <h3>Fluxos n8n</h3>
            <p>A RS Connect cadastra e mantém os fluxos por empresa, como agenda, cobrança, Google Sheets, mensagens e callbacks.</p>
            <p class="field-hint">Isso evita que um cliente altere automações de outro cliente ou exponha tokens internos.</p>
        </article>
        <article class="card locked-card">
            <h3>Credenciais e provedores</h3>
            <p>Chaves de IA, Evolution, gateway de pagamento e integrações externas continuam criptografadas e gerenciadas no painel administrativo.</p>
            <p class="field-hint">O cliente só personaliza o comportamento do assistente e acompanha a operação.</p>
        </article>
    </div>
</section>

<?php if ($agents): ?>
    <article class="card completion-card">
        <div><span class="eyebrow">Assistente configurado</span><h2><?= View::e($agents[0]['name']) ?></h2><p><?= View::e($agents[0]['model_provider']) ?> · <?= View::e($agents[0]['model_name']) ?> · <?= View::e($agents[0]['instance_name'] ?? 'Sem instância') ?></p></div>
        <a class="btn btn-soft" href="<?= View::e(Router::url('/agents')) ?>">Gerenciar agentes</a>
    </article>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.querySelector('[data-prompt-builder]');
    if (!form) return;
    const output = form.querySelector('[data-prompt-output]');
    const companyName = <?= json_encode((string) ($company['name'] ?? 'empresa'), JSON_UNESCAPED_UNICODE) ?>;

    function field(name) {
        const el = form.querySelector('[data-prompt-field="' + name + '"]');
        return el ? String(el.value || '').trim() : '';
    }

    function addSection(lines, label, value) {
        if (value) lines.push(label + ': ' + value + '.');
    }

    function generatePrompt() {
        const assistant = field('assistant_name') || 'Assistente virtual';
        const segment = field('segment') || 'atendimento';
        const lines = [];
        lines.push('Você é ' + assistant + ', assistente virtual de atendimento da empresa ' + companyName + '.');
        lines.push('A empresa atua no segmento de ' + segment + '.');
        addSection(lines, 'Objetivo principal', field('main_goal'));
        addSection(lines, 'Tom de atendimento', field('tone'));
        addSection(lines, 'Público atendido', field('audience'));
        addSection(lines, 'Resumo do negócio', field('business_summary'));
        addSection(lines, 'Produtos e serviços', field('products_services'));
        addSection(lines, 'Região ou modalidade de atendimento', field('service_area'));
        addSection(lines, 'Preços, condições e políticas comerciais', field('prices_policy'));
        addSection(lines, 'Perguntas frequentes e respostas autorizadas', field('common_questions'));
        addSection(lines, 'Informações que devem ser coletadas', field('collect_fields'));
        addSection(lines, 'Palavras ou situações para transferir ao humano', field('handoff_keywords'));
        addSection(lines, 'Mensagem de transferência para humano', field('human_handoff_message'));
        addSection(lines, 'Mensagem fora do horário', field('after_hours_message'));
        addSection(lines, 'Regras e restrições', field('restrictions'));
        addSection(lines, 'Contexto adicional', field('extra_context'));
        lines.push('Regras de conversa: responda em português do Brasil, de forma objetiva, educada e natural. Faça uma pergunta por vez quando precisar coletar dados.');
        lines.push('Não confirme agendamentos, pagamentos, disponibilidade, descontos ou condições que não estejam claramente informados.');
        lines.push('Quando não tiver segurança, quando o cliente pedir atendimento humano ou quando o assunto exigir decisão da empresa, encaminhe para uma pessoa da equipe.');
        lines.push('Não diga que é uma inteligência artificial. Apresente-se como assistente virtual de atendimento.');
        output.value = lines.join('\n\n');
    }

    const button = form.querySelector('[data-generate-prompt]');
    if (button) button.addEventListener('click', generatePrompt);
    form.querySelectorAll('[data-prompt-field]').forEach(function (el) {
        el.addEventListener('change', function () {
            if (!output.value.trim()) generatePrompt();
        });
    });
});
</script>
