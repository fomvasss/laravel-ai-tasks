<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\Drivers;

use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Enums\Lab;

final class JsonModeAgent extends AnonymousAgent implements HasProviderOptions
{
    private const JSON_OBJECT_PROVIDERS = ['deepseek', 'groq', 'openrouter', 'xai', 'mistral'];

    public function providerOptions(Lab|string $provider): array
    {
        $value = $provider instanceof Lab ? $provider->value : $provider;

        if (in_array($value, self::JSON_OBJECT_PROVIDERS, true)) {
            return ['response_format' => ['type' => 'json_object']];
        }

        return [];
    }
}
