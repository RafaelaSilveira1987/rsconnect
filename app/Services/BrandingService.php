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

            return self::buildFromTenant($tenant, $default);
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

            return self::buildFromTenant($tenant, $default);
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
            'tenant_identity' => false,
            'tenant_id' => 0,
            'app_name' => 'RS Connect',
            'subtitle' => 'Atendimento e Comercial',
            'icon_text' => 'RS',
            'logo_url' => '',
            'icon_url' => '',
            'favicon_url' => '',
            'logo_variant' => 'horizontal',
            'logo_background' => 'transparent',
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

    private static function buildFromTenant(array $tenant, array $default): array
    {
        $tenantId = (int) ($tenant['id'] ?? 0);
        if ($tenantId < 1) {
            return $default;
        }

        $tenantName = trim((string) ($tenant['name'] ?? ''));
        if ($tenantName === '') {
            $tenantName = 'Empresa';
        }

        // O nome exibido vem do cadastro da empresa e não é um campo editável
        // do White Label. A única personalização enviada pelo administrador é a logo.
        $logoUrl = self::assetUrl((string) ($tenant['brand_logo_url'] ?? ''));

        return array_replace($default, [
            'enabled' => $logoUrl !== '',
            'tenant_identity' => true,
            'tenant_id' => $tenantId,
            'app_name' => $tenantName,
            'subtitle' => 'Atendimento e Comercial',
            'icon_text' => self::initials($tenantName),
            'logo_url' => $logoUrl,
        ]);
    }

    private static function initials(string $name): string
    {
        $words = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($words === []) {
            return 'EP';
        }

        $first = self::characterAt((string) $words[0], 0);
        $last = count($words) > 1
            ? self::characterAt((string) $words[count($words) - 1], 0)
            : self::characterAt((string) $words[0], 1);

        $initials = function_exists('mb_strtoupper')
            ? mb_strtoupper($first . $last, 'UTF-8')
            : strtoupper($first . $last);

        return $initials !== '' ? self::firstCharacters($initials, 2) : 'EP';
    }

    private static function characterAt(string $value, int $position): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($value, $position, 1, 'UTF-8');
        }

        preg_match_all('/./us', $value, $matches);
        return (string) ($matches[0][$position] ?? '');
    }

    private static function firstCharacters(string $value, int $length): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $length, 'UTF-8');
        }

        preg_match_all('/./us', $value, $matches);
        return implode('', array_slice($matches[0] ?? [], 0, $length));
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
