<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Database;
use App\Core\Flash;
use App\Core\Router;
use App\Core\View;
use PDO;
use Throwable;

final class WhiteLabelController
{
    public function index(): void
    {
        $pdo = Database::connection();
        $companies = $pdo->query(
            'SELECT id, name, slug, status, white_label_enabled, brand_name, brand_primary_color, brand_secondary_color, brand_accent_color, custom_domain
             FROM tenants
             ORDER BY name ASC'
        )->fetchAll(PDO::FETCH_ASSOC);

        $selectedId = (int) ($_GET['tenant_id'] ?? ($companies[0]['id'] ?? 0));
        $selected = null;
        if ($selectedId > 0) {
            $statement = $pdo->prepare('SELECT * FROM tenants WHERE id = :id LIMIT 1');
            $statement->execute(['id' => $selectedId]);
            $selected = $statement->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        View::render('white_label.index', [
            'title' => 'White label',
            'companies' => $companies,
            'selected' => $selected,
        ]);
    }

    public function save(): void
    {
        $tenantId = (int) ($_POST['tenant_id'] ?? 0);
        if ($tenantId < 1) {
            Flash::set('error', 'Selecione uma empresa para configurar o white label.');
            $this->redirect('/white-label');
        }

        $enabled = isset($_POST['white_label_enabled']) ? 1 : 0;
        $showPoweredBy = isset($_POST['show_powered_by']) ? 1 : 0;
        $brandName = trim((string) ($_POST['brand_name'] ?? ''));
        $brandSubtitle = trim((string) ($_POST['brand_subtitle'] ?? ''));
        $brandLogoUrl = trim((string) ($_POST['brand_logo_url'] ?? ''));
        $brandFaviconUrl = trim((string) ($_POST['brand_favicon_url'] ?? ''));
        $brandIconText = trim((string) ($_POST['brand_icon_text'] ?? ''));
        $primary = $this->color((string) ($_POST['brand_primary_color'] ?? '#146498'), '#146498');
        $secondary = $this->color((string) ($_POST['brand_secondary_color'] ?? '#631b7c'), '#631b7c');
        $accent = $this->color((string) ($_POST['brand_accent_color'] ?? '#01c5b6'), '#01c5b6');
        $loginTitle = trim((string) ($_POST['login_title'] ?? ''));
        $loginSubtitle = trim((string) ($_POST['login_subtitle'] ?? ''));
        $footerText = trim((string) ($_POST['brand_footer_text'] ?? ''));
        $supportEmail = strtolower(trim((string) ($_POST['support_email'] ?? '')));
        $customDomain = strtolower(trim((string) ($_POST['custom_domain'] ?? '')));

        foreach ([$brandLogoUrl, $brandFaviconUrl] as $url) {
            if ($url !== '' && !filter_var($url, FILTER_VALIDATE_URL)) {
                Flash::set('error', 'Informe URLs completas para logo/favicon, incluindo https://.');
                $this->redirect('/white-label?tenant_id=' . $tenantId);
            }
        }

        if ($supportEmail !== '' && !filter_var($supportEmail, FILTER_VALIDATE_EMAIL)) {
            Flash::set('error', 'O e-mail de suporte informado é inválido.');
            $this->redirect('/white-label?tenant_id=' . $tenantId);
        }

        if ($customDomain !== '' && !preg_match('/^[a-z0-9.-]+\\.[a-z]{2,}$/', $customDomain)) {
            Flash::set('error', 'Informe um domínio válido, sem https://. Exemplo: painel.cliente.com.br');
            $this->redirect('/white-label?tenant_id=' . $tenantId);
        }

        try {
            $statement = Database::connection()->prepare(
                'UPDATE tenants
                 SET white_label_enabled = :enabled,
                     brand_name = :brand_name,
                     brand_subtitle = :brand_subtitle,
                     brand_logo_url = :brand_logo_url,
                     brand_favicon_url = :brand_favicon_url,
                     brand_icon_text = :brand_icon_text,
                     brand_primary_color = :primary_color,
                     brand_secondary_color = :secondary_color,
                     brand_accent_color = :accent_color,
                     login_title = :login_title,
                     login_subtitle = :login_subtitle,
                     brand_footer_text = :footer_text,
                     support_email = :support_email,
                     custom_domain = :custom_domain,
                     show_powered_by = :show_powered_by
                 WHERE id = :tenant_id'
            );
            $statement->execute([
                'enabled' => $enabled,
                'brand_name' => $brandName !== '' ? $brandName : null,
                'brand_subtitle' => $brandSubtitle !== '' ? $brandSubtitle : null,
                'brand_logo_url' => $brandLogoUrl !== '' ? $brandLogoUrl : null,
                'brand_favicon_url' => $brandFaviconUrl !== '' ? $brandFaviconUrl : null,
                'brand_icon_text' => $brandIconText !== '' ? mb_substr($brandIconText, 0, 4) : null,
                'primary_color' => $primary,
                'secondary_color' => $secondary,
                'accent_color' => $accent,
                'login_title' => $loginTitle !== '' ? $loginTitle : null,
                'login_subtitle' => $loginSubtitle !== '' ? $loginSubtitle : null,
                'footer_text' => $footerText !== '' ? $footerText : null,
                'support_email' => $supportEmail !== '' ? $supportEmail : null,
                'custom_domain' => $customDomain !== '' ? $customDomain : null,
                'show_powered_by' => $showPoweredBy,
                'tenant_id' => $tenantId,
            ]);

            Audit::log('white_label.updated', ['enabled' => $enabled, 'custom_domain' => $customDomain], $tenantId);
            Flash::set('success', 'White label atualizado para a empresa selecionada.');
        } catch (Throwable) {
            Flash::set('error', 'Não foi possível salvar. Verifique se o domínio já não está em uso.');
        }

        $this->redirect('/white-label?tenant_id=' . $tenantId);
    }

    private function color(string $value, string $fallback): string
    {
        $value = trim($value);
        return preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1 ? $value : $fallback;
    }

    private function redirect(string $path): never
    {
        header('Location: ' . Router::url($path));
        exit;
    }
}
