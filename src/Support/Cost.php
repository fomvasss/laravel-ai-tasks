<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\Support;

class Cost
{
    /**
     * Ставки, за якими рахується прогін цієї моделі: спершу `drivers.X.prices[<model>]`,
     * далі — спільний `drivers.X.price`.
     *
     * Пошук по моделі потрібен тому, що ставки живуть на драйвері, а модель береться з .env
     * (`OPENAI_MODEL`, `DEEPSEEK_MODEL` тощо). Без нього зміна моделі на дорожчу не змінює
     * жодної цифри в обліку: `cost` далі рахується за старими ставками й тихо занижений.
     *
     * Ключ шукається двічі — повним ім'ям і хвостом після `/`: моделі через шлюз приходять як
     * `anthropic/claude-sonnet-5`, а в конфізі природно писати саме `claude-sonnet-5`.
     *
     * Повертає ставки разом з `model` і `source` — цей масив лягає в `ai_runs.cost_rates`
     * як знімок: без нього рядок, записаний до зміни тарифів провайдера, заднім числом уже
     * не пояснити (числа в конфізі вже інші, і невідомо, які діяли тоді).
     *
     * @return array{model?: string, source: string, in?: float, out?: float, cache_read?: float, cache_write?: float, per_char?: float}|null
     */
    public static function ratesFor(array $driverCfg, ?string $model = null): ?array
    {
        $price  = null;
        $source = null;

        foreach (self::modelKeys($model) as $key) {
            if (is_array($driverCfg['prices'][$key] ?? null)) {
                $price  = $driverCfg['prices'][$key];
                $source = 'model:' . $key;
                break;
            }
        }

        if ($price === null && is_array($driverCfg['price'] ?? null)) {
            $price  = $driverCfg['price'];
            $source = 'driver';
        }

        if ($price === null) {
            return null;
        }

        $rates = ['model' => $model, 'source' => $source];

        foreach (['in', 'out', 'cache_read', 'cache_write', 'per_char'] as $key) {
            if (isset($price[$key]) && is_numeric($price[$key])) {
                $rates[$key] = (float) $price[$key];
            }
        }

        return array_filter($rates, fn (mixed $v): bool => $v !== null);
    }

    public static function calc(string $provider, array $usage, array $driverCfg): ?float
    {
        $price = self::ratesFor($driverCfg, $usage['model'] ?? null);
        if ($price === null) {
            return null;
        }

        $in            = (int) ($usage['tokens_in']          ?? 0);
        $out           = (int) ($usage['tokens_out']         ?? 0);
        $cacheRead     = (int) ($usage['cache_read_tokens']  ?? 0);
        $cacheWrite    = (int) ($usage['cache_write_tokens'] ?? 0);

        $perM = fn(float $rate, int $tokens): float => ($tokens / 1_000_000) * $rate;

        $cost = $perM((float) ($price['in']  ?? 0.0), $in)
              + $perM((float) ($price['out'] ?? 0.0), $out)
              + $perM((float) ($price['cache_read']  ?? 0.0), $cacheRead)
              + $perM((float) ($price['cache_write'] ?? 0.0), $cacheWrite);

        return round($cost, 8);
    }

    /**
     * Approximates cost for character-billed modalities (audio/TTS), which don't
     * return token usage. Rate is per 1M characters, configured under price.per_char.
     */
    public static function calcByChars(string $provider, int $chars, array $driverCfg, ?string $model = null): ?float
    {
        $rate = self::ratesFor($driverCfg, $model)['per_char'] ?? null;
        if ($rate === null) {
            return null;
        }

        return round(($chars / 1_000_000) * (float) $rate, 8);
    }

    /** @return list<string> */
    private static function modelKeys(?string $model): array
    {
        if ($model === null || $model === '') {
            return [];
        }

        $slash = strrpos($model, '/');

        return $slash === false ? [$model] : [$model, substr($model, $slash + 1)];
    }
}
