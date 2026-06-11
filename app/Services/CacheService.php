<?php

declare(strict_types=1);

namespace App\Services;

final class CacheService
{
    private string $cachePath;

    public function __construct(?string $cachePath = null)
    {
        $this->cachePath = $cachePath ?? BASE_PATH . '/storage/cache';
    }

    public function remember(string $key, int $ttlSeconds, callable $callback): mixed
    {
        $path = $this->path($key);

        if (is_file($path)) {
            $payload = json_decode((string) file_get_contents($path), true);
            if (is_array($payload) && isset($payload['expires_at']) && (int) $payload['expires_at'] >= time()) {
                return $payload['data'] ?? null;
            }
        }

        $data = $callback();

        file_put_contents($path, (string) json_encode([
            'expires_at' => time() + $ttlSeconds,
            'data' => $data,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $data;
    }

    public function forget(string $key): void
    {
        $path = $this->path($key);
        if (is_file($path)) {
            unlink($path);
        }
    }

    private function path(string $key): string
    {
        return rtrim($this->cachePath, '/') . '/' . md5($key) . '.json';
    }
}
