<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\LogRepository;
use RuntimeException;

final class OpenAIService
{
    private LogRepository $logs;

    public function __construct()
    {
        $this->logs = new LogRepository();
    }

    public function analyzeMatch(array $payload, ?int $userId = null): array
    {
        $model = (string) env('OPENAI_MODEL', 'gpt-4.1-mini');
        $messages = [
            [
                'role' => 'system',
                'content' => 'Voce e um analista esportivo especializado em futebol, estatistica, mercado de gols e leitura de jogo. Analise a partida usando exclusivamente os dados fornecidos no payload. Quando houver season_context, round_odds_context, fixture_context, match_centre, prediction_model, featured_players ou schedule_context, use isso para dar contexto ao desempenho recente, a forca relativa na temporada, a leitura do mercado, o xG da partida, o desgaste do calendario, o impacto de clima e desfalques, o modelo probabilistico e os destaques individuais. Nao invente escalacoes, odds, lesoes, previsoes ou estatisticas que nao estejam nos dados. Sua resposta deve ser probabilistica e informativa. Nunca prometa lucro, resultado certo, aposta garantida ou green garantido. Retorne JSON valido em portugues brasileiro com os campos pedidos.',
            ],
            [
                'role' => 'user',
                'content' => json_encode([
                    'instruction' => 'Analise a partida e retorne tendencia principal, probabilidades, risco, cenarios conservador/equilibrado/ousado, resumo e disclaimer de responsabilidade. Use contexto de temporada, comparativos da liga, leitura de odds da rodada, dados de xG/lineup da partida, calendario recente/proximo dos times, match centre, modelo preditivo, clima, desfalques e perfis resumidos de jogadores quando estiverem disponiveis.',
                    'payload' => $payload,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ],
        ];

        $response = $this->request($messages, $model);

        if ($response === null) {
            $fallback = $this->fallbackMatchAnalysis($payload);
            $this->logs->logOpenAI($userId, 'match_analysis', $model, 0, 0, 0.0, 'fallback', (string) ($payload['fixture_id'] ?? null), [
                'fallback' => true,
            ]);

            return $fallback;
        }

        $content = $response['choices'][0]['message']['content'] ?? null;
        if (!is_string($content)) {
            throw new RuntimeException('OpenAI response did not include content.');
        }

        $decoded = $this->extractJson($content);
        $validated = $this->validateMatchAnalysis($decoded);
        $usage = $response['usage'] ?? [];
        $estimatedCost = $this->estimateCost($model, (int) ($usage['prompt_tokens'] ?? 0), (int) ($usage['completion_tokens'] ?? 0));

        $this->logs->logOpenAI(
            $userId,
            'match_analysis',
            $model,
            (int) ($usage['prompt_tokens'] ?? 0),
            (int) ($usage['completion_tokens'] ?? 0),
            $estimatedCost,
            'success',
            (string) ($payload['fixture_id'] ?? null),
            ['fixture_id' => $payload['fixture_id'] ?? null]
        );

        return $validated;
    }

    public function generateSlip(array $payload, array $analyses, ?int $userId = null): array
    {
        $model = (string) env('OPENAI_MODEL', 'gpt-4.1-mini');
        $messages = [
            [
                'role' => 'system',
                'content' => 'Voce e um assistente de analise esportiva. Monte um cenario informativo com base nas analises fornecidas. Nao prometa lucro, nao diga que e aposta certa, nao use a palavra garantido, nao sugira stake, nao recomende martingale nem recuperacao de loss. Priorize seguranca quando o perfil for conservador. Em cada selecao, a justificativa deve explicar por que o mercado foi escolhido usando probabilidades, confianca e pelo menos dois fatores do jogo presentes nas analises. Retorne JSON valido em portugues brasileiro.',
            ],
            [
                'role' => 'user',
                'content' => json_encode([
                    'instruction' => 'Monte um cenario informativo com no maximo o numero de selecoes solicitado. Em cada justificativa, explique por que chegou ao resultado, citando o mercado escolhido, o nivel de confianca e os fatores-chave que sustentam a leitura.',
                    'payload' => $payload,
                    'analyses' => $analyses,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ],
        ];

        $response = $this->request($messages, $model);
        if ($response === null) {
            $fallback = $this->fallbackSlip($payload, $analyses);
            $this->logs->logOpenAI($userId, 'slip_builder', $model, 0, 0, 0.0, 'fallback', null, ['fallback' => true]);
            return $fallback;
        }

        $content = $response['choices'][0]['message']['content'] ?? null;
        if (!is_string($content)) {
            throw new RuntimeException('OpenAI response did not include slip content.');
        }

        $decoded = $this->extractJson($content);
        $validated = $this->validateSlip($decoded, (int) ($payload['maxSelections'] ?? 3));
        $usage = $response['usage'] ?? [];
        $estimatedCost = $this->estimateCost($model, (int) ($usage['prompt_tokens'] ?? 0), (int) ($usage['completion_tokens'] ?? 0));

        $this->logs->logOpenAI(
            $userId,
            'slip_builder',
            $model,
            (int) ($usage['prompt_tokens'] ?? 0),
            (int) ($usage['completion_tokens'] ?? 0),
            $estimatedCost,
            'success',
            null,
            ['maxSelections' => $payload['maxSelections'] ?? null]
        );

        return $validated;
    }

    private function request(array $messages, string $model): ?array
    {
        $apiKey = env('OPENAI_API_KEY');

        if (!$apiKey || !function_exists('curl_init')) {
            return null;
        }

        $body = [
            'model' => $model,
            'temperature' => 0.3,
            'response_format' => ['type' => 'json_object'],
            'messages' => $messages,
        ];

        $handle = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        $response = curl_exec($handle);
        $statusCode = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if (!is_string($response) || $response === '' || $statusCode >= 400 || $error !== '') {
            $this->logs->logApiError('openai', '/v1/chat/completions', $error !== '' ? $error : ('HTTP ' . $statusCode));
            return null;
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('OpenAI returned invalid JSON.');
        }

        return $decoded;
    }

    private function extractJson(string $content): array
    {
        $content = trim($content);
        $content = preg_replace('/^```json\s*|\s*```$/', '', $content) ?? $content;

        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('OpenAI content is not valid JSON.');
        }

        return $decoded;
    }

    private function validateMatchAnalysis(array $data): array
    {
        $requiredScenarios = ['conservative_scenario', 'balanced_scenario', 'bold_scenario'];

        foreach (['main_tendency', 'summary', 'disclaimer'] as $field) {
            if (!isset($data[$field]) || trim((string) $data[$field]) === '') {
                throw new RuntimeException('Invalid match analysis field: ' . $field);
            }
        }

        foreach (['over_1_5_probability', 'over_2_5_probability', 'btts_probability', 'confidence_score'] as $field) {
            $value = (int) ($data[$field] ?? -1);
            if ($value < 0 || $value > 100) {
                throw new RuntimeException('Invalid percentage field: ' . $field);
            }
            $data[$field] = $value;
        }

        if (!in_array($data['risk_level'] ?? '', ['low', 'medium', 'high'], true)) {
            throw new RuntimeException('Invalid risk level.');
        }

        $data['key_factors'] = array_values(array_map('strval', $data['key_factors'] ?? []));
        $data['red_flags'] = array_values(array_map('strval', $data['red_flags'] ?? []));

        foreach ($requiredScenarios as $key) {
            $scenario = $data[$key] ?? null;
            if (!is_array($scenario)) {
                throw new RuntimeException('Invalid scenario: ' . $key);
            }

            $data[$key] = [
                'market' => (string) ($scenario['market'] ?? 'Analise manual'),
                'confidence' => max(0, min(100, (int) ($scenario['confidence'] ?? 0))),
                'risk' => (string) ($scenario['risk'] ?? 'medium'),
                'explanation' => (string) ($scenario['explanation'] ?? ''),
            ];
        }

        return $data;
    }

    private function validateSlip(array $data, int $maxSelections): array
    {
        $selections = array_slice(is_array($data['selections'] ?? null) ? $data['selections'] : [], 0, $maxSelections);
        $normalizedSelections = [];

        foreach ($selections as $selection) {
            if (!is_array($selection)) {
                continue;
            }

            $normalizedSelections[] = [
                'game' => (string) ($selection['game'] ?? ''),
                'market' => (string) ($selection['market'] ?? ''),
                'confidence' => max(0, min(100, (int) ($selection['confidence'] ?? 0))),
                'risk' => (string) ($selection['risk'] ?? 'medium'),
                'justification' => (string) ($selection['justification'] ?? ''),
            ];
        }

        return [
            'selections' => $normalizedSelections,
            'global_confidence' => max(0, min(100, (int) ($data['global_confidence'] ?? 0))),
            'global_risk' => in_array($data['global_risk'] ?? '', ['low', 'medium', 'high'], true) ? $data['global_risk'] : 'medium',
            'explanation' => (string) ($data['explanation'] ?? 'Cenario montado com base nas analises disponiveis.'),
            'disclaimer' => (string) ($data['disclaimer'] ?? 'Isto e uma analise informativa, nao uma garantia. Nao aposte valores que voce nao pode perder.'),
        ];
    }

    private function estimateCost(string $model, int $inputTokens, int $outputTokens): float
    {
        $pricing = match ($model) {
            'gpt-4.1-mini' => ['input' => 0.40 / 1_000_000, 'output' => 1.60 / 1_000_000],
            default => ['input' => 0.50 / 1_000_000, 'output' => 2.00 / 1_000_000],
        };

        return round(($inputTokens * $pricing['input']) + ($outputTokens * $pricing['output']), 6);
    }

    private function fallbackMatchAnalysis(array $payload): array
    {
        $homeStats = $payload['home_stats'] ?? [];
        $awayStats = $payload['away_stats'] ?? [];
        $fixtureContext = is_array($payload['fixture_context'] ?? null) ? $payload['fixture_context'] : [];
        $scheduleContext = is_array($payload['schedule_context'] ?? null) ? $payload['schedule_context'] : [];
        $homeSchedule = is_array($scheduleContext['home'] ?? null) ? $scheduleContext['home'] : [];
        $awaySchedule = is_array($scheduleContext['away'] ?? null) ? $scheduleContext['away'] : [];
        $homeScheduleSummary = is_array($homeSchedule['summary'] ?? null) ? $homeSchedule['summary'] : [];
        $awayScheduleSummary = is_array($awaySchedule['summary'] ?? null) ? $awaySchedule['summary'] : [];
        $predictionModel = is_array($payload['prediction_model'] ?? null) ? $payload['prediction_model'] : [];
        $featuredPlayers = is_array($payload['featured_players'] ?? null) ? $payload['featured_players'] : [];
        $weather = is_array($fixtureContext['weather'] ?? null) ? $fixtureContext['weather'] : [];
        $sidelined = is_array($fixtureContext['sidelined'] ?? null) ? $fixtureContext['sidelined'] : [];
        $over15 = (int) round((($homeStats['over_1_5_rate'] ?? 60) + ($awayStats['over_1_5_rate'] ?? 60)) / 2);
        $over25 = (int) round((($homeStats['over_2_5_rate'] ?? 45) + ($awayStats['over_2_5_rate'] ?? 45)) / 2);
        $btts = (int) round((($homeStats['btts_rate'] ?? 45) + ($awayStats['btts_rate'] ?? 45)) / 2);
        $confidence = (int) round(($over15 + $over25 + $btts) / 3);
        $keyFactors = [
            'Media combinada de gols das equipes acima do basico.',
            'Historico recente sugere frequencia razoavel para cenarios com gols.',
            'Indicadores ofensivos favorecem leitura positiva para over curto.',
        ];
        $redFlags = [
            'Sem garantia de manutencao do padrao se o jogo tiver postura conservadora.',
            'Oscilacao recente ou mudanca de contexto pode elevar o risco.',
        ];

        $shortRestThreshold = 3;
        $homeRest = isset($homeScheduleSummary['days_since_previous']) ? (int) $homeScheduleSummary['days_since_previous'] : null;
        $awayRest = isset($awayScheduleSummary['days_since_previous']) ? (int) $awayScheduleSummary['days_since_previous'] : null;
        $homeRecentLoad = (int) ($homeScheduleSummary['matches_last_14_days'] ?? 0);
        $awayRecentLoad = (int) ($awayScheduleSummary['matches_last_14_days'] ?? 0);

        if (($homeRest !== null && $homeRest <= $shortRestThreshold) || ($awayRest !== null && $awayRest <= $shortRestThreshold)) {
            $confidence -= 4;
            $redFlags[] = 'Calendario curto antes do jogo pode reduzir consistencia fisica e tatica.';
        }

        if ($homeRecentLoad >= 5 || $awayRecentLoad >= 5) {
            $confidence -= 3;
            $redFlags[] = 'Sequencia pesada de jogos nos 14 dias anteriores aumenta variancia.';
        }

        if (($homeRest !== null && $homeRest >= 6) && ($awayRest !== null && $awayRest >= 6)) {
            $confidence += 2;
            $keyFactors[] = 'As duas equipes chegam com janela de descanso mais limpa que a media.';
        }

        if ($predictionModel !== []) {
            $confidence += 1;
            $keyFactors[] = 'Ha modelo probabilistico externo disponivel para reforcar a leitura de cenarios.';
        }

        $sidelinedCount = 0;
        foreach ($sidelined as $teamUnavailable) {
            if (!is_array($teamUnavailable)) {
                continue;
            }

            $sidelinedCount += (int) ($teamUnavailable['count'] ?? 0);
        }

        if ($sidelinedCount >= 4) {
            $confidence -= 2;
            $redFlags[] = 'Numero relevante de desfalques potenciais pode distorcer o comportamento esperado do jogo.';
        }

        $temperature = is_numeric($weather['temperature_c'] ?? null) ? (float) $weather['temperature_c'] : null;
        $wind = is_numeric($weather['wind_kph'] ?? null) ? (float) $weather['wind_kph'] : null;

        if ($temperature !== null && $temperature >= 30) {
            $confidence -= 1;
            $redFlags[] = 'Clima quente pode reduzir intensidade e ritmo do jogo.';
        }

        if ($wind !== null && $wind >= 25) {
            $confidence -= 1;
            $redFlags[] = 'Vento forte pode aumentar variancia tecnica em finalizacoes e bolas longas.';
        }

        if ($featuredPlayers !== []) {
            $keyFactors[] = 'A partida conta com jogadores destacados no recorte recente e no perfil individual resumido.';
        }

        $confidence = max(48, min(84, $confidence));
        $riskLevel = $confidence >= 72 ? 'low' : ($confidence >= 58 ? 'medium' : 'high');

        return [
            'main_tendency' => 'Jogo com inclinacao para mercado de gols, mas com necessidade de leitura do contexto final perto do inicio.',
            'over_1_5_probability' => $over15,
            'over_2_5_probability' => $over25,
            'btts_probability' => $btts,
            'confidence_score' => $confidence,
            'risk_level' => $riskLevel,
            'key_factors' => array_values(array_unique($keyFactors)),
            'red_flags' => array_values(array_unique($redFlags)),
            'conservative_scenario' => [
                'market' => 'Over 1.5 gols',
                'confidence' => max(50, $over15 - 2),
                'risk' => 'low',
                'explanation' => 'Cenario mais controlado, sustentado por media de gols e frequencia historica acima da media.',
            ],
            'balanced_scenario' => [
                'market' => 'Over 2.5 gols',
                'confidence' => max(42, $over25 - 1),
                'risk' => 'medium',
                'explanation' => 'Leitura equilibrada quando as duas equipes mostram participacao ofensiva regular.',
            ],
            'bold_scenario' => [
                'market' => 'Ambas marcam',
                'confidence' => max(38, $btts - 1),
                'risk' => 'medium',
                'explanation' => 'Cenario mais agressivo e dependente da resposta ofensiva dos dois lados.',
            ],
            'summary' => 'A tendencia principal aponta para um jogo com producao ofensiva razoavel, sem qualquer promessa de acerto. Vale usar como apoio a decisao e revisar o contexto final perto da partida.',
            'disclaimer' => 'Analise probabilistica. Nao existe garantia de resultado. Use com responsabilidade. 18+.',
        ];
    }

    private function fallbackSlip(array $payload, array $analyses): array
    {
        $selections = [];
        foreach (array_slice($analyses, 0, (int) ($payload['maxSelections'] ?? 3)) as $analysis) {
            $selections[] = [
                'game' => $analysis['game'] ?? 'Partida',
                'market' => $analysis['preferred_market'] ?? 'Over 1.5 gols',
                'confidence' => (int) ($analysis['confidence_score'] ?? 60),
                'risk' => (string) ($analysis['risk_level'] ?? 'medium'),
                'justification' => (string) ($analysis['summary'] ?? 'Selecao sustentada pelos indicadores de gols e contexto recente.'),
            ];
        }

        return [
            'selections' => $selections,
            'global_confidence' => (int) round(array_sum(array_column($selections, 'confidence')) / max(1, count($selections))),
            'global_risk' => $payload['riskProfile'] === 'conservative' ? 'low' : 'medium',
            'explanation' => 'Cenario informativo montado com base nas analises disponiveis e no perfil selecionado.',
            'disclaimer' => 'Isto e uma analise informativa, nao uma garantia. Nao aposte valores que voce nao pode perder.',
        ];
    }
}
