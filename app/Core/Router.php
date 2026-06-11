<?php
declare(strict_types=1);
namespace App\Core;
use Throwable;
final class Router
{
    private array $routes = [];
    public function get(string $path, array $handler): void
    {
        $this->map('GET', $path, $handler);
    }
    public function post(string $path, array $handler): void
    {
        $this->map('POST', $path, $handler);
    }
    public function dispatch(string $method, string $uri): void
    {
        $routes = $this->routes[$method] ?? [];
        foreach ($routes as $route) {
            $pattern = preg_replace('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', '(?P<$1>[^/]+)', $route['path']);
            $pattern = '#^' . $pattern . '$#';
            if (!preg_match($pattern, $uri, $matches)) {
                continue;
            }
            $params = array_filter($matches, static fn ($key) => !is_int($key), ARRAY_FILTER_USE_KEY);
            [$class, $action] = $route['handler'];
            $controller = new $class();
            try {
                $controller->{$action}(...array_values($params));
            } catch (Throwable $exception) {
                if (function_exists('app_log')) {
                    app_log('error', 'Falha ao despachar rota.', [
                        'method' => $method,
                        'uri' => $uri,
                        'controller' => $class,
                        'action' => $action,
                        'params' => $params,
                        'exception' => $exception->getMessage(),
                    ]);
                }
                http_response_code(500);
                echo 'Internal Server Error.';
            }
            return;
        }
        http_response_code(404);
        echo 'Page not found.';
    }
    private function map(string $method, string $path, array $handler): void
    {
        $this->routes[$method][] = [
            'path' => $path,
            'handler' => $handler,
        ];
    }
}
