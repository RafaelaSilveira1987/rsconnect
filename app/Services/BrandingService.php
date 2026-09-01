<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use PDO;
use Throwable;

final class BrandingService
{
    public static function forCurrentRequest(): array
    {
        $default = self::defaults();

        try {
            $pdo = Database::connection();
            $tenant = self::resolveTenant($pdo);
            if (!$tenant || (int) ($tenant['white_label_enabled'] ?? 0) !== 1) {
                return $default;
            }

            return self::buildFromTenant($tenant, $default, false);
        } catch (Throwable) {
            return $default;
        }
    }

    public static function forTenantId(int $tenantId): array
    {
        $default = self::defaults();
        if ($tenantId < 1) {
            return $default;
        }

        try {
            $pdo = Database::connection();
            $statement = $pdo->prepare('SELECT * FROM tenants WHERE id = :id LIMIT 1');
            $statement->execute(['id' => $tenantId]);
            $tenant = $statement->fetch(PDO::FETCH_ASSOC);
            if (!$tenant) {
                return $default;
            }

            return self::buildFromTenant($tenant, $default, true);
        } catch (Throwable) {
            return $default;
        }
    }

    public static function assetUrl(string $path): string
    {
        $path = trim($path);
        if ($path === '' || preg_match('/^(?:javascript|data|file|vbscript):/i', $path) === 1) {
            return '';
        }

        if (preg_match('/^https?:\/\//i', $path) === 1) {
            $scheme = strtolower((string) parse_url($path, PHP_URL_SCHEME));
            $assetPath = strtolower((string) parse_url($path, PHP_URL_PATH));
            if ($scheme !== 'https' || preg_match('/\.(?:svg|svgz|html?|js|xml)$/', $assetPath) === 1) {
                return '';
            }
            return $path;
        }

        $normalized = '/' . ltrim($path, '/');
        $assetPath = strtolower((string) parse_url($normalized, PHP_URL_PATH));

        if ($assetPath === '/white-label/asset') {
            $query = [];
            parse_str((string) parse_url($normalized, PHP_URL_QUERY), $query);
            $tenantId = (int) ($query['scope'] ?? 0);
            $filename = basename((string) ($query['file'] ?? ''));
            if ($tenantId < 1 || preg_match('/^logo-\d{14}-[a-f0-9]{8}\.(?:png|jpg|webp)$/D', $filename) !== 1) {
                return '';
            }
            return $normalized;
        }

        if (str_starts_with($assetPath, '/uploads/') && preg_match('/\.(?:png|jpe?g|webp)$/', $assetPath) !== 1) {
            return '';
        }

        return $normalized;
    }

    public static function defaults(): array
    {
        return [
            'enabled' => false,
            'tenant_id' => 0,
            'app_name' => 'RS Connect',
            'subtitle' => 'Atendimento e CRM',
            'icon_text' => 'RS',
            'logo_url' => '',
            'icon_url' => '',
            'favicon_url' => '',
            'logo_variant' => 'horizontal',
            'logo_background' => 'light',
            'primary' => '#146498',
            'secondary' => '#631b7c',
            'accent' => '#01c5b6',
            'login_bg' => '#07111f',
            'login_text' => '#ffffff',
            'login_eyebrow' => 'Atendimento e CRM',
            'login_title' => 'Controle sua operação de WhatsApp em uma plataforma profissional.',
            'login_subtitle' => 'Multiempresa, agentes de IA, agenda, CRM, cobrança, n8n e atendimento humano trabalhando juntos.',
            'login_button_text' => 'Acessar painel',
            'login_benefits' => ['WhatsApp + Evolution API', 'IA com regras comerciais', 'CRM e agenda integrados'],
            'login_security_text' => 'Ambiente seguro para administradores, equipes e clientes.',
            'footer_text' => 'RS Automação Digital',
            'support_email' => '',
            'show_powered_by' => true,
        ];
    }

    private static function buildFromTenant(array $tenant, array $default, bool $allowInactivePreview): array
    {
        if (!$allowInactivePreview && (int) ($tenant['white_label_enabled'] ?? 0) !== 1) {
            return $default;
        }

        $logoUrl = self::assetUrl((string) ($tenant['brand_logo_url'] ?? ''));
        if ($logoUrl === '') {
            return $default;
        }

        // White label deliberadamente limitado à logo.
        // Todos os demais dados antigos permanecem armazenados, porém não alteram
        // nome, cores, favicon, textos, rodapé ou identidade visual da RS Connect.
        return array_replace($default, [
            'enabled' => true,
            'tenant_id' => (int) ($tenant['id'] ?? 0),
            'logo_url' => $logoUrl,
        ]);
    }

    private static function resolveTenant(PDO $pdo): ?array
    {
        if (Auth::check() && Auth::tenantId()) {
            $statement = $pdo->prepare('SELECT * FROM tenants WHERE id = :id LIMIT 1');
            $statement->execute(['id' => Auth::tenantId()]);
            $tenant = $statement->fetch(PDO::FETCH_ASSOC);
            return $tenant ?: null;
        }

        $slug = trim((string) ($_GET['tenant'] ?? $_GET['empresa'] ?? ''));
        if ($slug !== '') {
            $statement = $pdo->prepare('SELECT * FROM tenants WHERE slug = :slug LIMIT 1');
            $statement->execute(['slug' => $slug]);
            $tenant = $statement->fetch(PDO::FETCH_ASSOC);
            if ($tenant) {
                return $tenant;
            }
        }

        $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
        $host = preg_replace('/:\d+$/', '', $host) ?: $host;
        if ($host !== '') {
            $statement = $pdo->prepare('SELECT * FROM tenants WHERE custom_domain = :host LIMIT 1');
            $statement->execute(['host' => $host]);
            $tenant = $statement->fetch(PDO::FETCH_ASSOC);
            if ($tenant) {
                return $tenant;
            }
        }

        return null;
    }
}
