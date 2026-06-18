<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\Core;

use Fomvasss\AiTasks\Contracts\AiDriver;
use Fomvasss\AiTasks\Drivers\LaravelAiDriver;
use Fomvasss\AiTasks\Drivers\NullDriver;
use Fomvasss\AiTasks\Exceptions\AiDriverException;
use Illuminate\Support\Manager;

class AiManager extends Manager
{
    public function getDefaultDriver(): string
    {
        return config('ai-tasks.default');
    }

    protected function createDriver($driver): AiDriver
    {
        if ($driver === 'null') {
            return new NullDriver();
        }

        if (config()->has("ai-tasks.drivers.{$driver}")) {
            return new LaravelAiDriver($driver, config("ai-tasks.drivers.{$driver}", []));
        }

        throw new AiDriverException("Driver [{$driver}] is not configured in config/ai.php.");
    }
}
