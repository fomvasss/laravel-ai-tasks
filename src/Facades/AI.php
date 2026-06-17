<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\Facades;

use Fomvasss\AiTasks\Testing\FakeAI;
use Illuminate\Support\Facades\Facade;

class AI extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Fomvasss\AiTasks\Core\AI::class;
    }

    public static function fake(string|array|null $responses = null): FakeAI
    {
        $fake = new FakeAI($responses);
        static::swap($fake);

        return $fake;
    }
}
