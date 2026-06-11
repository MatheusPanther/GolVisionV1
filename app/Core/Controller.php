<?php

declare(strict_types=1);

namespace App\Core;

abstract class Controller
{
    protected function render(string $view, array $data = [], string $layout = 'main'): void
    {
        View::render($view, $data, $layout);
    }

    protected function redirect(string $path): void
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $path) === 1) {
            header('Location: ' . $path);
            exit;
        }

        header('Location: ' . \route($path));
        exit;
    }

    protected function json(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    protected function requestJson(): array
    {
        $raw = file_get_contents('php://input');
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    protected function requirePost(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            http_response_code(405);
            exit('Method Not Allowed');
        }
    }

    protected function verifyCsrf(): void
    {
        $token = $_POST['_token'] ?? null;
        if (!Csrf::verify(is_string($token) ? $token : null)) {
            http_response_code(419);
            exit('Invalid CSRF token');
        }
    }
}
