<?php

declare(strict_types=1);

namespace App\Core;

use App\Repositories\UserRepository;

final class Auth
{
    public static function user(): ?array
    {
        $user = Session::get('auth_user');
        return is_array($user) ? $user : null;
    }

    public static function id(): ?int
    {
        $user = self::user();
        return isset($user['id']) ? (int) $user['id'] : null;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function attempt(string $email, string $password): bool
    {
        $repository = new UserRepository();
        $user = $repository->findByEmail($email);

        if (!$user || !password_verify($password, $user['password_hash'])) {
            return false;
        }

        unset($user['password_hash']);
        Session::put('auth_user', $user);
        return true;
    }

    public static function login(array $user): void
    {
        unset($user['password_hash']);
        Session::put('auth_user', $user);
    }

    public static function logout(): void
    {
        Session::forget('auth_user');
    }

    public static function requireLogin(): void
    {
        if (!self::check()) {
            header('Location: ' . \route('/login'));
            exit;
        }
    }

    public static function isAdmin(): bool
    {
        $user = self::user();
        if (!$user || !isset($user['email'])) {
            return false;
        }

        $allowed = explode(',', (string) env('ADMIN_EMAILS', ''));
        $allowed = array_map(static fn (string $value): string => strtolower(trim($value)), $allowed);

        return in_array(strtolower((string) $user['email']), $allowed, true);
    }
}
