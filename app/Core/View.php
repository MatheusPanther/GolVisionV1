<?php

declare(strict_types=1);

namespace App\Core;

use Throwable;

final class View
{
    public static function render(string $view, array $data = [], string $layout = 'main'): void
    {
        $viewPath = BASE_PATH . '/app/Views/' . str_replace('.', '/', $view) . '.php';
        $layoutPath = BASE_PATH . '/app/Views/layouts/' . $layout . '.php';

        if (!is_file($viewPath) || !is_file($layoutPath)) {
            http_response_code(500);
            echo 'View not found.';
            return;
        }

        extract($data, EXTR_SKIP);

        try {
            ob_start();
            require $viewPath;
            $content = (string) ob_get_clean();

            require $layoutPath;
        } catch (Throwable $exception) {
            if (ob_get_level() > 0) {
                ob_end_clean();
            }

            if (function_exists('app_log')) {
                app_log('error', 'Falha ao renderizar view.', [
                    'view' => $view,
                    'layout' => $layout,
                    'exception' => $exception->getMessage(),
                ]);
            }

            throw $exception;
        }
    }
}
