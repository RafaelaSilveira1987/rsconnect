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
            if (!$tenant) {
                return $default;
            }

            if ((int) ($tenant['white_label_enabled'] ?? 0) !== 1) {
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
        if ($path === '') {
            return '';
        }

        if (preg_match('/^https?:\/\//i', $path) === 1 || str_starts_with($path, 'data:')) {
            return $path;
        }

        return '/' . ltrim($path, '/');
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
            'favicon_url' => '',
            'primary' => '#146498',
            'secondary' => '#631b7c',
            'accent' => '#01c5b6',
            'login_title' => 'Controle sua operação de WhatsApp em uma plataforma profissional.',
            'login_subtitle' => 'Multiempresa, agentes de IA, agenda, CRM, cobrança, n8n e atendimento humano trabalhando juntos.',
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

        $brandName = trim((string) ($tenant['brand_name'] ?? ''));
        $brandSubtitle = trim((string) ($tenant['brand_subtitle'] ?? ''));
        $iconText = trim((string) ($tenant['brand_icon_text'] ?? ''));
        $logoUrl = self::assetUrl((string) ($tenant['brand_logo_url'] ?? ''));
        $faviconUrl = self::assetUrl((string) ($tenant['brand_favicon_url'] ?? ''));

        return [
            'enabled' => true,
            'tenant_id' => (int) ($tenant['id'] ?? 0),
            'app_name' => $brandName !== '' ? $brandName : (string) ($tenant['name'] ?? $default['app_name']),
            'subtitle' => $brandSubtitle !== '' ? $brandSubtitle : 'Atendimento e CRM',
            'icon_text' => $iconText !== '' ? mb_substr($iconText, 0, 4) : self::initials((string) ($tenant['name'] ?? 'RS')),
            'logo_url' => $logoUrl,
            'favicon_url' => $faviconUrl,
            'primary' => self::color((string) ($tenant['brand_primary_color'] ?? ''), $default['primary']),
            'secondary' => self::color((string) ($tenant['brand_secondary_color'] ?? ''), $default['secondary']),
            'accent' => self::color((string) ($tenant['brand_accent_color'] ?? ''), $default['accent']),
            'login_title' => trim((string) ($tenant['login_title'] ?? '')) ?: $default['login_title'],
            'login_subtitle' => trim((string) ($tenant['login_subtitle'] ?? '')) ?: $default['login_subtitle'],
            'footer_text' => trim((string) ($tenant['brand_footer_text'] ?? '')) ?: '',
            'support_email' => trim((string) ($tenant['support_email'] ?? '')),
            'show_powered_by' => (int) ($tenant['show_powered_by'] ?? 1) === 1,
        ];
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
        $host = preg_replace('/:\\d+$/', '', $host) ?: $host;
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

    private static function color(string $value, string $fallback): string
    {
        $value = trim($value);
        return preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1 ? $value : $fallback;
    }

    private static function initials(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return 'RS';
        }
        $parts = preg_split('/\\s+/', $name) ?: [];
        $first = mb_substr((string) ($parts[0] ?? 'R'), 0, 1);
        $second = mb_substr((string) ($parts[1] ?? ($parts[0] ?? 'S')), 0, 1);
        return mb_strtoupper($first . $second);
    }
}
