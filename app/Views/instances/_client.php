<?php
use App\Core\Csrf;
use App\Core\Router;
use App\Core\View;
?>
<section class="card instances-client-layout">
    <div class="section-heading"><div><span class="eyebrow">WhatsApp da empresa</span><h2>Conexões disponíveis</h2></div><span class="badge"><?= count($instances) ?> conexão(ões)</span></div>
    <div class="client-connection-note"><strong>A configuração técnica é feita pela equipe RS Connect.</strong><span>Quando uma nova conexão estiver disponível, use o QR Code para vincular o WhatsApp da empresa.</span></div>
    <div class="instance-list">
        <?php foreach ($instances as $instance): ?>
            <article class="instance-item"><div class="instance-main"><span class="instance-icon instance-icon-device" aria-hidden="true"></span><div><h3><?= View::e($instance['name']) ?></h3><p><?= View::e($instance['tenant_name']) ?></p><small><?= $instance['status'] === 'connected' ? 'WhatsApp pronto para atendimento.' : 'Aguardando vinculação pelo QR Code.' ?></small></div></div><div class="instance-meta"><span class="badge badge-<?= View::e($instance['status']) ?>"><?= $instance['status'] === 'connected' ? 'Conectada' : ($instance['status'] === 'pending' ? 'Pendente' : 'Desconectada') ?></span><?php if ((int) $instance['is_default'] === 1): ?><span class="badge">Padrão</span><?php endif; ?></div>
            <?php if ($canGenerateQr && $instance['status'] !== 'connected'): ?><div class="instance-client-actions"><form method="post" action="<?= View::e(Router::url('/instances/qr')) ?>" data-qr-code-form><?= Csrf::input() ?><input type="hidden" name="instance_id" value="<?= (int) $instance['id'] ?>"><button class="btn btn-primary" type="submit" data-qr-code-button>Gerar QR Code para conectar</button></form><small>Abra o WhatsApp no celular e acesse Dispositivos conectados.</small></div><?php else: ?><div class="instance-connected-message"><span aria-hidden="true">✓</span><strong>WhatsApp conectado</strong></div><?php endif; ?></article>
        <?php endforeach; ?>
        <?php if (!$instances): ?><div class="empty-state">Nenhuma conexão foi preparada para a empresa.</div><?php endif; ?>
    </div>
</section>
<?php if ($canGenerateQr): ?><div class="qr-connection-modal" data-qr-code-modal hidden aria-hidden="true"><button class="qr-modal-backdrop" type="button" data-close-qr-modal aria-label="Fechar QR Code"></button><section class="qr-modal-card" role="dialog" aria-modal="true" aria-labelledby="qr-modal-title"><div class="qr-modal-header"><div><span class="eyebrow">Conectar WhatsApp</span><h2 id="qr-modal-title">Escaneie o QR Code</h2></div><button class="icon-button" type="button" data-close-qr-modal aria-label="Fechar">×</button></div><div class="qr-modal-body"><div class="qr-loading" data-qr-loading>Gerando QR Code com segurança...</div><img data-qr-image alt="QR Code para conectar o WhatsApp" hidden><p data-qr-message>Abra o WhatsApp no celular, toque em <strong>Dispositivos conectados</strong> e depois em <strong>Conectar dispositivo</strong>.</p><div class="qr-error-message" data-qr-error hidden></div></div><div class="qr-modal-actions"><button class="btn btn-quiet" type="button" data-close-qr-modal>Fechar</button></div></section></div><?php endif; ?>
