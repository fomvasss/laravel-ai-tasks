<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\Drivers;

use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Enums\Lab;

final class JsonModeAgent extends AnonymousAgent implements HasProviderOptions
{
    // Провайдери що використовують Chat Completions-сумісний response_format
    private const RESPONSE_FORMAT_PROVIDERS = ['deepseek', 'groq', 'openrouter', 'mistral'];

    public function providerOptions(Lab|string $provider): array
    {
        $value = $provider instanceof Lab ? $provider->value : $provider;

        // OpenAI і xAI використовують Responses API з text.format
        if (in_array($value, ['openai', 'xai'], true)) {
            return ['text' => ['format' => ['type' => 'json_object']]];
        }

        // Gemini: провайдерОпції потрапляють у generationConfig
        if ($value === 'gemini') {
            return ['response_mime_type' => 'application/json'];
        }

        if (in_array($value, self::RESPONSE_FORMAT_PROVIDERS, true)) {
            return ['response_format' => ['type' => 'json_object']];
        }

        return [];
    }
}
