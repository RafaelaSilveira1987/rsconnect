<?php
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Router;
use App\Core\View;

$channelPlan = is_array($channelPlan ?? null) ? $channelPlan : [];
$channelUsage = is_array($channelUsage ?? null) ? $channelUsage : [];
$routingByInstance = is_array($routingByInstance ?? null) ? $routingByInstance : [];
$allAgents = is_array($adminAgents ?? null) ? $adminAgents : [];
$canRoute = Auth::can('agents.manage');
$limit = $channelPlan['limits']['instances'] ?? null;
$used = (int) ($channelUsage['instances'] ?? count($instances));
?>
<section class="channel-page-hero">
    <div>
        <span class="eyebrow">Canais de atendimento</span>
        <h2>WhatsApps da empresa</h2>
        <p>Todos os números ficam em uma única tela. Em cada canal você define quais assistentes podem atuar e quem recebe as novas conversas.</p>
    </div>
    <div class="channel-plan-usage">
        <span>Uso do plano</span>
        <strong><?= $used ?><?= $limit !== null ? ' de ' . (int) $limit : '' ?></strong>
        <small><?= $limit !== null ? 'conexões WhatsApp disponíveis no plano ' . View::e((string) ($channelPlan['name'] ?? 'atual')) : 'sem limite definido' ?></small>
    </div>
</section>

<section class="channel-explainer-grid" aria-label="Como funcionam os canais e agentes">
    <article><strong>1 número = 1 canal</strong><span>Recepção, Comercial, Unidade Centro ou qualquer outro WhatsApp da empresa.</span></article>
    <article><strong>Vários agentes no mesmo canal</strong><span>Um agente principal recebe o atendimento e especialistas podem assumir por assunto.</span></article>
    <article><strong>Um agente em vários canais</strong><span>O mesmo assistente pode atender mais de um número quando a operação pedir.</span></article>
</section>

<section class="card channels-client-layout">
    <div class="section-heading">
        <div><span class="eyebrow">Canais conectados</span><h2>Operação WhatsApp</h2><p>A configuração técnica da Evolution continua protegida pela equipe RS Connect; o roteamento dos assistentes pode ser ajustado aqui.</p></div>
        <span class="badge"><?= count($instances) ?> canal(is)</span>
    </div>

    <div class="channel-card-grid">
        <?php foreach ($instances as $instance): ?>
            <?php
            $bindings = $routingByInstance[(int) $instance['id']] ?? [];
            $primaryName = '';
            foreach ($bindings as $binding) {
                if ((int) ($binding['is_primary'] ?? 0) === 1) {
                    $primaryName = (string) ($binding['name'] ?? $binding['agent_name'] ?? '');
                    break;
                }
            }
            ?>
            <article class="channel-card" data-instance-status-card data-status-endpoint="<?= View::e(Router::url('/instances/status-feed')) ?>" data-instance-id="<?= (int) $instance['id'] ?>">
                <div class="channel-card-head">
                    <div class="channel-identity">
                        <span class="channel-icon" aria-hidden="true">WA</span>
                        <div>
                            <h3><?= View::e($instance['name']) ?></h3>
                            <p><?= View::e($instance['instance_name']) ?></p>
                        </div>
                    </div>
                    <div class="channel-status-stack">
                        <span class="badge badge-<?= View::e($instance['status']) ?>" data-instance-status-badge><?= $instance['status'] === 'connected' ? 'Conectado' : ($instance['status'] === 'pending' ? 'Pendente' : 'Desconectado') ?></span>
                        <?php if ((int) $instance['is_default'] === 1): ?><span class="badge">Canal padrão</span><?php endif; ?>
                        <small class="channel-live-status" data-instance-status-detail><?= View::e((string) (($instance['connection_state'] ?? '') ?: 'Aguardando atualização')) ?></small>
                    </div>
                </div>

                <div class="channel-metrics">
                    <div><span>Assistentes</span><strong><?= count($bindings) ?></strong><small>vinculados a este número</small></div>
                    <div><span>Principal</span><strong><?= View::e($primaryName !== '' ? $primaryName : 'Não definido') ?></strong><small>recebe quando não há regra específica</small></div>
                    <div><span>Conversas</span><strong><?= (int) ($instance['conversations_count'] ?? 0) ?></strong><small>histórico deste canal</small></div>
                </div>

                <?php
                require __DIR__ . '/_routing.php';
                ?>

                <div class="channel-card-actions">
                    <a class="btn btn-outline" href="<?= View::e(Router::url('/conversations?instance_id=' . (int) $instance['id'])) ?>">Ver conversas</a>
                    <?php if ($canGenerateQr): ?>
                        <form method="post" action="<?= View::e(Router::url('/instances/qr')) ?>" data-qr-code-form <?= $instance['status'] === 'connected' ? 'hidden' : '' ?>>
                            <?= Csrf::input() ?><input type="hidden" name="instance_id" value="<?= (int) $instance['id'] ?>">
                            <button class="btn btn-primary" type="submit" data-qr-code-button>Conectar QR Code</button>
                        </form>
                    <?php endif; ?>
                    <span class="channel-connected-note" <?= $instance['status'] === 'connected' ? '' : 'hidden' ?>>WhatsApp pronto para atendimento</span>
                </div>
            </article>
        <?php endforeach; ?>
        <?php if (!$instances): ?>
            <div class="empty-state">Nenhum canal foi preparado para a empresa. A equipe RS Connect precisa cadastrar a primeira conexão.</div>
        <?php endif; ?>
    </div>
</section>

<?php if ($canGenerateQr): ?>
<div class="qr-connection-modal" data-qr-code-modal hidden aria-hidden="true">
    <button class="qr-modal-backdrop" type="button" data-close-qr-modal aria-label="Fechar QR Code"></button>
    <section class="qr-modal-card" role="dialog" aria-modal="true" aria-labelledby="qr-modal-title">
        <div class="qr-modal-header"><div><span class="eyebrow">Conectar WhatsApp</span><h2 id="qr-modal-title">Escaneie o QR Code</h2></div><button class="icon-button" type="button" data-close-qr-modal aria-label="Fechar">×</button></div>
        <div class="qr-modal-body"><div class="qr-loading" data-qr-loading>Gerando QR Code com segurança...</div><img data-qr-image alt="QR Code para conectar o WhatsApp" hidden><p data-qr-message>Abra o WhatsApp no celular, toque em <strong>Dispositivos conectados</strong> e depois em <strong>Conectar dispositivo</strong>.</p><div class="qr-error-message" data-qr-error hidden></div></div>
        <div class="qr-modal-actions"><button class="btn btn-quiet" type="button" data-close-qr-modal>Fechar</button></div>
    </section>
</div>
<?php endif; ?>
