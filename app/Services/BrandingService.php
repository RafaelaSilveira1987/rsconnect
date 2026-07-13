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
            'icon_url' => '',
            'favicon_url' => '',
            'logo_variant' => 'square',
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

        $brandName = trim((string) ($tenant['brand_name'] ?? ''));
        $brandSubtitle = trim((string) ($tenant['brand_subtitle'] ?? ''));
        $iconText = trim((string) ($tenant['brand_icon_text'] ?? ''));
        $logoUrl = self::assetUrl((string) ($tenant['brand_logo_url'] ?? ''));
        $iconUrl = self::assetUrl((string) ($tenant['brand_icon_url'] ?? ''));
        $faviconUrl = self::assetUrl((string) ($tenant['brand_favicon_url'] ?? ''));

        $benefits = [
            trim((string) ($tenant['login_benefit_1'] ?? '')),
            trim((string) ($tenant['login_benefit_2'] ?? '')),
            trim((string) ($tenant['login_benefit_3'] ?? '')),
        ];
        $benefits = array_values(array_filter($benefits, static fn (string $v): bool => $v !== ''));
        if ($benefits === []) {
            $benefits = $default['login_benefits'];
        }

        $logoVariant = trim((string) ($tenant['brand_logo_variant'] ?? ''));
        if (!in_array($logoVariant, ['horizontal', 'square', 'symbol'], true)) {
            $logoVariant = $default['logo_variant'];
        }

        $logoBackground = trim((string) ($tenant['brand_logo_background'] ?? ''));
        if (!in_array($logoBackground, ['light', 'transparent', 'brand'], true)) {
            $logoBackground = $default['logo_background'];
        }

        return [
            'enabled' => true,
            'tenant_id' => (int) ($tenant['id'] ?? 0),
            'app_name' => $brandName !== '' ? $brandName : (string) ($tenant['name'] ?? $default['app_name']),
            'subtitle' => $brandSubtitle !== '' ? $brandSubtitle : 'Atendimento e CRM',
            'icon_text' => $iconText !== '' ? mb_substr($iconText, 0, 4) : self::initials((string) ($tenant['name'] ?? 'RS')),
            'logo_url' => $logoUrl,
            'icon_url' => $iconUrl,
            'favicon_url' => $faviconUrl,
            'logo_variant' => $logoVariant,
            'logo_background' => $logoBackground,
            'primary' => self::color((string) ($tenant['brand_primary_color'] ?? ''), $default['primary']),
            'secondary' => self::color((string) ($tenant['brand_secondary_color'] ?? ''), $default['secondary']),
            'accent' => self::color((string) ($tenant['brand_accent_color'] ?? ''), $default['accent']),
            'login_bg' => self::color((string) ($tenant['login_background_color'] ?? ''), $default['login_bg']),
            'login_text' => self::color((string) ($tenant['login_text_color'] ?? ''), $default['login_text']),
            'login_eyebrow' => trim((string) ($tenant['login_eyebrow'] ?? '')) ?: ($brandSubtitle ?: $default['login_eyebrow']),
            'login_title' => trim((string) ($tenant['login_title'] ?? '')) ?: 'Acesse o painel da ' . ($brandName !== '' ? $brandName : (string) ($tenant['name'] ?? $default['app_name'])),
            'login_subtitle' => trim((string) ($tenant['login_subtitle'] ?? '')) ?: 'Gerencie atendimento, relacionamento e operação em um ambiente seguro e personalizado para sua empresa.',
            'login_button_text' => trim((string) ($tenant['login_button_text'] ?? '')) ?: $default['login_button_text'],
            'login_benefits' => $benefits,
            'login_security_text' => trim((string) ($tenant['login_security_text'] ?? '')) ?: $default['login_security_text'],
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
        $parts = preg_split('/\s+/', $name) ?: [];
        $first = mb_substr((string) ($parts[0] ?? 'R'), 0, 1);
        $second = mb_substr((string) ($parts[1] ?? ($parts[0] ?? 'S')), 0, 1);
        return mb_strtoupper($first . $second);
    }
}
