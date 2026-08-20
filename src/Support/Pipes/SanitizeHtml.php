<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\Support\Pipes;

use Fomvasss\AiTasks\DTO\AiResponse;

/**
 * @deprecated Empty stub, will be removed in 4.0. Write your own pipe instead.
 */
class SanitizeHtml
{
    public function handle(AiResponse $resp, \Closure $next)
    {
        // спрощений приклад: при потребі підключити HTMLPurifier
        return $next($resp);
    }
}
