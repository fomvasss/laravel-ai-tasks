<?php

namespace Fomvasss\AiTasks\Support;

class Cost
{
    public static function calc(string $driverName, array $usage, array $driverCfg): float
    {
        $price = $driverCfg['price'] ?? [];

        // токени
        $in  = (int)($usage['prompt_tokens']     ?? $usage['tokens_in']  ?? 0);
        $out = (int)($usage['completion_tokens'] ?? $usage['tokens_out'] ?? 0);

        $cin  = (float)($price['in']  ?? 0.0);
        $cout = (float)($price['out'] ?? 0.0);

        $cost = $in * $cin + $out * $cout;

        // зображення (якщо колись буде), при потребі додай інші модальності
        if (($usage['images'] ?? 0) > 0 && isset($price['image'])) {
            $cost += (int)$usage['images'] * (float)$price['image'];
        }
        // TODO: додати інші модальності

        return round($cost, 8);
    }
}