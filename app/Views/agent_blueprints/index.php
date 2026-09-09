<?php

use App\Core\Csrf;
use App\Core\Router;
use App\Core\View;

/** @var array<int,array<string,mixed>> $niches */
/** @var array<int,array<string,mixed>> $blueprints */
?>

<section class="page-header">
    <div>
        <span class="eyebrow">Arquitetura de agentes</span>
        <h1>Nichos e blueprints</h1>
        <p>Defina o comportamento estrutural por segmento. O Prompt Studio continua responsável pela linguagem; capacidades, triagem, políticas e ações ficam protegidas pelo backend.</p>
    </div>
</section>

<section class="card" style="margin-bottom:18px;">
    <div class="card-header">
        <div>
            <span class="eyebrow">Modelo de segurança</span>
            <h2>Blueprint → cópia por empresa → personalização</h2>
        </div>
    </div>
    <div class="card-body">
        <p style="margin-top:0;">Publicar uma nova versão <strong>não altera empresas em produção automaticamente</strong>. O blueprint é copiado para cada tenant quando aplicado, evitando mudanças globais acidentais.</p>
        <div class="grid grid-4" style="gap:12px;">
            <div class="surface-card"><strong>Prompt Studio</strong><br><small>Como o agente conversa.</small></div>
            <div class="surface-card"><strong>Triagem</strong><br><small>O que precisa ser coletado.</small></div>
            <div class="surface-card"><strong>Policy Engine</strong><br><small>O que pode ou não acontecer.</small></div>
            <div class="surface-card"><strong>Capabilities</strong><br><small>Quais ações o agente pode executar.</small></div>
        </div>
    </div>
</section>

<section class="card" style="margin-bottom:18px;">
    <div class="card-header">
        <div>
            <span class="eyebrow">Catálogo</span>
            <h2>Nichos</h2>
        </div>
    </div>
    <div class="card-body">
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Nicho</th><th>Código</th><th>Blueprints</th><th>Empresas</th><th>Status</th><th>Ajustar</th></tr></thead>
                <tbody>
                <?php foreach ($niches as $niche): ?>
                    <tr>
                        <td><strong><?= View::e((string) $niche['name']) ?></strong><br><small><?= View::e((string) ($niche['description'] ?? '')) ?></small></td>
                        <td><code><?= View::e((string) $niche['code']) ?></code></td>
                        <td><?= (int) ($niche['blueprint_count'] ?? 0) ?></td>
                        <td><?= (int) ($niche['tenant_count'] ?? 0) ?></td>
                        <td><?= (int) ($niche['active'] ?? 0) === 1 ? 'Ativo' : 'Inativo' ?></td>
                        <td>
                            <details>
                                <summary class="btn btn-secondary btn-sm">Editar</summary>
                                <form method="post" action="<?= View::e(Router::url('/agent-blueprints/niche')) ?>" style="margin-top:12px; min-width:320px;">
                                    <?= Csrf::input() ?>
                                    <input type="hidden" name="id" value="<?= (int) $niche['id'] ?>">
                                    <label>Nome<input class="form-control" name="name" value="<?= View::e((string) $niche['name']) ?>" required></label>
                                    <label>Código<input class="form-control" name="code" value="<?= View::e((string) $niche['code']) ?>" required></label>
                                    <label>Descrição<textarea class="form-control" name="description" rows="2"><?= View::e((string) ($niche['description'] ?? '')) ?></textarea></label>
                                    <label>Ordem<input class="form-control" type="number" name="position" value="<?= (int) ($niche['position'] ?? 100) ?>"></label>
                                    <label style="display:flex; gap:8px; align-items:center;"><input type="checkbox" name="active" value="1" <?= (int) ($niche['active'] ?? 0) === 1 ? 'checked' : '' ?>> Ativo</label>
                                    <button class="btn btn-primary btn-sm" type="submit">Salvar nicho</button>
                                </form>
                            </details>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <details style="margin-top:16px;">
            <summary class="btn btn-secondary">Novo nicho</summary>
            <form method="post" action="<?= View::e(Router::url('/agent-blueprints/niche')) ?>" class="grid grid-2" style="gap:12px; margin-top:14px; max-width:900px;">
                <?= Csrf::input() ?>
                <label>Nome<input class="form-control" name="name" required placeholder="Ex.: Imobiliária"></label>
                <label>Código<input class="form-control" name="code" placeholder="imobiliaria"></label>
                <label style="grid-column:1/-1;">Descrição<textarea class="form-control" name="description" rows="2"></textarea></label>
                <label>Ordem<input class="form-control" type="number" name="position" value="100"></label>
                <label style="display:flex; gap:8px; align-items:center;"><input type="checkbox" name="active" value="1" checked> Ativo</label>
                <div><button class="btn btn-primary" type="submit">Criar nicho</button></div>
            </form>
        </details>
    </div>
</section>

<section class="card">
    <div class="card-header">
        <div>
            <span class="eyebrow">Blueprints versionados</span>
            <h2>Fluxos, campos, políticas e capacidades</h2>
        </div>
    </div>
    <div class="card-body">
        <?php if ($blueprints === []): ?>
            <p>Nenhum blueprint cadastrado.</p>
        <?php endif; ?>

        <?php foreach ($blueprints as $blueprint): ?>
            <article class="surface-card" style="margin-bottom:16px; padding:18px;">
                <div style="display:flex; align-items:flex-start; justify-content:space-between; gap:16px; flex-wrap:wrap;">
                    <div>
                        <span class="eyebrow"><?= View::e((string) $blueprint['niche_name']) ?></span>
                        <h3 style="margin:4px 0;"><?= View::e((string) $blueprint['name']) ?></h3>
                        <p style="margin:0;"><?= View::e((string) ($blueprint['description'] ?? '')) ?></p>
                    </div>
                    <div style="text-align:right;">
                        <span class="badge"><?= (int) ($blueprint['active'] ?? 0) === 1 ? 'Ativo' : 'Inativo' ?></span>
                        <div><small>Versão atual: <?= View::e((string) ($blueprint['current_version_label'] ?? 'sem versão')) ?> · <?= (int) ($blueprint['tenant_count'] ?? 0) ?> empresa(s)</small></div>
                    </div>
                </div>

                <div class="grid grid-2" style="gap:14px; margin-top:16px; align-items:start;">
                    <details>
                        <summary class="btn btn-secondary btn-sm">Editar metadados</summary>
                        <form method="post" action="<?= View::e(Router::url('/agent-blueprints/blueprint')) ?>" style="margin-top:12px;">
                            <?= Csrf::input() ?>
                            <input type="hidden" name="id" value="<?= (int) $blueprint['id'] ?>">
                            <label>Nicho
                                <select class="form-control" name="niche_id" required>
                                    <?php foreach ($niches as $niche): ?>
                                        <option value="<?= (int) $niche['id'] ?>" <?= (int) $niche['id'] === (int) $blueprint['niche_id'] ? 'selected' : '' ?>><?= View::e((string) $niche['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label>Nome<input class="form-control" name="name" value="<?= View::e((string) $blueprint['name']) ?>" required></label>
                            <label>Código<input class="form-control" name="code" value="<?= View::e((string) $blueprint['code']) ?>" required></label>
                            <label>Descrição<textarea class="form-control" name="description" rows="3"><?= View::e((string) ($blueprint['description'] ?? '')) ?></textarea></label>
                            <label style="display:flex; gap:8px; align-items:center;"><input type="checkbox" name="active" value="1" <?= (int) ($blueprint['active'] ?? 0) === 1 ? 'checked' : '' ?>> Ativo</label>
                            <button class="btn btn-primary btn-sm" type="submit">Salvar blueprint</button>
                        </form>
                    </details>

                    <details>
                        <summary class="btn btn-primary btn-sm">Publicar nova versão</summary>
                        <form method="post" action="<?= View::e(Router::url('/agent-blueprints/version')) ?>" style="margin-top:12px;">
                            <?= Csrf::input() ?>
                            <input type="hidden" name="blueprint_id" value="<?= (int) $blueprint['id'] ?>">
                            <label>Rótulo da versão<input class="form-control" name="version_label" placeholder="Ex.: 1.1"></label>
                            <label>Orientação complementar ao Prompt Studio<textarea class="form-control" name="prompt_guidance" rows="3"><?= View::e((string) ($blueprint['current_prompt_guidance'] ?? '')) ?></textarea></label>
                            <label>Configuração JSON
                                <textarea class="form-control" name="config_json" rows="22" spellcheck="false" style="font-family:monospace; font-size:12px;" required><?= View::e((string) ($blueprint['current_config_pretty'] ?? '{\n  "interaction_mode": "hybrid",\n  "capabilities": {},\n  "triage_fields": [],\n  "policies": [],\n  "workflow": []\n}')) ?></textarea>
                            </label>
                            <p><small>Uma nova versão não reconfigura tenants existentes. Para atualizar uma empresa, aplique a versão pela configuração da empresa.</small></p>
                            <button class="btn btn-primary" type="submit">Publicar versão</button>
                        </form>
                    </details>
                </div>
            </article>
        <?php endforeach; ?>

        <details style="margin-top:14px;">
            <summary class="btn btn-secondary">Novo blueprint</summary>
            <form method="post" action="<?= View::e(Router::url('/agent-blueprints/blueprint')) ?>" class="grid grid-2" style="gap:12px; margin-top:14px; max-width:900px;">
                <?= Csrf::input() ?>
                <label>Nicho
                    <select class="form-control" name="niche_id" required>
                        <option value="">Selecione</option>
                        <?php foreach ($niches as $niche): ?>
                            <option value="<?= (int) $niche['id'] ?>"><?= View::e((string) $niche['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Nome<input class="form-control" name="name" required placeholder="Ex.: Imobiliária — Qualificação e visita"></label>
                <label>Código<input class="form-control" name="code" placeholder="imobiliaria-visita-v1"></label>
                <label style="grid-column:1/-1;">Descrição<textarea class="form-control" name="description" rows="2"></textarea></label>
                <label style="display:flex; gap:8px; align-items:center;"><input type="checkbox" name="active" value="1" checked> Ativo</label>
                <div><button class="btn btn-primary" type="submit">Criar blueprint</button></div>
            </form>
        </details>
    </div>
</section>
