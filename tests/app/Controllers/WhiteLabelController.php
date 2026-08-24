<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Database;
use App\Core\Flash;
use App\Core\Router;
use App\Core\View;
use App\Services\BrandingService;
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

        $pdo = Database::connection();
        $statement = $pdo->prepare('SELECT * FROM tenants WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $tenantId]);
        $current = $statement->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$current) {
            Flash::set('error', 'Empresa não encontrada.');
            $this->redirect('/white-label');
        }

        $enabled = isset($_POST['white_label_enabled']) ? 1 : 0;
        $showPoweredBy = isset($_POST['show_powered_by']) ? 1 : 0;

        $brandName = trim((string) ($_POST['brand_name'] ?? ''));
        $brandSubtitle = trim((string) ($_POST['brand_subtitle'] ?? ''));
        $brandIconText = trim((string) ($_POST['brand_icon_text'] ?? ''));
        $brandLogoUrl = trim((string) ($_POST['brand_logo_url'] ?? ''));
        $brandIconUrl = trim((string) ($_POST['brand_icon_url'] ?? ''));
        $brandFaviconUrl = trim((string) ($_POST['brand_favicon_url'] ?? ''));
        $logoVariant = $this->choice((string) ($_POST['brand_logo_variant'] ?? 'horizontal'), ['horizontal', 'square', 'symbol'], 'horizontal');
        $logoBackground = $this->choice((string) ($_POST['brand_logo_background'] ?? 'light'), ['light', 'transparent', 'brand'], 'light');

        $primary = $this->color((string) ($_POST['brand_primary_color'] ?? '#146498'), '#146498');
        $secondary = $this->color((string) ($_POST['brand_secondary_color'] ?? '#631b7c'), '#631b7c');
        $accent = $this->color((string) ($_POST['brand_accent_color'] ?? '#01c5b6'), '#01c5b6');
        $loginBg = $this->color((string) ($_POST['login_background_color'] ?? '#07111f'), '#07111f');
        $loginText = $this->color((string) ($_POST['login_text_color'] ?? '#ffffff'), '#ffffff');

        $loginEyebrow = trim((string) ($_POST['login_eyebrow'] ?? ''));
        $loginTitle = trim((string) ($_POST['login_title'] ?? ''));
        $loginSubtitle = trim((string) ($_POST['login_subtitle'] ?? ''));
        $loginButtonText = trim((string) ($_POST['login_button_text'] ?? ''));
        $loginBenefit1 = trim((string) ($_POST['login_benefit_1'] ?? ''));
        $loginBenefit2 = trim((string) ($_POST['login_benefit_2'] ?? ''));
        $loginBenefit3 = trim((string) ($_POST['login_benefit_3'] ?? ''));
        $loginSecurityText = trim((string) ($_POST['login_security_text'] ?? ''));
        $footerText = trim((string) ($_POST['brand_footer_text'] ?? ''));
        $supportEmail = strtolower(trim((string) ($_POST['support_email'] ?? '')));
        $customDomain = strtolower(trim((string) ($_POST['custom_domain'] ?? '')));

        if (isset($_POST['remove_logo'])) {
            $brandLogoUrl = '';
        } elseif ($brandLogoUrl === '') {
            $brandLogoUrl = (string) ($current['brand_logo_url'] ?? '');
        }

        if (isset($_POST['remove_icon'])) {
            $brandIconUrl = '';
        } elseif ($brandIconUrl === '') {
            $brandIconUrl = (string) ($current['brand_icon_url'] ?? '');
        }

        if (isset($_POST['remove_favicon'])) {
            $brandFaviconUrl = '';
        } elseif ($brandFaviconUrl === '') {
            $brandFaviconUrl = (string) ($current['brand_favicon_url'] ?? '');
        }

        try {
            $uploadedLogo = $this->uploadBrandAsset('brand_logo_file', $tenantId, 'logo');
            if ($uploadedLogo !== null) {
                $brandLogoUrl = $uploadedLogo;
            }

            $uploadedIcon = $this->uploadBrandAsset('brand_icon_file', $tenantId, 'icon');
            if ($uploadedIcon !== null) {
                $brandIconUrl = $uploadedIcon;
            }

            $uploadedFavicon = $this->uploadBrandAsset('brand_favicon_file', $tenantId, 'favicon');
            if ($uploadedFavicon !== null) {
                $brandFaviconUrl = $uploadedFavicon;
            }
        } catch (Throwable $exception) {
            Flash::set('error', $exception->getMessage());
            $this->redirect('/white-label?tenant_id=' . $tenantId);
        }

        foreach ([$brandLogoUrl, $brandIconUrl, $brandFaviconUrl] as $url) {
            if ($url !== '' && !str_starts_with($url, '/uploads/') && !filter_var($url, FILTER_VALIDATE_URL)) {
                Flash::set('error', 'Informe URLs completas para imagens ou envie arquivos locais.');
                $this->redirect('/white-label?tenant_id=' . $tenantId);
            }
        }

        if ($supportEmail !== '' && !filter_var($supportEmail, FILTER_VALIDATE_EMAIL)) {
            Flash::set('error', 'O e-mail de suporte informado é inválido.');
            $this->redirect('/white-label?tenant_id=' . $tenantId);
        }

        if ($customDomain !== '' && !preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $customDomain)) {
            Flash::set('error', 'Informe um domínio válido, sem https://. Exemplo: painel.cliente.com.br');
            $this->redirect('/white-label?tenant_id=' . $tenantId);
        }

        try {
            $statement = $pdo->prepare(
                'UPDATE tenants
                 SET white_label_enabled = :enabled,
                     brand_name = :brand_name,
                     brand_subtitle = :brand_subtitle,
                     brand_logo_url = :brand_logo_url,
                     brand_icon_url = :brand_icon_url,
                     brand_favicon_url = :brand_favicon_url,
                     brand_icon_text = :brand_icon_text,
                     brand_logo_variant = :brand_logo_variant,
                     brand_logo_background = :brand_logo_background,
                     brand_primary_color = :primary_color,
                     brand_secondary_color = :secondary_color,
                     brand_accent_color = :accent_color,
                     login_background_color = :login_background_color,
                     login_text_color = :login_text_color,
                     login_eyebrow = :login_eyebrow,
                     login_title = :login_title,
                     login_subtitle = :login_subtitle,
                     login_button_text = :login_button_text,
                     login_benefit_1 = :login_benefit_1,
                     login_benefit_2 = :login_benefit_2,
                     login_benefit_3 = :login_benefit_3,
                     login_security_text = :login_security_text,
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
                'brand_icon_url' => $brandIconUrl !== '' ? $brandIconUrl : null,
                'brand_favicon_url' => $brandFaviconUrl !== '' ? $brandFaviconUrl : null,
                'brand_icon_text' => $brandIconText !== '' ? mb_substr($brandIconText, 0, 4) : null,
                'brand_logo_variant' => $logoVariant,
                'brand_logo_background' => $logoBackground,
                'primary_color' => $primary,
                'secondary_color' => $secondary,
                'accent_color' => $accent,
                'login_background_color' => $loginBg,
                'login_text_color' => $loginText,
                'login_eyebrow' => $loginEyebrow !== '' ? $loginEyebrow : null,
                'login_title' => $loginTitle !== '' ? $loginTitle : null,
                'login_subtitle' => $loginSubtitle !== '' ? $loginSubtitle : null,
                'login_button_text' => $loginButtonText !== '' ? $loginButtonText : null,
                'login_benefit_1' => $loginBenefit1 !== '' ? $loginBenefit1 : null,
                'login_benefit_2' => $loginBenefit2 !== '' ? $loginBenefit2 : null,
                'login_benefit_3' => $loginBenefit3 !== '' ? $loginBenefit3 : null,
                'login_security_text' => $loginSecurityText !== '' ? $loginSecurityText : null,
                'footer_text' => $footerText !== '' ? $footerText : null,
                'support_email' => $supportEmail !== '' ? $supportEmail : null,
                'custom_domain' => $customDomain !== '' ? $customDomain : null,
                'show_powered_by' => $showPoweredBy,
                'tenant_id' => $tenantId,
            ]);

            Audit::log('white_label.updated', ['enabled' => $enabled, 'custom_domain' => $customDomain, 'logo_variant' => $logoVariant], $tenantId);
            Flash::set('success', $enabled === 1 ? 'White label ativado e atualizado.' : 'White label salvo como inativo.');
        } catch (Throwable $exception) {
            Flash::set('error', 'Não foi possível salvar. Execute a migration 023 e verifique se o domínio já não está em uso.');
        }

        $this->redirect('/white-label?tenant_id=' . $tenantId);
    }

    public function preview(): void
    {
        $tenantId = (int) ($_GET['tenant_id'] ?? 0);
        $branding = BrandingService::forTenantId($tenantId);

        View::render('auth.login', [
            'title' => 'Pré-visualização do login',
            'branding' => $branding,
            'isPreview' => true,
        ], 'guest');
    }

    private function uploadBrandAsset(string $field, int $tenantId, string $prefix): ?string
    {
        $file = $_FILES[$field] ?? null;
        if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        if ((int) $file['error'] !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('Não foi possível enviar a imagem. Tente novamente.');
        }

        $size = (int) ($file['size'] ?? 0);
        if ($size < 1 || $size > 2 * 1024 * 1024) {
            throw new \RuntimeException('A imagem deve ter no máximo 2MB.');
        }

        $tmpName = (string) ($file['tmp_name'] ?? '');
        $mime = '';
        if ($tmpName !== '' && is_file($tmpName)) {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mime = (string) $finfo->file($tmpName);
        }

        $extensions = [
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            'image/svg+xml' => 'svg',
            'image/x-icon' => 'ico',
            'image/vnd.microsoft.icon' => 'ico',
        ];

        if (!isset($extensions[$mime])) {
            throw new \RuntimeException('Envie uma imagem PNG, JPG, WEBP, SVG ou ICO.');
        }

        $publicPath = dirname(__DIR__, 2) . '/public';
        $uploadDir = $publicPath . '/uploads/white-label/tenant-' . $tenantId;
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
            throw new \RuntimeException('Não foi possível criar a pasta de upload.');
        }

        $filename = $prefix . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $extensions[$mime];
        $destination = $uploadDir . '/' . $filename;

        if (!move_uploaded_file($tmpName, $destination)) {
            throw new \RuntimeException('Não foi possível salvar a imagem enviada.');
        }

        return '/uploads/white-label/tenant-' . $tenantId . '/' . $filename;
    }

    private function color(string $value, string $fallback): string
    {
        $value = trim($value);
        return preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1 ? $value : $fallback;
    }

    private function choice(string $value, array $allowed, string $fallback): string
    {
        $value = trim($value);
        return in_array($value, $allowed, true) ? $value : $fallback;
    }

    private function redirect(string $path): never
    {
        header('Location: ' . Router::url($path));
        exit;
    }
}
