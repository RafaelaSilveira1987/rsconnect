<?php

use App\Core\Csrf;
use App\Core\Router;
use App\Core\View;

$totalCredentials = count($credentials);
$activeCredentials = count(array_filter($credentials, static fn (array $credential): bool => ($credential['status'] ?? '') === 'active'));
$coveredTenants = count(array_unique(array_map(static fn (array $credential): int => (int) ($credential['tenant_id'] ?? 0), $credentials)));
$agentCredentials = count(array_filter($credentials, static fn (array $credential): bool => (int) ($credential['agent_id'] ?? 0) > 0));
?>

<section class="ai-credentials-hero">
    <div>
        <span class="eyebrow">Administração RS Connect</span>
        <h2>Credenciais de inteligência artificial</h2>
        <p>Organize as chaves de acesso usadas pelos assistentes de cada empresa sem expor informações sensíveis.</p>
    </div>
    <button class="btn btn-primary" type="button" data-ai-credential-open="new" data-toggle-panel="ai-credential-drawer">
        Nova credencial
    </button>
</section>

<section class="ai-credential-summary" aria-label="Resumo das credenciais">
    <article>
        <span>Total cadastrado</span>
        <strong><?= $totalCredentials ?></strong>
        <small>credencial(is) protegida(s)</small>
    </article>
    <article class="is-success">
        <span>Ativas</span>
        <strong><?= $activeCredentials ?></strong>
        <small>disponíveis para uso</small>
    </article>
    <article class="is-blue">
        <span>Empresas atendidas</span>
        <strong><?= $coveredTenants ?></strong>
        <small>com credencial própria</small>
    </article>
    <article class="is-purple">
        <span>Uso individual</span>
        <strong><?= $agentCredentials ?></strong>
        <small>vinculada(s) a um assistente</small>
    </article>
</section>

<section class="card ai-credentials-panel">
    <div class="section-heading ai-credentials-heading">
        <div>
            <span class="eyebrow">Acessos configurados</span>
            <h2>Credenciais por empresa</h2>
            <p>Use os filtros para localizar uma empresa, provedor ou situação.</p>
        </div>
        <span class="badge" data-ai-credential-visible-count><?= $totalCredentials ?> registro(s)</span>
    </div>

    <div class="ai-credential-filters" data-ai-credential-filters>
        <label class="field ai-credential-search">
            <span>Buscar</span>
            <input type="search" placeholder="Empresa, assistente ou nome da credencial" data-ai-credential-search>
        </label>
        <label class="field">
            <span>Provedor</span>
            <select data-ai-credential-provider-filter>
                <option value="">Todos</option>
                <option value="openai">OpenAI</option>
                <option value="google">Google Gemini</option>
                <option value="custom">Personalizado</option>
            </select>
        </label>
        <label class="field">
            <span>Situação</span>
            <select data-ai-credential-status-filter>
                <option value="">Todas</option>
                <option value="active">Ativas</option>
                <option value="inactive">Inativas</option>
            </select>
        </label>
        <button class="btn btn-quiet" type="button" data-ai-credential-clear>Limpar</button>
    </div>

    <div class="ai-credential-list" data-ai-credential-list>
        <?php foreach ($credentials as $credential): ?>
            <?php
            $searchText = strtolower(trim(implode(' ', [
                (string) ($credential['tenant_name'] ?? ''),
                (string) ($credential['agent_name'] ?? ''),
                (string) ($credential['label'] ?? ''),
                (string) ($credential['provider'] ?? ''),
                (string) ($credential['default_model'] ?? ''),
            ])));
            $providerLabel = match ($credential['provider']) {
                'openai' => 'OpenAI',
                'google' => 'Google Gemini',
                default => 'Personalizado',
            };
            $scopeLabel = $credential['agent_name']
                ? 'Assistente: ' . (string) $credential['agent_name']
                : 'Toda a empresa';
            ?>
            <article
                class="ai-credential-card"
                data-ai-credential-card
                data-search="<?= View::e($searchText) ?>"
                data-provider="<?= View::e((string) $credential['provider']) ?>"
                data-status="<?= View::e((string) $credential['status']) ?>"
            >
                <div class="ai-credential-card-main">
                    <span class="ai-credential-company-mark" aria-hidden="true">
                        <?= View::e(strtoupper(substr((string) $credential['tenant_name'], 0, 2))) ?>
                    </span>
                    <div class="ai-credential-card-copy">
                        <div class="ai-credential-card-title">
                            <div>
                                <h3><?= View::e((string) $credential['tenant_name']) ?></h3>
                                <p><?= View::e((string) $credential['label']) ?></p>
                            </div>
                            <div class="ai-credential-card-badges">
                                <span class="badge badge-<?= View::e((string) $credential['status']) ?>">
                                    <?= $credential['status'] === 'active' ? 'Ativa' : 'Inativa' ?>
                                </span>
                                <?php if ((int) $credential['is_default'] === 1): ?>
                                    <span class="badge">Padrão</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="ai-credential-scope">
                            <strong><?= View::e($scopeLabel) ?></strong>
                            <small><?= $credential['agent_name'] ? 'Uso exclusivo neste assistente.' : 'Disponível como padrão para os assistentes da empresa.' ?></small>
                        </div>
                    </div>
                </div>

                <dl class="ai-credential-details">
                    <div><dt>Provedor</dt><dd><?= View::e($providerLabel) ?></dd></div>
                    <div><dt>Modelo</dt><dd><?= View::e((string) ($credential['default_model'] ?: 'Definido no assistente')) ?></dd></div>
                    <div><dt>Chave</dt><dd><?= View::e((string) $credential['api_key_masked']) ?></dd></div>
                    <div><dt>Endereço da API</dt><dd><?= View::e((string) ($credential['base_url'] ?: 'Padrão do provedor')) ?></dd></div>
                </dl>

                <div class="ai-credential-card-actions">
                    <button
                        class="btn btn-outline btn-small"
                        type="button"
                        data-toggle-panel="ai-credential-drawer"
                        data-ai-credential-open="edit"
                        data-id="<?= (int) $credential['id'] ?>"
                        data-tenant-id="<?= (int) $credential['tenant_id'] ?>"
                        data-agent-id="<?= (int) ($credential['agent_id'] ?? 0) ?>"
                        data-label="<?= View::e((string) $credential['label']) ?>"
                        data-provider="<?= View::e((string) $credential['provider']) ?>"
                        data-base-url="<?= View::e((string) ($credential['base_url'] ?? '')) ?>"
                        data-default-model="<?= View::e((string) ($credential['default_model'] ?? '')) ?>"
                        data-status="<?= View::e((string) $credential['status']) ?>"
                        data-is-default="<?= (int) $credential['is_default'] ?>"
                    >
                        Editar credencial
                    </button>
                </div>
            </article>
        <?php endforeach; ?>

        <?php if (!$credentials): ?>
            <div class="empty-state ai-credential-empty">
                <strong>Nenhuma credencial cadastrada.</strong>
                <span>Crie uma credencial para liberar o uso da IA em uma empresa ou assistente.</span>
                <button class="btn btn-primary" type="button" data-ai-credential-open="new" data-toggle-panel="ai-credential-drawer">Cadastrar primeira credencial</button>
            </div>
        <?php endif; ?>

        <div class="empty-state ai-credential-filter-empty" data-ai-credential-filter-empty hidden>
            Nenhuma credencial corresponde aos filtros selecionados.
        </div>
    </div>
</section>

<aside class="conversation-details conversation-drawer ai-credential-drawer" id="ai-credential-drawer" aria-label="Configurar credencial de inteligência artificial" aria-modal="true" role="dialog">
    <div class="conversation-drawer-header">
        <div>
            <span class="eyebrow" data-ai-credential-drawer-eyebrow>Nova credencial</span>
            <h2 data-ai-credential-drawer-title>Cadastrar acesso à IA</h2>
            <p data-ai-credential-drawer-description>Defina quem usará a chave e configure somente as informações necessárias.</p>
        </div>
        <button class="icon-button drawer-close" type="button" data-close-panel="ai-credential-drawer" aria-label="Fechar painel">×</button>
    </div>

    <div class="conversation-drawer-body">
        <form class="drawer-form ai-credential-form" method="post" action="<?= View::e(Router::url('/ai-credentials/save')) ?>" data-ai-credential-form>
            <?= Csrf::input() ?>
            <input type="hidden" name="id" value="0" data-ai-field="id">

            <section class="drawer-section">
                <div class="drawer-section-title">
                    <div>
                        <span class="eyebrow">1. Quem vai usar</span>
                        <h3>Empresa e assistente</h3>
                        <small>Escolha se a credencial será compartilhada pela empresa ou usada por apenas um assistente.</small>
                    </div>
                </div>

                <div class="drawer-form-grid">
                    <label class="field drawer-span">
                        <span>Empresa</span>
                        <select name="tenant_id" required data-ai-field="tenant_id" data-ai-credential-tenant>
                            <option value="">Selecione uma empresa</option>
                            <?php foreach ($tenants as $tenant): ?>
                                <option value="<?= (int) $tenant['id'] ?>"><?= View::e((string) $tenant['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label class="field drawer-span">
                        <span>Onde esta credencial será usada?</span>
                        <select data-ai-credential-scope>
                            <option value="company">Em todos os assistentes da empresa</option>
                            <option value="agent">Somente em um assistente específico</option>
                        </select>
                    </label>

                    <label class="field drawer-span" data-ai-agent-field hidden>
                        <span>Assistente específico</span>
                        <select name="agent_id" data-ai-field="agent_id" data-ai-credential-agent>
                            <option value="0">Selecione o assistente</option>
                            <?php foreach ($agents as $agent): ?>
                                <option value="<?= (int) $agent['id'] ?>" data-tenant-id="<?= (int) $agent['tenant_id'] ?>">
                                    <?= View::e((string) ($agent['tenant_name'] . ' — ' . $agent['name'])) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="field-hint">A lista mostra apenas assistentes pertencentes à empresa escolhida.</small>
                    </label>
                </div>
            </section>

            <section class="drawer-section">
                <div class="drawer-section-title">
                    <div>
                        <span class="eyebrow">2. Identificação</span>
                        <h3>Nome e provedor</h3>
                        <small>Use um nome fácil de reconhecer, como “OpenAI — Clínica Alfa”.</small>
                    </div>
                </div>

                <div class="drawer-form-grid">
                    <label class="field drawer-span">
                        <span>Nome da credencial</span>
                        <input name="label" placeholder="OpenAI — Nome da empresa" required data-ai-field="label">
                    </label>
                    <label class="field">
                        <span>Provedor</span>
                        <select name="provider" data-ai-field="provider" data-ai-credential-provider>
                            <option value="openai">OpenAI</option>
                            <option value="google">Google Gemini</option>
                            <option value="custom">Outro provedor</option>
                        </select>
                    </label>
                    <label class="field">
                        <span>Situação</span>
                        <select name="status" data-ai-field="status">
                            <option value="active">Ativa</option>
                            <option value="inactive">Inativa</option>
                        </select>
                    </label>
                </div>
            </section>

            <section class="drawer-section">
                <div class="drawer-section-title">
                    <div>
                        <span class="eyebrow">3. Chave e modelo</span>
                        <h3>Acesso seguro</h3>
                        <small>A chave é criptografada e não será exibida novamente depois de salvar.</small>
                    </div>
                </div>

                <div class="drawer-form-grid">
                    <label class="field drawer-span">
                        <span data-ai-api-key-label>API Key</span>
                        <input name="api_key" type="password" autocomplete="new-password" placeholder="Cole a chave fornecida pelo provedor" data-ai-field="api_key">
                        <small class="field-hint" data-ai-api-key-hint>Obrigatória ao criar. Na edição, deixe em branco para manter a chave atual.</small>
                    </label>
                    <label class="field">
                        <span>Modelo padrão</span>
                        <input name="default_model" placeholder="gpt-4o-mini" data-ai-field="default_model">
                    </label>
                    <label class="field">
                        <span>Endereço da API</span>
                        <input name="base_url" placeholder="Deixe vazio para usar o padrão" data-ai-field="base_url">
                        <small class="field-hint">Para OpenAI, deixe vazio para usar https://api.openai.com/v1.</small>
                    </label>
                    <label class="check-field drawer-span">
                        <input type="checkbox" name="is_default" value="1" checked data-ai-field="is_default">
                        <span>Usar como credencial padrão neste escopo</span>
                    </label>
                </div>
            </section>

            <div class="drawer-savebar ai-credential-savebar">
                <button class="btn btn-quiet" type="button" data-close-panel="ai-credential-drawer">Cancelar</button>
                <button class="btn btn-primary" type="submit" data-ai-credential-submit>Salvar credencial</button>
            </div>
        </form>
    </div>
</aside>
