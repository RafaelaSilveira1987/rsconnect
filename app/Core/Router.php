<?php

declare(strict_types=1);

namespace App\Core;

use App\Services\TenantModuleService;

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
        if ($middleware === 'auth' && !Auth::check()) {
            Flash::set('warning', 'Faça login para continuar.');
            $this->redirect('/login');
            return false;
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

        if ($middleware === 'csrf' && !Csrf::validate($_POST['_token'] ?? null)) {
            http_response_code(419);
            Flash::set('error', 'Sessão expirada. Atualize a página e tente novamente.');
            $this->redirect($_SERVER['HTTP_REFERER'] ?? '/');
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
        return $base . '/' . ltrim($path, '/');
    }
}
