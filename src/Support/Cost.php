<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\Support;

class Cost
{
    public static function calc(string $provider, array $usage, array $driverCfg): ?float
    {
        $price = $driverCfg['price'] ?? null;
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
}
