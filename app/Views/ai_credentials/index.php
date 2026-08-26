<?php

use App\Core\Csrf;
use App\Core\Router;
use App\Core\View;

$totalCredentials = count($credentials);
$activeCredentials = count(array_filter($credentials, static fn (array $credential): bool => ($credential['status'] ?? '') === 'active'));
$coveredTenants = count(array_unique(array_map(static fn (array $credential): int => (int) ($credential['tenant_id'] ?? 0), $credentials)));
$tenantOwnedCredentials = count(array_filter($credentials, static fn (array $credential): bool => ($credential['credential_owner'] ?? 'tenant') === 'tenant'));
$rsOwnedCredentials = count(array_filter($credentials, static fn (array $credential): bool => ($credential['credential_owner'] ?? 'tenant') === 'rs_connect'));
?>

<section class="ai-credentials-hero">
    <div>
        <span class="eyebrow">Administração RS Connect</span>
        <h2>Credenciais de inteligência artificial</h2>
        <p>Organize as chaves de acesso usadas pelos assistentes de cada empresa sem expor informações sensíveis.</p>
    </div>
    <button class="btn btn-primary" type="button" data-ai-credential-open="new" data-toggle-panel="ai-credential-drawer">
        Nova chave de acesso
    </button>
</section>

<section class="ai-credential-summary" aria-label="Resumo das credenciais">
    <article>
        <span>Total cadastrado</span>
        <strong><?= $totalCredentials ?></strong>
        <small>chave(s) protegida(s)</small>
    </article>
    <article class="is-success">
        <span>Ativas</span>
        <strong><?= $activeCredentials ?></strong>
        <small>disponíveis para uso</small>
    </article>
    <article class="is-blue">
        <span>Credenciais do cliente</span>
        <strong><?= $tenantOwnedCredentials ?></strong>
        <small>não usam o limite pago pela RS</small>
    </article>
    <article class="is-purple">
        <span>Custeadas pela RS</span>
        <strong><?= $rsOwnedCredentials ?></strong>
        <small>usam o limite de IA do plano</small>
    </article>
</section>

<section class="card openai-usage-shortcut" aria-label="Atalho para consumo da OpenAI">
    <div>
        <span class="eyebrow">Dados oficiais da organização</span>
        <h2>Uso e custo da IA em página própria</h2>
        <p>Acompanhe quanto a IA foi usada, os modelos escolhidos e os custos na página Uso e custo da IA.</p>
    </div>
    <a class="btn btn-outline" href="<?= View::e(Router::url('/openai-usage')) ?>">Abrir uso e custo da IA</a>
</section>

<div class="operations-alert is-info" style="margin-bottom:16px">
    <strong>Revise o custeio das chaves existentes</strong>
    <p>Chaves cadastradas anteriormente foram marcadas como <strong>Cliente</strong>. Escolha <strong>RS Connect</strong> para as chaves pagas pela RS, garantindo que o limite do plano seja calculado corretamente. A chave global do ambiente é considerada RS automaticamente.</p>
</div>

<section class="card ai-credentials-panel">
    <div class="section-heading ai-credentials-heading">
        <div>
            <span class="eyebrow">Acessos configurados</span>
            <h2>Credenciais por empresa</h2>
            <p>Use os filtros para localizar uma empresa, serviço de IA ou situação.</p>
        </div>
        <span class="badge" data-ai-credential-visible-count><?= $totalCredentials ?> registro(s)</span>
    </div>

    <div class="ai-credential-filters" data-ai-credential-filters>
        <label class="field ai-credential-search">
            <span>Buscar</span>
            <input type="search" placeholder="Empresa, assistente ou nome da chave" data-ai-credential-search>
        </label>
        <label class="field">
            <span>Serviço de IA</span>
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
        <label class="field">
            <span>Custeio</span>
            <select data-ai-credential-owner-filter>
                <option value="">Todos</option>
                <option value="rs_connect">RS Connect</option>
                <option value="tenant">Cliente</option>
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
            $credentialOwner = (string) ($credential['credential_owner'] ?? 'tenant');
            $ownerLabel = $credentialOwner === 'rs_connect' ? 'RS Connect' : 'Cliente';
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
                data-owner="<?= View::e($credentialOwner) ?>"
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
                                <span class="badge"><?= View::e($ownerLabel === 'RS Connect' ? 'Custeio RS' : 'Custeio cliente') ?></span>
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
                    <div><dt>Serviço de IA</dt><dd><?= View::e($providerLabel) ?></dd></div>
                    <div><dt>Custeio</dt><dd><?= View::e($ownerLabel) ?></dd></div>
                    <div><dt>Modelo de IA</dt><dd><?= View::e((string) ($credential['default_model'] ?: 'Definido no assistente')) ?></dd></div>
                    <div><dt>Chave protegida</dt><dd><?= View::e((string) $credential['api_key_masked']) ?></dd></div>
                    <div><dt>Endereço do serviço</dt><dd><?= View::e((string) ($credential['base_url'] ?: 'Padrão do serviço')) ?></dd></div>
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
                        data-credential-owner="<?= View::e($credentialOwner) ?>"
                        data-base-url="<?= View::e((string) ($credential['base_url'] ?? '')) ?>"
                        data-default-model="<?= View::e((string) ($credential['default_model'] ?? '')) ?>"
                        data-status="<?= View::e((string) $credential['status']) ?>"
                        data-is-default="<?= (int) $credential['is_default'] ?>"
                    >
                        Editar chave
                    </button>
                </div>
            </article>
        <?php endforeach; ?>

        <?php if (!$credentials): ?>
            <div class="empty-state ai-credential-empty">
                <strong>Nenhuma chave de acesso cadastrada.</strong>
                <span>Cadastre uma chave para permitir que uma empresa ou assistente use a IA.</span>
                <button class="btn btn-primary" type="button" data-ai-credential-open="new" data-toggle-panel="ai-credential-drawer">Cadastrar primeira chave</button>
            </div>
        <?php endif; ?>

        <div class="empty-state ai-credential-filter-empty" data-ai-credential-filter-empty hidden>
            Nenhuma chave corresponde aos filtros selecionados.
        </div>
    </div>
</section>

<aside class="conversation-details conversation-drawer ai-credential-drawer" id="ai-credential-drawer" aria-label="Configurar chave de acesso da inteligência artificial" aria-modal="true" role="dialog">
    <div class="conversation-drawer-header">
        <div>
            <span class="eyebrow" data-ai-credential-drawer-eyebrow>Nova chave de acesso</span>
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
                        <small>Escolha se a chave será usada por todos os assistentes da empresa ou por apenas um.</small>
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
                        <span>Onde esta chave será usada?</span>
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
                        <h3>Nome e serviço de IA</h3>
                        <small>Use um nome fácil de reconhecer, como “OpenAI — Clínica Alfa”.</small>
                    </div>
                </div>

                <div class="drawer-form-grid">
                    <label class="field drawer-span">
                        <span>Nome para identificar a chave</span>
                        <input name="label" placeholder="OpenAI — Nome da empresa" required data-ai-field="label">
                    </label>
                    <label class="field">
                        <span>Serviço de IA</span>
                        <select name="provider" data-ai-field="provider" data-ai-credential-provider>
                            <option value="openai">OpenAI</option>
                            <option value="google">Google Gemini</option>
                            <option value="custom">Outro serviço</option>
                        </select>
                    </label>
                    <label class="field">
                        <span>Quem custeia esta IA?</span>
                        <select name="credential_owner" data-ai-field="credential_owner">
                            <option value="tenant">Cliente — chave/conta própria</option>
                            <option value="rs_connect">RS Connect — usa o limite de IA do plano</option>
                        </select>
                        <small class="field-hint">A chave paga pelo cliente fica registrada, mas não usa o limite de IA pago pela RS Connect.</small>
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
                        <span class="eyebrow">3. Acesso e modelo da IA</span>
                        <h3>Acesso seguro</h3>
                        <small>A chave é criptografada e não será exibida novamente depois de salvar.</small>
                    </div>
                </div>

                <div class="drawer-form-grid ai-credential-access-grid">
                    <label class="field drawer-span">
                        <span data-ai-api-key-label>Chave de acesso</span>
                        <input name="api_key" type="password" autocomplete="new-password" placeholder="Cole aqui a chave fornecida pelo serviço de IA" data-ai-field="api_key">
                        <small class="field-hint" data-ai-api-key-hint>Obrigatória ao criar. Na edição, deixe em branco para continuar usando a chave atual.</small>
                    </label>
                    <label class="field">
                        <span>Modelo usado por padrão</span>
                        <input name="default_model" placeholder="Ex.: gpt-4o-mini" data-ai-field="default_model">
                        <small class="field-hint">Será usado quando o assistente não tiver um modelo específico.</small>
                    </label>
                    <label class="field">
                        <span>Endereço do serviço (opcional)</span>
                        <input name="base_url" placeholder="Use o endereço padrão" data-ai-field="base_url">
                        <small class="field-hint">Na OpenAI, normalmente este campo pode ficar vazio.</small>
                    </label>
                    <label class="check-field check-card drawer-span ai-credential-default-option">
                        <input type="checkbox" name="is_default" value="1" checked data-ai-field="is_default">
                        <span><strong>Usar como chave principal desta empresa</strong><small>Quando não houver outra chave escolhida, o sistema usará esta.</small></span>
                    </label>
                </div>
            </section>

            <div class="drawer-savebar ai-credential-savebar">
                <button class="btn btn-quiet" type="button" data-close-panel="ai-credential-drawer">Cancelar</button>
                <button class="btn btn-primary" type="submit" data-ai-credential-submit>Salvar chave</button>
            </div>
        </form>
    </div>
</aside>
