<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\Core;

use Fomvasss\AiTasks\Contracts\AiDriver;
use Fomvasss\AiTasks\Drivers\NullDriver;
use Fomvasss\AiTasks\Drivers\PrismDriver;
use Illuminate\Support\Manager;

class AiManager extends Manager
{
    public function getDefaultDriver(): string
    {
        return config('ai.default');
    }

    public function createOpenaiDriver(): AiDriver
    {
        return new PrismDriver('openai', config('ai.drivers.openai', []));
    }

    public function createAnthropicDriver(): AiDriver
    {
        return new PrismDriver('anthropic', config('ai.drivers.anthropic', []));
    }

    public function createGeminiDriver(): AiDriver
    {
        return new PrismDriver('gemini', config('ai.drivers.gemini', []));
    }

    public function createOllamaDriver(): AiDriver
    {
        return new PrismDriver('ollama', config('ai.drivers.ollama', []));
    }

    public function createMistralDriver(): AiDriver
    {
        return new PrismDriver('mistral', config('ai.drivers.mistral', []));
    }

    public function createNullDriver(): AiDriver
    {
        return new NullDriver();
    }
}
