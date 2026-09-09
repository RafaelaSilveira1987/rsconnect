<?php

use App\Core\Csrf;
use App\Core\Router;
use App\Core\View;

/** @var array<int,array<string,mixed>> $niches */
/** @var array<int,array<string,mixed>> $blueprints */

$modeLabels = [
    'hybrid' => 'Natural com regras',
    'form' => 'Perguntas fixas',
    'prompt' => 'Prompt Studio com regras',
];
?>

<div class="agent-models-page">
    <section class="agent-models-hero">
        <div class="agent-models-hero-copy">
            <span class="eyebrow">Configuração por segmento</span>
            <h1>Modelos de atendimento</h1>
            <p>Crie um padrão para cada tipo de negócio. Ao escolher o segmento de uma empresa, o RS Connect já prepara as perguntas, regras de segurança e ações que o assistente poderá usar.</p>
        </div>
        <div class="agent-models-hero-badge">
            <span>Como funciona</span>
            <strong>Segmento → modelo → empresa</strong>
            <small>Cada empresa recebe uma cópia própria e pode ser personalizada sem afetar as demais.</small>
        </div>
    </section>

    <section class="agent-model-concepts" aria-label="Partes do modelo de atendimento">
        <article>
            <span class="agent-model-concept-icon">01</span>
            <div><strong>Jeito de falar</strong><small>O Prompt Studio cuida do tom, personalidade e forma de responder.</small></div>
        </article>
        <article>
            <span class="agent-model-concept-icon">02</span>
            <div><strong>Informações a coletar</strong><small>Define o que o assistente precisa saber antes de seguir.</small></div>
        </article>
        <article>
            <span class="agent-model-concept-icon">03</span>
            <div><strong>Regras de segurança</strong><small>Impede atendimento, agenda ou confirmação quando alguma regra não for atendida.</small></div>
        </article>
        <article>
            <span class="agent-model-concept-icon">04</span>
            <div><strong>Ações permitidas</strong><small>Controla o que o assistente pode consultar, reservar, confirmar ou encaminhar.</small></div>
        </article>
    </section>

    <section class="card agent-model-section">
        <div class="card-header agent-model-section-head">
            <div>
                <span class="eyebrow">Segmentos</span>
                <h2>Tipos de empresa</h2>
                <p>Use os segmentos para organizar os modelos e facilitar a configuração de novos clientes.</p>
            </div>
            <details class="agent-model-add-details">
                <summary class="btn btn-primary">Novo segmento</summary>
                <form method="post" action="<?= View::e(Router::url('/agent-blueprints/niche')) ?>" class="agent-model-inline-form">
                    <?= Csrf::input() ?>
                    <label class="field"><span>Nome do segmento</span><input class="form-control" name="name" required placeholder="Ex.: Imobiliária"></label>
                    <label class="field"><span>Descrição</span><textarea class="form-control" name="description" rows="2" placeholder="Explique em poucas palavras para que tipo de empresa este segmento serve."></textarea></label>
                    <details class="agent-model-technical-details">
                        <summary>Configuração técnica</summary>
                        <div class="form-grid two">
                            <label class="field"><span>Código interno</span><input class="form-control" name="code" placeholder="imobiliaria"></label>
                            <label class="field"><span>Ordem de exibição</span><input class="form-control" type="number" name="position" value="100"></label>
                        </div>
                    </details>
                    <label class="switch-inline"><input type="checkbox" name="active" value="1" checked> Disponível para uso</label>
                    <button class="btn btn-primary" type="submit">Criar segmento</button>
                </form>
            </details>
        </div>
        <div class="card-body">
            <div class="agent-niche-grid">
                <?php foreach ($niches as $niche): ?>
                    <article class="agent-niche-card <?= (int) ($niche['active'] ?? 0) === 1 ? '' : 'is-inactive' ?>">
                        <div class="agent-niche-card-top">
                            <div>
                                <strong><?= View::e((string) $niche['name']) ?></strong>
                                <small><?= View::e((string) (($niche['description'] ?? '') ?: 'Sem descrição.')) ?></small>
                            </div>
                            <span class="badge <?= (int) ($niche['active'] ?? 0) === 1 ? 'badge-active' : 'badge-pending' ?>"><?= (int) ($niche['active'] ?? 0) === 1 ? 'Disponível' : 'Desativado' ?></span>
                        </div>
                        <div class="agent-niche-stats">
                            <div><strong><?= (int) ($niche['blueprint_count'] ?? 0) ?></strong><span>modelo(s)</span></div>
                            <div><strong><?= (int) ($niche['tenant_count'] ?? 0) ?></strong><span>empresa(s)</span></div>
                        </div>
                        <details class="agent-model-edit-details">
                            <summary>Ajustar segmento</summary>
                            <form method="post" action="<?= View::e(Router::url('/agent-blueprints/niche')) ?>" class="agent-model-inline-form compact">
                                <?= Csrf::input() ?>
                                <input type="hidden" name="id" value="<?= (int) $niche['id'] ?>">
                                <label class="field"><span>Nome</span><input class="form-control" name="name" value="<?= View::e((string) $niche['name']) ?>" required></label>
                                <label class="field"><span>Descrição</span><textarea class="form-control" name="description" rows="2"><?= View::e((string) ($niche['description'] ?? '')) ?></textarea></label>
                                <details class="agent-model-technical-details">
                                    <summary>Configuração técnica</summary>
                                    <div class="form-grid two">
                                        <label class="field"><span>Código interno</span><input class="form-control" name="code" value="<?= View::e((string) $niche['code']) ?>" required></label>
                                        <label class="field"><span>Ordem</span><input class="form-control" type="number" name="position" value="<?= (int) ($niche['position'] ?? 100) ?>"></label>
                                    </div>
                                </details>
                                <label class="switch-inline"><input type="checkbox" name="active" value="1" <?= (int) ($niche['active'] ?? 0) === 1 ? 'checked' : '' ?>> Disponível para uso</label>
                                <button class="btn btn-primary btn-sm" type="submit">Salvar alterações</button>
                            </form>
                        </details>
                    </article>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <section class="card agent-model-section">
        <div class="card-header agent-model-section-head">
            <div>
                <span class="eyebrow">Padrões prontos</span>
                <h2>Modelos de atendimento por segmento</h2>
                <p>O modelo é o ponto de partida. Quando aplicado a uma empresa, ela recebe uma cópia própria das regras.</p>
            </div>
            <details class="agent-model-add-details">
                <summary class="btn btn-secondary">Novo modelo</summary>
                <form method="post" action="<?= View::e(Router::url('/agent-blueprints/blueprint')) ?>" class="agent-model-inline-form">
                    <?= Csrf::input() ?>
                    <label class="field"><span>Segmento</span>
                        <select class="form-control" name="niche_id" required>
                            <option value="">Selecione</option>
                            <?php foreach ($niches as $niche): ?>
                                <option value="<?= (int) $niche['id'] ?>"><?= View::e((string) $niche['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="field"><span>Nome do modelo</span><input class="form-control" name="name" required placeholder="Ex.: Atendimento inicial com aprovação"></label>
                    <label class="field"><span>Descrição</span><textarea class="form-control" name="description" rows="2" placeholder="Resuma como este modelo deve funcionar."></textarea></label>
                    <details class="agent-model-technical-details">
                        <summary>Configuração técnica</summary>
                        <label class="field"><span>Código interno</span><input class="form-control" name="code" placeholder="atendimento-inicial-v1"></label>
                    </details>
                    <label class="switch-inline"><input type="checkbox" name="active" value="1" checked> Disponível para uso</label>
                    <button class="btn btn-primary" type="submit">Criar modelo</button>
                </form>
            </details>
        </div>
        <div class="card-body">
            <?php if ($blueprints === []): ?>
                <div class="message-info"><strong>Nenhum modelo criado</strong><span>Crie o primeiro modelo para começar a automatizar a configuração por segmento.</span></div>
            <?php endif; ?>

            <div class="agent-blueprint-grid">
                <?php foreach ($blueprints as $blueprint): ?>
                    <?php
                    $config = is_array($blueprint['current_config_decoded'] ?? null) ? $blueprint['current_config_decoded'] : [];
                    $capabilities = is_array($config['capabilities'] ?? null) ? $config['capabilities'] : [];
                    $fields = is_array($config['triage_fields'] ?? null) ? $config['triage_fields'] : [];
                    $policies = is_array($config['policies'] ?? null) ? $config['policies'] : [];
                    $workflow = is_array($config['workflow'] ?? null) ? $config['workflow'] : [];
                    $mode = (string) ($config['interaction_mode'] ?? 'hybrid');
                    ?>
                    <article class="agent-blueprint-card <?= (int) ($blueprint['active'] ?? 0) === 1 ? '' : 'is-inactive' ?>">
                        <div class="agent-blueprint-card-head">
                            <div>
                                <span class="agent-model-segment-pill"><?= View::e((string) $blueprint['niche_name']) ?></span>
                                <h3><?= View::e((string) $blueprint['name']) ?></h3>
                                <p><?= View::e((string) (($blueprint['description'] ?? '') ?: 'Sem descrição.')) ?></p>
                            </div>
                            <span class="badge <?= (int) ($blueprint['active'] ?? 0) === 1 ? 'badge-active' : 'badge-pending' ?>"><?= (int) ($blueprint['active'] ?? 0) === 1 ? 'Disponível' : 'Desativado' ?></span>
                        </div>

                        <div class="agent-blueprint-summary">
                            <div><strong><?= count($fields) ?></strong><span>informações a coletar</span></div>
                            <div><strong><?= count($policies) ?></strong><span>regras</span></div>
                            <div><strong><?= count($workflow) ?></strong><span>etapas</span></div>
                            <div><strong><?= View::e($modeLabels[$mode] ?? 'Personalizado') ?></strong><span>forma de conversar</span></div>
                        </div>

                        <div class="agent-blueprint-meta-row">
                            <span>Versão <?= View::e((string) ($blueprint['current_version_label'] ?? 'ainda não publicada')) ?></span>
                            <span><?= (int) ($blueprint['tenant_count'] ?? 0) ?> empresa(s) usando</span>
                            <?php if (!empty($capabilities['calendar.human_approval'])): ?><span>Aprovação da equipe</span><?php endif; ?>
                            <?php if (!empty($capabilities['calendar.confirm'])): ?><span>Confirmação automática</span><?php endif; ?>
                        </div>

                        <div class="agent-blueprint-actions">
                            <details class="agent-model-edit-details">
                                <summary>Ajustar informações</summary>
                                <form method="post" action="<?= View::e(Router::url('/agent-blueprints/blueprint')) ?>" class="agent-model-inline-form compact">
                                    <?= Csrf::input() ?>
                                    <input type="hidden" name="id" value="<?= (int) $blueprint['id'] ?>">
                                    <label class="field"><span>Segmento</span>
                                        <select class="form-control" name="niche_id" required>
                                            <?php foreach ($niches as $niche): ?>
                                                <option value="<?= (int) $niche['id'] ?>" <?= (int) $niche['id'] === (int) $blueprint['niche_id'] ? 'selected' : '' ?>><?= View::e((string) $niche['name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </label>
                                    <label class="field"><span>Nome do modelo</span><input class="form-control" name="name" value="<?= View::e((string) $blueprint['name']) ?>" required></label>
                                    <label class="field"><span>Descrição</span><textarea class="form-control" name="description" rows="3"><?= View::e((string) ($blueprint['description'] ?? '')) ?></textarea></label>
                                    <details class="agent-model-technical-details">
                                        <summary>Configuração técnica</summary>
                                        <label class="field"><span>Código interno</span><input class="form-control" name="code" value="<?= View::e((string) $blueprint['code']) ?>" required></label>
                                    </details>
                                    <label class="switch-inline"><input type="checkbox" name="active" value="1" <?= (int) ($blueprint['active'] ?? 0) === 1 ? 'checked' : '' ?>> Disponível para uso</label>
                                    <button class="btn btn-primary btn-sm" type="submit">Salvar alterações</button>
                                </form>
                            </details>

                            <details class="agent-model-edit-details agent-model-version-details">
                                <summary>Criar nova versão</summary>
                                <form method="post" action="<?= View::e(Router::url('/agent-blueprints/version')) ?>" class="agent-model-inline-form compact">
                                    <?= Csrf::input() ?>
                                    <input type="hidden" name="blueprint_id" value="<?= (int) $blueprint['id'] ?>">
                                    <label class="field"><span>Nome da versão</span><input class="form-control" name="version_label" placeholder="Ex.: 1.1"></label>
                                    <label class="field"><span>Orientação para o jeito de conversar</span><textarea class="form-control" name="prompt_guidance" rows="3" placeholder="Regras complementares para o Prompt Studio."><?= View::e((string) ($blueprint['current_prompt_guidance'] ?? '')) ?></textarea><small>Use este campo para tom e condução da conversa. As regras de segurança continuam protegidas pelo sistema.</small></label>
                                    <details class="agent-model-technical-details">
                                        <summary>Configuração avançada do modelo</summary>
                                        <p class="agent-model-technical-warning">Esta área é técnica. Altere somente se souber exatamente o efeito da mudança.</p>
                                        <label class="field"><span>Configuração interna (JSON)</span>
                                            <textarea class="form-control agent-model-json" name="config_json" rows="22" spellcheck="false" required><?= View::e((string) ($blueprint['current_config_pretty'] ?? '{\n  "interaction_mode": "hybrid",\n  "capabilities": {},\n  "triage_fields": [],\n  "policies": [],\n  "workflow": []\n}')) ?></textarea>
                                        </label>
                                    </details>
                                    <div class="message-info"><strong>Empresas atuais ficam protegidas</strong><span>Publicar uma nova versão não muda automaticamente as empresas que já estão usando este modelo.</span></div>
                                    <button class="btn btn-primary" type="submit">Publicar nova versão</button>
                                </form>
                            </details>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
</div>
