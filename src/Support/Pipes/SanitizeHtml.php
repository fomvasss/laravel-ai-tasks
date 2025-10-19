<?php

namespace Fomvasss\AiTasks\Support\Pipes;

use Fomvasss\AiTasks\DTO\AiResponse;

class SanitizeHtml
{
    public function handle(AiResponse $resp, \Closure $next)
    {
        // спрощений приклад: при потребі підключити HTMLPurifier
        return $next($resp);
    }
}
