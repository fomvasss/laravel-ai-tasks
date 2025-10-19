<?php

namespace Fomvasss\AiTasks\Core;

use Fomvasss\AiTasks\Contracts\AiDriver;
use Fomvasss\AiTasks\Drivers\GeminiDriver;
use Fomvasss\AiTasks\Drivers\OpenAiDriver;
use Illuminate\Support\Manager;

class AiManager extends Manager
{
    public function getDefaultDriver()
    {
        return config('ai.default');
    }

    public function createOpenaiDriver(): \Fomvasss\AiTasks\Contracts\AiDriver
    {
        return new \Fomvasss\AiTasks\Drivers\OpenAiDriver(config('ai.drivers.openai'));
    }

    public function createGeminiDriver(): \Fomvasss\AiTasks\Contracts\AiDriver
    {
        return new \Fomvasss\AiTasks\Drivers\GeminiDriver(config('ai.drivers.gemini'));
    }
}
