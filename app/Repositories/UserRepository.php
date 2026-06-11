<?php

declare(strict_types=1);

namespace App\Repositories;

final class UserRepository extends BaseRepository
{
    public function findByEmail(string $email): ?array
    {
        return $this->fetchOne('SELECT * FROM users WHERE email = :email LIMIT 1', [
            'email' => strtolower(trim($email)),
        ]);
    }

    public function findById(int $id): ?array
    {
        return $this->fetchOne('SELECT * FROM users WHERE id = :id LIMIT 1', ['id' => $id]);
    }

    public function create(array $data): array
    {
        $acceptedTermsAt = !empty($data['accepted_terms']) ? date('Y-m-d H:i:s') : null;
        $is18Confirmed = !empty($data['is_18_confirmed']) ? 1 : 0;

        $this->execute(
            'INSERT INTO users (email, password_hash, name, plan, accepted_terms_at, is_18_confirmed)
             VALUES (:email, :password_hash, :name, :plan, :accepted_terms_at, :is_18_confirmed)',
            [
                'email' => strtolower(trim((string) ($data['email'] ?? ''))),
                'password_hash' => password_hash((string) ($data['password'] ?? ''), PASSWORD_DEFAULT),
                'name' => trim((string) ($data['name'] ?? '')),
                'plan' => $data['plan'] ?? 'free',
                'accepted_terms_at' => $acceptedTermsAt,
                'is_18_confirmed' => $is18Confirmed,
            ]
        );

        $userId = $this->lastInsertId();

        $this->execute(
            'INSERT INTO user_preferences (user_id, preferred_leagues_json, preferred_markets_json, excluded_leagues_json)
             VALUES (:user_id, :preferred_leagues_json, :preferred_markets_json, :excluded_leagues_json)',
            [
                'user_id' => $userId,
                'preferred_leagues_json' => $this->encodeJson([]),
                'preferred_markets_json' => $this->encodeJson(['goals', 'btts', 'mixed']),
                'excluded_leagues_json' => $this->encodeJson([]),
            ]
        );

        return (array) $this->findById($userId);
    }

    public function getPreferences(int $userId): array
    {
        $row = $this->fetchOne('SELECT * FROM user_preferences WHERE user_id = :user_id LIMIT 1', [
            'user_id' => $userId,
        ]);

        if ($row === null) {
            return [
                'preferred_leagues' => [],
                'preferred_markets' => ['goals', 'btts', 'mixed'],
                'excluded_leagues' => [],
            ];
        }

        return [
            'preferred_leagues' => $this->decodeJson($row['preferred_leagues_json'] ?? null, []),
            'preferred_markets' => $this->decodeJson($row['preferred_markets_json'] ?? null, []),
            'excluded_leagues' => $this->decodeJson($row['excluded_leagues_json'] ?? null, []),
        ];
    }

    public function updateSettings(int $userId, array $data): void
    {
        $this->execute(
            'UPDATE users
             SET name = :name,
                 accepted_terms_at = CASE WHEN :accept_terms = 1 THEN COALESCE(accepted_terms_at, :accepted_terms_at) ELSE accepted_terms_at END,
                 is_18_confirmed = CASE WHEN :is_18_confirmed = 1 THEN 1 ELSE is_18_confirmed END
             WHERE id = :id',
            [
                'id' => $userId,
                'name' => trim((string) ($data['name'] ?? '')),
                'accept_terms' => !empty($data['accept_terms']) ? 1 : 0,
                'accepted_terms_at' => date('Y-m-d H:i:s'),
                'is_18_confirmed' => !empty($data['is_18_confirmed']) ? 1 : 0,
            ]
        );

        $this->execute(
            'INSERT INTO user_preferences (user_id, preferred_leagues_json, preferred_markets_json, excluded_leagues_json)
             VALUES (:user_id, :preferred_leagues_json, :preferred_markets_json, :excluded_leagues_json)
             ON DUPLICATE KEY UPDATE
             preferred_leagues_json = VALUES(preferred_leagues_json),
             preferred_markets_json = VALUES(preferred_markets_json),
             excluded_leagues_json = VALUES(excluded_leagues_json)',
            [
                'user_id' => $userId,
                'preferred_leagues_json' => $this->encodeJson($data['preferred_leagues'] ?? []),
                'preferred_markets_json' => $this->encodeJson($data['preferred_markets'] ?? []),
                'excluded_leagues_json' => $this->encodeJson($data['excluded_leagues'] ?? []),
            ]
        );
    }

    public function adminSummary(): array
    {
        return $this->fetchOne(
            'SELECT COUNT(*) AS total_users,
                    SUM(CASE WHEN plan = "free" THEN 1 ELSE 0 END) AS free_users,
                    SUM(CASE WHEN plan = "beta" THEN 1 ELSE 0 END) AS beta_users,
                    SUM(CASE WHEN plan = "pro" THEN 1 ELSE 0 END) AS pro_users,
                    SUM(CASE WHEN DATE(created_at) = CURDATE() THEN 1 ELSE 0 END) AS new_users_today
             FROM users'
        ) ?? [];
    }

    public function recentUsers(int $limit = 10): array
    {
        return $this->fetchAll(
            'SELECT id, email, name, plan, created_at
             FROM users
             ORDER BY created_at DESC
             LIMIT ' . (int) $limit
        );
    }

    public function ensureAdminAccount(string $email, string $password, string $name, string $plan = 'pro'): array
    {
        $normalizedEmail = strtolower(trim($email));
        $existing = $this->findByEmail($normalizedEmail);
        $payload = [
            'email' => $normalizedEmail,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'name' => trim($name) !== '' ? trim($name) : $normalizedEmail,
            'plan' => $plan !== '' ? $plan : 'pro',
            'accepted_terms_at' => date('Y-m-d H:i:s'),
        ];

        if ($existing !== null) {
            $this->execute(
                'UPDATE users
                 SET password_hash = :password_hash,
                     name = :name,
                     plan = :plan,
                     accepted_terms_at = COALESCE(accepted_terms_at, :accepted_terms_at),
                     is_18_confirmed = 1
                 WHERE id = :id',
                [
                    'id' => (int) $existing['id'],
                    ...$payload,
                ]
            );

            $userId = (int) $existing['id'];
        } else {
            $this->execute(
                'INSERT INTO users (email, password_hash, name, plan, accepted_terms_at, is_18_confirmed)
                 VALUES (:email, :password_hash, :name, :plan, :accepted_terms_at, 1)',
                $payload
            );

            $userId = $this->lastInsertId();
        }

        $this->execute(
            'INSERT INTO user_preferences (user_id, preferred_leagues_json, preferred_markets_json, excluded_leagues_json)
             VALUES (:user_id, :preferred_leagues_json, :preferred_markets_json, :excluded_leagues_json)
             ON DUPLICATE KEY UPDATE user_id = user_id',
            [
                'user_id' => $userId,
                'preferred_leagues_json' => $this->encodeJson([]),
                'preferred_markets_json' => $this->encodeJson(['goals', 'btts', 'mixed']),
                'excluded_leagues_json' => $this->encodeJson([]),
            ]
        );

        return (array) $this->findById($userId);
    }
}
