<?php

declare(strict_types=1);

namespace App\Core;

final class Csrf
{
    public static function token(): string
    {
        $token = Session::get('_csrf_token');
        if (is_string($token) && $token !== '') {
            return $token;
        }

        $token = bin2hex(random_bytes(24));
        Session::put('_csrf_token', $token);
        return $token;
    }

    public static function verify(?string $token): bool
    {
        $expected = Session::get('_csrf_token');
        return is_string($expected) && is_string($token) && hash_equals($expected, $token);
    }
}
