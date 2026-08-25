<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Env;

/**
 * Calcula custo técnico estimado por chamada usando tarifas por 1M tokens.
 *
 * Prioridade:
 * 1) AI_COST_RATES_JSON definido no ambiente;
 * 2) catálogo padrão OpenAI embutido (snapshot 2026-08-25) para modelos de texto conhecidos.
 *
 * Modelos especializados (TTS, transcrição, realtime, imagem etc.) não usam
 * automaticamente as tarifas de texto; nesses casos é obrigatório configurar uma
 * tarifa explícita no AI_COST_RATES_JSON.
 */
final class AiCostCalculatorService
{
    public const DEFAULT_PRICING_SNAPSHOT = '2026-08-25';

    /** @return array{cost:?float,currency:?string,source:string,rate_key:?string} */
    public function estimate(string $provider, string $model, ?int $input, ?int $output, ?int $cached = null): array
    {
        $provider = strtolower(trim($provider));
        $model = strtolower(trim($model));
        if ($provider === '' || $model === '' || ($input === null && $output === null)) {
            return ['cost' => null, 'currency' => null, 'source' => 'unavailable', 'rate_key' => null];
        }

        $rate = $this->configuredRate($provider, $model);
        $source = 'env';
        $rateKey = $model;

        if ($rate === null) {
            $resolved = $this->builtinRate($provider, $model);
            $rate = $resolved['rate'];
            $rateKey = $resolved['key'];
            $source = $rate !== null ? 'builtin_' . self::DEFAULT_PRICING_SNAPSHOT : 'unpriced';
        }

        if (!is_array($rate)) {
            return ['cost' => null, 'currency' => null, 'source' => $source, 'rate_key' => $rateKey];
        }

        $inputRate = max(0.0, (float) ($rate['input_per_million'] ?? 0));
        $outputRate = max(0.0, (float) ($rate['output_per_million'] ?? 0));
        $cachedRate = array_key_exists('cached_input_per_million', $rate)
            ? max(0.0, (float) $rate['cached_input_per_million'])
            : $inputRate;
        $currency = strtoupper(trim((string) ($rate['currency'] ?? 'USD')));
        $currency = preg_match('/^[A-Z]{3}$/', $currency) ? $currency : 'USD';

        $inputTokens = max(0, (int) ($input ?? 0));
        $cachedTokens = min($inputTokens, max(0, (int) ($cached ?? 0)));
        $nonCachedInput = max(0, $inputTokens - $cachedTokens);
        $outputTokens = max(0, (int) ($output ?? 0));

        $estimated = (($nonCachedInput / 1_000_000) * $inputRate)
            + (($cachedTokens / 1_000_000) * $cachedRate)
            + (($outputTokens / 1_000_000) * $outputRate);

        return [
            'cost' => round($estimated, 8),
            'currency' => $currency,
            'source' => $source,
            'rate_key' => $rateKey,
        ];
    }

    /** @return array<string,array<string,float|string>> */
    public function defaultOpenAiRates(): array
    {
        return [
            'gpt-4o-mini' => ['input_per_million' => 0.15, 'cached_input_per_million' => 0.075, 'output_per_million' => 0.60, 'currency' => 'USD'],
            'gpt-4o' => ['input_per_million' => 2.50, 'cached_input_per_million' => 1.25, 'output_per_million' => 10.00, 'currency' => 'USD'],
            'gpt-4.1-nano' => ['input_per_million' => 0.10, 'cached_input_per_million' => 0.025, 'output_per_million' => 0.40, 'currency' => 'USD'],
            'gpt-4.1-mini' => ['input_per_million' => 0.40, 'cached_input_per_million' => 0.10, 'output_per_million' => 1.60, 'currency' => 'USD'],
            'gpt-4.1' => ['input_per_million' => 2.00, 'cached_input_per_million' => 0.50, 'output_per_million' => 8.00, 'currency' => 'USD'],
            'gpt-5-mini' => ['input_per_million' => 0.25, 'cached_input_per_million' => 0.025, 'output_per_million' => 2.00, 'currency' => 'USD'],
            'gpt-5' => ['input_per_million' => 1.25, 'cached_input_per_million' => 0.125, 'output_per_million' => 10.00, 'currency' => 'USD'],
            'gpt-5.6-luna' => ['input_per_million' => 0.20, 'cached_input_per_million' => 0.02, 'output_per_million' => 1.20, 'currency' => 'USD'],
            'gpt-5.6-terra' => ['input_per_million' => 2.00, 'cached_input_per_million' => 0.20, 'output_per_million' => 12.00, 'currency' => 'USD'],
            'gpt-5.6-sol' => ['input_per_million' => 4.00, 'cached_input_per_million' => 0.40, 'output_per_million' => 20.00, 'currency' => 'USD'],
        ];
    }

    /** @return array{rate:?array<string,mixed>,key:?string} */
    private function builtinRate(string $provider, string $model): array
    {
        if ($provider !== 'openai' || $this->isSpecializedModel($model)) {
            return ['rate' => null, 'key' => null];
        }

        $rates = $this->defaultOpenAiRates();
        $key = $this->normalizeOpenAiModel($model);
        if ($key === null || !isset($rates[$key])) {
            return ['rate' => null, 'key' => $key];
        }

        return ['rate' => $rates[$key], 'key' => $key];
    }

    /** @return array<string,mixed>|null */
    private function configuredRate(string $provider, string $model): ?array
    {
        $raw = trim((string) Env::get('AI_COST_RATES_JSON', ''));
        if ($raw === '') {
            return null;
        }
        $rates = json_decode($raw, true);
        if (!is_array($rates)) {
            return null;
        }
        $providerRates = $rates[$provider] ?? null;
        if (!is_array($providerRates)) {
            return null;
        }

        if (is_array($providerRates[$model] ?? null)) {
            return $providerRates[$model];
        }

        $normalized = $provider === 'openai' ? $this->normalizeOpenAiModel($model) : null;
        if ($normalized !== null && is_array($providerRates[$normalized] ?? null)) {
            return $providerRates[$normalized];
        }

        return is_array($providerRates['*'] ?? null) ? $providerRates['*'] : null;
    }

    private function normalizeOpenAiModel(string $model): ?string
    {
        if (str_starts_with($model, 'gpt-4o-mini')) return 'gpt-4o-mini';
        if (str_starts_with($model, 'gpt-4o')) return 'gpt-4o';
        if (str_starts_with($model, 'gpt-4.1-nano')) return 'gpt-4.1-nano';
        if (str_starts_with($model, 'gpt-4.1-mini')) return 'gpt-4.1-mini';
        if ($model === 'gpt-4.1' || str_starts_with($model, 'gpt-4.1-20')) return 'gpt-4.1';
        if (str_starts_with($model, 'gpt-5.6-luna')) return 'gpt-5.6-luna';
        if (str_starts_with($model, 'gpt-5.6-terra')) return 'gpt-5.6-terra';
        if (str_starts_with($model, 'gpt-5.6-sol') || $model === 'gpt-5.6' || str_starts_with($model, 'gpt-5.6-20')) return 'gpt-5.6-sol';
        if (str_starts_with($model, 'gpt-5-mini')) return 'gpt-5-mini';
        if ($model === 'gpt-5' || str_starts_with($model, 'gpt-5-20')) return 'gpt-5';
        return null;
    }

    private function isSpecializedModel(string $model): bool
    {
        foreach (['tts', 'transcribe', 'realtime', 'audio', 'image', 'sora', 'search'] as $marker) {
            if (str_contains($model, $marker)) return true;
        }
        return false;
    }
}
