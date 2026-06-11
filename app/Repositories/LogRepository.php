<?php

declare(strict_types=1);

namespace App\Repositories;

use Throwable;

final class LogRepository extends BaseRepository
{
    public function logOpenAI(
        ?int $userId,
        string $feature,
        string $model,
        int $inputTokens,
        int $outputTokens,
        float $estimatedCostUsd,
        string $status,
        ?string $referenceId = null,
        ?array $metadata = null
    ): void {
        try {
            $this->execute(
                'INSERT INTO openai_usage_logs
                 (user_id, feature, model, input_tokens, output_tokens, estimated_cost_usd, status, reference_id, metadata_json)
                 VALUES
                 (:user_id, :feature, :model, :input_tokens, :output_tokens, :estimated_cost_usd, :status, :reference_id, :metadata_json)',
                [
                    'user_id' => $userId,
                    'feature' => $feature,
                    'model' => $model,
                    'input_tokens' => $inputTokens,
                    'output_tokens' => $outputTokens,
                    'estimated_cost_usd' => $estimatedCostUsd,
                    'status' => $status,
                    'reference_id' => $referenceId,
                    'metadata_json' => $metadata !== null ? $this->encodeJson($metadata) : null,
                ]
            );
        } catch (Throwable $exception) {
            if (function_exists('app_log')) {
                app_log('warning', 'Falha ao gravar openai_usage_logs.', [
                    'feature' => $feature,
                    'model' => $model,
                    'exception' => $exception->getMessage(),
                ]);
            }
        }
    }

    public function logApiError(string $service, string $endpoint, string $message): void
    {
        try {
            $this->execute(
                'INSERT INTO api_error_logs (service, endpoint, message)
                 VALUES (:service, :endpoint, :message)',
                [
                    'service' => $service,
                    'endpoint' => $endpoint,
                    'message' => $message,
                ]
            );
        } catch (Throwable $exception) {
            if (function_exists('app_log')) {
                app_log('warning', 'Falha ao gravar api_error_logs.', [
                    'service' => $service,
                    'endpoint' => $endpoint,
                    'message' => $message,
                    'exception' => $exception->getMessage(),
                ]);
            }
        }
    }

    public function countFeatureUsageToday(int $userId, string $feature): int
    {
        $row = $this->fetchOne(
            'SELECT COUNT(*) AS total
             FROM openai_usage_logs
             WHERE user_id = :user_id
             AND feature = :feature
             AND status IN ("success", "fallback")
             AND DATE(created_at) = CURDATE()',
            [
                'user_id' => $userId,
                'feature' => $feature,
            ]
        );

        return (int) ($row['total'] ?? 0);
    }

    public function usageSummary(): array
    {
        try {
            $totals = $this->fetchOne(
                'SELECT COUNT(*) AS calls,
                        COALESCE(SUM(input_tokens), 0) AS input_tokens,
                        COALESCE(SUM(output_tokens), 0) AS output_tokens,
                        COALESCE(SUM(estimated_cost_usd), 0) AS estimated_cost_usd
                 FROM openai_usage_logs'
            ) ?? [];

            $byFeature = $this->fetchAll(
                'SELECT feature, COUNT(*) AS total, COALESCE(SUM(estimated_cost_usd), 0) AS estimated_cost_usd
                 FROM openai_usage_logs
                 GROUP BY feature
                 ORDER BY total DESC'
            );
        } catch (Throwable $exception) {
            if (function_exists('app_log')) {
                app_log('warning', 'Falha ao consultar resumo de uso OpenAI.', [
                    'exception' => $exception->getMessage(),
                ]);
            }

            return [
                'totals' => [],
                'by_feature' => [],
            ];
        }

        return [
            'totals' => $totals,
            'by_feature' => $byFeature,
        ];
    }

    public function apiErrors(int $limit = 20): array
    {
        try {
            return $this->fetchAll(
                'SELECT * FROM api_error_logs
                 ORDER BY created_at DESC
                 LIMIT ' . (int) $limit
            );
        } catch (Throwable $exception) {
            if (function_exists('app_log')) {
                app_log('warning', 'Falha ao consultar api_error_logs.', [
                    'exception' => $exception->getMessage(),
                ]);
            }

            return [];
        }
    }

    public function latestApiErrorMessage(?string $service = null, int $recentMinutes = 30): ?string
    {
        try {
            $sql = 'SELECT message
                    FROM api_error_logs
                    WHERE created_at >= DATE_SUB(NOW(), INTERVAL ' . max(1, $recentMinutes) . ' MINUTE)';
            $params = [];

            if ($service !== null && $service !== '') {
                $sql .= ' AND service = :service';
                $params['service'] = $service;
            }

            $sql .= ' ORDER BY created_at DESC
                      LIMIT 1';

            $row = $this->fetchOne($sql, $params);

            return is_string($row['message'] ?? null) ? $row['message'] : null;
        } catch (Throwable $exception) {
            if (function_exists('app_log')) {
                app_log('warning', 'Falha ao consultar ultimo erro de API.', [
                    'service' => $service,
                    'exception' => $exception->getMessage(),
                ]);
            }

            return null;
        }
    }
}
