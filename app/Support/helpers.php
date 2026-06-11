<?php

declare(strict_types=1);

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Session;

function env(string $key, mixed $default = null): mixed
{
    return $_ENV[$key] ?? $_SERVER[$key] ?? $default;
}

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function asset(string $path): string
{
    $cleanPath = ltrim($path, '/');
    $documentRoot = isset($_SERVER['DOCUMENT_ROOT']) ? rtrim((string) $_SERVER['DOCUMENT_ROOT'], DIRECTORY_SEPARATOR) : '';
    $directAssetFile = $documentRoot !== '' ? $documentRoot . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $cleanPath) : '';
    $projectRoot = realpath(BASE_PATH) ?: BASE_PATH;
    $publicRoot = realpath(BASE_PATH . '/public') ?: (BASE_PATH . '/public');
    $documentRootReal = $documentRoot !== '' ? (realpath($documentRoot) ?: $documentRoot) : '';

    if ($documentRootReal !== '' && str_starts_with($directAssetFile, $documentRoot) && is_file($directAssetFile)) {
        return '/assets/' . $cleanPath;
    }

    if ($documentRootReal === $projectRoot || ($documentRootReal !== '' && $documentRootReal !== $publicRoot)) {
        return '/public/assets/' . $cleanPath;
    }

    return '/assets/' . $cleanPath;
}

function normalize_internal_path(string $path): string
{
    $normalized = parse_url($path, PHP_URL_PATH) ?: '/';

    foreach (['/public/index.php', '/index.php'] as $frontControllerPrefix) {
        if ($normalized === $frontControllerPrefix) {
            return '/';
        }

        if (str_starts_with($normalized, $frontControllerPrefix . '/')) {
            $normalized = substr($normalized, strlen($frontControllerPrefix)) ?: '/';
            break;
        }
    }

    if ($normalized !== '/') {
        $normalized = '/' . trim($normalized, '/');
    }

    return $normalized !== '' ? $normalized : '/';
}

function app_entry_prefix(): string
{
    static $prefix;

    if (is_string($prefix)) {
        return $prefix;
    }

    $documentRoot = isset($_SERVER['DOCUMENT_ROOT']) ? rtrim((string) $_SERVER['DOCUMENT_ROOT'], DIRECTORY_SEPARATOR) : '';
    $projectRoot = realpath(BASE_PATH) ?: BASE_PATH;
    $publicRoot = realpath(BASE_PATH . '/public') ?: (BASE_PATH . '/public');
    $documentRootReal = $documentRoot !== '' ? (realpath($documentRoot) ?: $documentRoot) : '';

    if ($documentRootReal === $publicRoot) {
        $prefix = '';
        return $prefix;
    }

    if ($documentRootReal === $projectRoot || ($documentRootReal !== '' && $documentRootReal !== $publicRoot)) {
        $prefix = '/index.php';
        return $prefix;
    }

    $prefix = '';

    return $prefix;
}

function route(string $path = '/', array $query = []): string
{
    $parts = parse_url($path);
    $normalizedPath = normalize_internal_path((string) ($parts['path'] ?? '/'));
    $existingQuery = [];

    if (isset($parts['query'])) {
        parse_str($parts['query'], $existingQuery);
    }

    $query = array_merge($existingQuery, $query);
    $queryString = $query !== [] ? '?' . http_build_query($query) : '';
    $fragment = isset($parts['fragment']) && $parts['fragment'] !== '' ? '#' . $parts['fragment'] : '';
    $prefix = app_entry_prefix();

    if ($prefix === '') {
        return ($normalizedPath === '/' ? '/' : $normalizedPath) . $queryString . $fragment;
    }

    return ($normalizedPath === '/' ? $prefix : $prefix . $normalizedPath) . $queryString . $fragment;
}

function storage_path(string $path = ''): string
{
    $base = BASE_PATH . '/storage';

    if ($path === '') {
        return $base;
    }

    return $base . '/' . ltrim($path, '/');
}

function app_log(string $level, string $message, array $context = []): void
{
    $directory = storage_path('logs');
    if (!is_dir($directory)) {
        @mkdir($directory, 0775, true);
    }

    $payload = [
        'time' => date('c'),
        'level' => strtoupper($level),
        'message' => $message,
        'context' => $context,
    ];

    @file_put_contents(
        storage_path('logs/app.log'),
        json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL,
        FILE_APPEND
    );
}

function friendly_service_message(string $message, string $fallbackMessage): string
{
    $message = strtolower($message);

    if (
        str_contains($message, 'unknown column') ||
        str_contains($message, '42s22') ||
        str_contains($message, '1054')
    ) {
        return 'O MySQL conectou, mas a estrutura do banco esta desatualizada para esta versao do sistema. Importe novamente o database/schema.sql ou aplique as colunas novas no banco da hospedagem.';
    }

    if (
        str_contains($message, 'base table') ||
        str_contains($message, 'doesn\'t exist') ||
        str_contains($message, 'does not exist')
    ) {
        return 'O MySQL conectou, mas as tabelas do sistema ainda nao existem. Importe o arquivo database/schema.sql no phpMyAdmin.';
    }

    if (
        str_contains($message, 'access denied') ||
        str_contains($message, '1045')
    ) {
        return 'O MySQL recusou o acesso. Isso normalmente acontece por usuario, senha ou host incorreto no banco. Revise DB_HOST, DB_USER e DB_PASS na hospedagem e confira se nao existe um .env antigo sobrescrevendo esses dados.';
    }

    if (
        str_contains($message, 'unknown database') ||
        str_contains($message, '1049')
    ) {
        return 'O banco configurado em DB_NAME nao foi encontrado na hospedagem.';
    }

    if (
        str_contains($message, 'server has gone away') ||
        str_contains($message, 'lost connection') ||
        str_contains($message, '2006') ||
        str_contains($message, '2013')
    ) {
        return 'A conexao com o MySQL caiu durante a operacao. O sistema vai tentar reconectar automaticamente nas proximas chamadas, mas voce ja pode tentar gerar o bilhete novamente.';
    }

    if (
        str_contains($message, 'incorrect datetime value') ||
        str_contains($message, '1292')
    ) {
        return 'O MySQL conectou, mas rejeitou o formato de data recebido na sincronizacao dos jogos. Isso normalmente aponta para estrutura antiga do banco ou incompatibilidade de coluna.';
    }

    if (
        str_contains($message, 'doesn\'t have a default value') ||
        str_contains($message, 'does not have a default value') ||
        str_contains($message, '1364')
    ) {
        return 'O MySQL conectou, mas faltam colunas obrigatorias para esta versao do sistema. Atualize a estrutura do banco com o database/schema.sql.';
    }

    if (
        str_contains($message, 'invalid json text') ||
        str_contains($message, '3140')
    ) {
        return 'O MySQL conectou, mas a coluna JSON do banco nao aceitou os dados da sincronizacao. Isso costuma indicar schema antigo ou incompatibilidade do banco.';
    }

    if (
        str_contains($message, 'connection refused') ||
        str_contains($message, 'php_network_getaddresses') ||
        str_contains($message, 'getaddrinfo') ||
        str_contains($message, '2002') ||
        str_contains($message, 'no such file or directory')
    ) {
        return 'Nao foi possivel conectar ao MySQL. Revise principalmente o DB_HOST e a porta do banco na Hostinger.';
    }

    if (
        str_contains($message, '401') ||
        str_contains($message, '403') ||
        str_contains($message, 'unauthorized') ||
        str_contains($message, 'invalid api token') ||
        str_contains($message, 'api token')
    ) {
        return 'A API recusou a autenticacao. Revise a chave da SportMonks/OpenAI e confirme se ela ainda esta ativa no painel.';
    }

    if (
        str_contains($message, '429') ||
        str_contains($message, 'too many requests') ||
        str_contains($message, 'rate limit')
    ) {
        return 'A API atingiu limite temporario de requisicoes. Aguarde um pouco e tente novamente.';
    }

    if (
        str_contains($message, 'timed out') ||
        str_contains($message, 'timeout') ||
        str_contains($message, 'operation timed out')
    ) {
        return 'A API demorou mais do que o esperado para responder. Tente novamente em instantes.';
    }

    if (
        str_contains($message, 'http 500') ||
        str_contains($message, 'http 502') ||
        str_contains($message, 'http 503') ||
        str_contains($message, 'http 504')
    ) {
        return 'A API do provedor esta instavel no momento. Tente novamente em alguns minutos.';
    }

    return $fallbackMessage;
}

function friendly_database_error(\Throwable $exception, string $fallbackMessage): string
{
    return friendly_service_message($exception->getMessage(), $fallbackMessage);
}

function view_partial(string $path, array $data = []): void
{
    $partialPath = BASE_PATH . '/app/Views/partials/' . $path . '.php';
    if (!is_file($partialPath)) {
        return;
    }

    extract($data, EXTR_SKIP);
    require $partialPath;
}

function current_user(): ?array
{
    return Auth::user();
}

function configured_admin_emails(): array
{
    $raw = explode(',', (string) env('ADMIN_EMAILS', ''));

    return array_values(array_filter(array_map(static function (string $email): string {
        return strtolower(trim($email));
    }, $raw), static function (string $email): bool {
        return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }));
}

function admin_display_name_from_email(string $email): string
{
    $localPart = strtolower(trim(strtok($email, '@') ?: 'admin'));
    $normalized = preg_replace('/[._-]+/', ' ', $localPart) ?: $localPart;

    return ucwords($normalized);
}

function sync_configured_admin_accounts(): void
{
    static $synced = false;

    if ($synced) {
        return;
    }

    $synced = true;
    $emails = configured_admin_emails();
    $password = (string) env('ADMIN_DEFAULT_PASSWORD', '');
    $plan = (string) env('ADMIN_DEFAULT_PLAN', 'pro');

    if ($emails === [] || $password === '') {
        return;
    }

    try {
        $repository = new \App\Repositories\UserRepository();

        foreach ($emails as $email) {
            $repository->ensureAdminAccount($email, $password, admin_display_name_from_email($email), $plan);
        }
    } catch (\Throwable $exception) {
        app_log('error', 'Falha ao sincronizar contas admin configuradas.', [
            'emails' => $emails,
            'exception' => $exception->getMessage(),
        ]);
    }
}

function csrf_input(): string
{
    return '<input type="hidden" name="_token" value="' . e(Csrf::token()) . '">';
}

function flash(string $key, ?string $message = null): ?string
{
    if ($message !== null) {
        Session::put('_flash_' . $key, $message);
        return null;
    }

    $value = Session::get('_flash_' . $key);
    Session::forget('_flash_' . $key);

    return is_string($value) ? $value : null;
}

function old(string $key, string $default = ''): string
{
    return (string) Session::get('_old_' . $key, $default);
}

function remember_old(array $data): void
{
    foreach ($data as $key => $value) {
        if (is_scalar($value)) {
            Session::put('_old_' . (string) $key, (string) $value);
        }
    }
}

function clear_old(array $keys): void
{
    foreach ($keys as $key) {
        Session::forget('_old_' . $key);
    }
}

function request_path(): string
{
    return normalize_internal_path($_SERVER['REQUEST_URI'] ?? '/');
}

function is_active_path(string $path): bool
{
    $current = request_path();

    return $current === $path || str_starts_with($current, rtrim($path, '/') . '/');
}

function selected_if(mixed $left, mixed $right): string
{
    return (string) $left === (string) $right ? 'selected' : '';
}

function checked_if(bool $condition): string
{
    return $condition ? 'checked' : '';
}

function format_percent(mixed $value): string
{
    return (int) round((float) $value) . '%';
}

function plan_label(string $plan): string
{
    return match ($plan) {
        'beta' => 'Beta',
        'pro' => 'Pro',
        default => 'Free',
    };
}
