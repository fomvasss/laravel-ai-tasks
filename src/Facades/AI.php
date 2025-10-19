<?php

namespace Fomvasss\AiTasks\Facades;

use Illuminate\Support\Facades\Facade;

class AI extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Fomvasss\AiTasks\Core\AI::class;
    }
}
