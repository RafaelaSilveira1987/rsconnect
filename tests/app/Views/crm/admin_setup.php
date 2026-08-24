<?php
use App\Core\Router;
use App\Core\View;
?>
<section class="admin-executive-hero">
    <div class="admin-executive-hero-copy">
        <span class="eyebrow">CRM comercial RS</span>
        <h2>Falta preparar a estrutura comercial</h2>
        <p>O código do CRM está instalado, mas as tabelas administrativas ainda não foram criadas no banco.</p>
    </div>
</section>
<section class="card" style="max-width:760px;margin:0 auto">
    <div class="section-heading"><div><span class="eyebrow">Última etapa</span><h2>Execute a migration 037</h2></div></div>
    <p>No Adminer, abra o banco do RS Connect e execute:</p>
    <pre><?= View::e($migration ?? 'database/migrations/037_admin_commercial_crm_reports.sql') ?></pre>
    <p>Depois atualize a página. Nenhuma oportunidade do CRM das empresas clientes será alterada.</p>
    <a class="btn btn-primary" href="<?= View::e(Router::url('/crm')) ?>">Verificar novamente</a>
</section>
