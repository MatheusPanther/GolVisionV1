<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

require BASE_PATH . '/app/Core/Env.php';
require BASE_PATH . '/app/Core/Config.php';
require BASE_PATH . '/app/Core/Database.php';
require BASE_PATH . '/app/Core/Session.php';
require BASE_PATH . '/app/Core/Csrf.php';
require BASE_PATH . '/app/Core/View.php';
require BASE_PATH . '/app/Core/Router.php';
require BASE_PATH . '/app/Core/Controller.php';
require BASE_PATH . '/app/Core/Auth.php';
require BASE_PATH . '/app/Support/helpers.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = BASE_PATH . '/app/' . str_replace('\\', '/', $relative) . '.php';

    if (is_file($path)) {
        require $path;
    }
});

App\Core\Env::load(BASE_PATH . '/.env');
App\Core\Env::load(BASE_PATH . '/.env.local');

date_default_timezone_set(env('APP_TIMEZONE', 'America/Maceio'));

App\Core\Session::start();

$router = new App\Core\Router();

require BASE_PATH . '/config/routes.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'HEAD') {
    $method = 'GET';
}

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

// Remove /index.php ou /public/index.php do inicio da URI
foreach (['/public/index.php', '/index.php'] as $prefix) {
    if ($uri === $prefix) {
        $uri = '/';
        break;
    }
    if (str_starts_with($uri, $prefix . '/')) {
        $uri = substr($uri, strlen($prefix));
        break;
    }
}

// Remove prefixo do SCRIPT_NAME se necessário
$baseScript = dirname($_SERVER['SCRIPT_NAME'] ?? '');
if ($baseScript !== '/' && $baseScript !== '.' && str_starts_with($uri, $baseScript)) {
    $uri = substr($uri, strlen($baseScript)) ?: '/';
}

$uri = normalize_internal_path($uri);

$router->dispatch($method, $uri);
