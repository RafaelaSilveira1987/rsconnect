<?php

declare(strict_types=1);

namespace App\Core;

use App\Services\TenantModuleService;
use App\Services\AccessControlService;
use App\Services\SecurityService;
use App\Services\PrivacyService;
use App\Services\OnboardingGuideService;
use App\Services\TenantIsolationService;

final class Router
{
    private array $routes = [];

    public function get(string $path, callable|array $handler, array $middleware = []): void
    {
        $this->add('GET', $path, $handler, $middleware);
    }

    public function post(string $path, callable|array $handler, array $middleware = []): void
    {
        $this->add('POST', $path, $handler, $middleware);
    }

    private function add(string $method, string $path, callable|array $handler, array $middleware): void
    {
        $this->routes[$method][$this->normalize($path)] = compact('handler', 'middleware');
    }

    public function dispatch(string $method, string $uri): void
    {
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $scriptDirectory = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
        if ($scriptDirectory !== '' && $scriptDirectory !== '/' && str_starts_with($path, $scriptDirectory)) {
            $path = substr($path, strlen($scriptDirectory)) ?: '/';
        }

        if (preg_match('~^/https?://~i', $path) === 1) {
            $embeddedUrl = rawurldecode(substr($path, 1));
            $this->redirect(self::safeInternalPath($embeddedUrl, '/login'));
        }

        $queryString = (string) (parse_url($uri, PHP_URL_QUERY) ?? '');
        $internalUri = $path . ($queryString !== '' ? '?' . $queryString : '');
        $query = [];
        if ($queryString !== '') {
            parse_str($queryString, $query);
        }

        // Legacy numeric GET links remain compatible, but are immediately
        // redirected to their UUID-based canonical URL.
        if (strtoupper($method) === 'GET'
            && !str_starts_with($this->normalize($path), '/webhooks/')
            && PublicId::hasLegacyPublicQuery($this->normalize($path), $query)) {
            $canonical = PublicId::publicizePath($internalUri);
            if ($canonical !== $internalUri) {
                $this->redirect($canonical);
            }
        }

        // Controllers continue working with numeric IDs internally. Public
        // UUID aliases are decoded before middleware and controller execution.
        if (!PublicId::hydrateRequest($this->normalize($path))) {
            http_response_code(404);
            View::render('errors.404', ['title' => 'Registro não encontrado'], Auth::check() ? 'app' : 'guest');
            return;
        }

        $route = $this->routes[strtoupper($method)][$this->normalize($path)] ?? null;
        if ($route === null) {
            http_response_code(404);
            View::render('errors.404', ['title' => 'Página não encontrada'], Auth::check() ? 'app' : 'guest');
            return;
        }

        foreach ($route['middleware'] as $middleware) {
            if (!$this->runMiddleware($middleware)) {
                return;
            }
        }

        if (Auth::check() && !Auth::isSuperAdmin()) {
            $isolation = (new TenantIsolationService())->validateAuthenticatedRequest(
                $this->normalize($path),
                $_GET,
                $_POST
            );
            if (empty($isolation['allowed'])) {
                (new SecurityService())->recordEvent('tenant.cross_scope_access_blocked', 'critical', [
                    'path' => $this->normalize($path),
                    'method' => strtoupper($method),
                    'violations' => $isolation['violations'] ?? [],
                ]);
                http_response_code(404);
                View::render('errors.404', ['title' => 'Registro não encontrado'], 'app');
                return;
            }
        }

        $handler = $route['handler'];
        if (is_array($handler)) {
            [$class, $action] = $handler;
            (new $class())->{$action}();
            return;
        }

        $handler();
    }

    private function runMiddleware(string $middleware): bool
    {
        if ($middleware === 'auth') {
            if (!Auth::check()) {
                Flash::set('warning', 'Faça login para continuar.');
                $this->redirect('/login');
                return false;
            }

            if (!(new SecurityService())->enforceAuthenticatedSession()) {
                Flash::set('warning', 'Sua sessão expirou ou foi encerrada. Faça login novamente.');
                $this->redirect('/login');
                return false;
            }

            $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
            if (!Auth::isSuperAdmin() && Auth::tenantId()) {
                $normalizedPath = $this->normalize($path);
                $accessExempt = in_array($normalizedPath, ['/access-restricted', '/subscription', '/logout'], true);
                $accessService = new AccessControlService();
                $accessStatus = $accessService->statusForTenant((int) Auth::tenantId());
                $_SESSION['tenant_access_status'] = $accessStatus;

                if (empty($accessStatus['allowed']) && !$accessExempt) {
                    $accessService->recordBlockedAccess($accessStatus, 'web');
                    $this->redirect('/access-restricted');
                    return false;
                }

                if (!empty($accessStatus['allowed']) && $normalizedPath === '/access-restricted') {
                    $this->redirect('/');
                    return false;
                }
            }

            if (!Auth::isSuperAdmin() && Auth::tenantId()) {
                $normalizedPath = $this->normalize($path);
                $guideService = new OnboardingGuideService();
                if ($guideService->requiresGuidedAccess((int) Auth::tenantId())
                    && !$guideService->pathAllowedDuringOnboarding((int) Auth::tenantId(), $normalizedPath, Auth::id())) {
                    Flash::set('warning', 'Conclua a etapa atual dos Primeiros passos para liberar as demais telas.');
                    $this->redirect('/onboarding');
                    return false;
                }
            }

            $normalizedPrivacyPath = $this->normalize($path);
            $privacyExempt = in_array($normalizedPrivacyPath, ['/privacy/accept', '/logout', '/access-restricted', '/subscription', '/webhooks/evolution', '/webhooks/n8n/callback', '/onboarding', '/primeiros-passos'], true)
                || str_starts_with($normalizedPrivacyPath, '/onboarding/');
            if (!$privacyExempt && !Auth::isSuperAdmin() && (new PrivacyService())->requiresAcceptance(Auth::tenantId(), Auth::id())) {
                Flash::set('warning', 'Leia e aceite os termos de privacidade/LGPD da sua empresa para continuar.');
                $this->redirect('/privacy/accept');
                return false;
            }
        }

        if ($middleware === 'guest' && Auth::check()) {
            $this->redirect('/');
            return false;
        }

        if ($middleware === 'super_admin' && !Auth::isSuperAdmin()) {
            http_response_code(403);
            Flash::set('error', 'Acesso permitido apenas ao Super Admin RS.');
            $this->redirect('/');
            return false;
        }

        if (str_starts_with($middleware, 'permission:')) {
            $permission = substr($middleware, strlen('permission:'));
            if (!Auth::can($permission)) {
                http_response_code(403);
                Flash::set('error', 'Seu perfil não possui permissão para esta ação.');
                $this->redirect('/');
                return false;
            }
            if (!Auth::isSuperAdmin() && Auth::tenantId()) {
                $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
                $subscriptionDuringBlock = $this->normalize($path) === '/subscription'
                    && empty($_SESSION['tenant_access_status']['allowed']);
                if (!$subscriptionDuringBlock) {
                    $moduleService = new TenantModuleService();
                    $module = $moduleService->moduleForPath($path);
                    if ($module !== null && !$moduleService->enabled((int) Auth::tenantId(), $module)) {
                        http_response_code(403);
                        Flash::set('warning', 'Este módulo está desativado para sua empresa.');
                        $this->redirect('/');
                        return false;
                    }
                }
            }
        }

        if ($middleware === 'csrf' && !Csrf::validate($_POST['_token'] ?? null)) {
            http_response_code(419);
            $currentPath = $this->normalize(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
            if ($currentPath === '/login') {
                Flash::set('warning', 'A página de login expirou após uma atualização. Digite seus dados novamente.');
                $this->redirect('/login');
                return false;
            }

            Flash::set('error', 'Sessão expirada. Atualize a página e tente novamente.');
            $this->redirect(self::safeInternalPath((string) ($_SERVER['HTTP_REFERER'] ?? ''), '/'));
            return false;
        }

        return true;
    }

    private function normalize(string $path): string
    {
        if ($path === '') {
            return '/';
        }
        $normalized = '/' . trim($path, '/');
        return $normalized === '//' ? '/' : $normalized;
    }

    private function redirect(string $path): never
    {
        header('Location: ' . self::url($path));
        exit;
    }

    public static function url(string $path = '/'): string
    {
        $base = rtrim((string) Env::get('APP_URL', ''), '/');
        $path = trim($path);
        if ($path === '') {
            $path = '/';
        }

        if (preg_match('~^https?://~i', $path) === 1 || str_starts_with($path, '//')) {
            $path = self::safeInternalPath($path, '/');
        }

        $path = PublicId::publicizePath($path);

        if ($base === '') {
            return '/' . ltrim($path, '/');
        }

        return $base . '/' . ltrim($path, '/');
    }

    private static function safeInternalPath(string $candidate, string $fallback = '/'): string
    {
        $candidate = trim($candidate);
        if ($candidate === '' || str_starts_with($candidate, '//')) {
            return $fallback;
        }

        $parts = parse_url($candidate);
        if ($parts === false) {
            return $fallback;
        }

        $isAbsolute = isset($parts['scheme']) || isset($parts['host']);
        $baseParts = parse_url((string) Env::get('APP_URL', ''));
        if ($isAbsolute) {
            if (!is_array($baseParts) || empty($baseParts['host'])) {
                return $fallback;
            }

            $candidateScheme = strtolower((string) ($parts['scheme'] ?? 'http'));
            $baseScheme = strtolower((string) ($baseParts['scheme'] ?? 'http'));
            $candidateHost = strtolower((string) ($parts['host'] ?? ''));
            $baseHost = strtolower((string) ($baseParts['host'] ?? ''));
            $candidatePort = (int) ($parts['port'] ?? ($candidateScheme === 'https' ? 443 : 80));
            $basePort = (int) ($baseParts['port'] ?? ($baseScheme === 'https' ? 443 : 80));

            if ($candidateScheme !== $baseScheme || $candidateHost !== $baseHost || $candidatePort !== $basePort) {
                return $fallback;
            }
        }

        $path = (string) ($parts['path'] ?? '/');
        if ($path === '') {
            $path = '/';
        }
        if (!str_starts_with($path, '/')) {
            $path = '/' . ltrim($path, '/');
        }
        if (str_starts_with($path, '//')) {
            return $fallback;
        }

        if ($isAbsolute && is_array($baseParts)) {
            $basePath = rtrim((string) ($baseParts['path'] ?? ''), '/');
            if ($basePath !== '' && ($path === $basePath || str_starts_with($path, $basePath . '/'))) {
                $path = substr($path, strlen($basePath)) ?: '/';
            }
        }

        $query = isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '';
        return $path . $query;
    }
}
