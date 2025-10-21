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

    public function createOpenaiDriver(): AiDriver
    {
        return new \Fomvasss\AiTasks\Drivers\OpenAiDriver(config('ai.drivers.openai'));
    }

    public function createGeminiDriver():AiDriver
    {
        return new \Fomvasss\AiTasks\Drivers\GeminiDriver(config('ai.drivers.gemini'));
    }

    public function createNullDriver(): AiDriver 
    {
        return new \Fomvasss\AiTasks\Drivers\NullDriver([]);
    }
}
