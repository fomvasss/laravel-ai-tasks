<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\Support\Pipes;

use Fomvasss\AiTasks\DTO\AiResponse;

/**
 * @deprecated Empty stub, will be removed in 4.0. Write your own pipe instead.
 */
class EnsureJson
{
    public function handle(AiResponse $resp, \Closure $next)
    {
        $decoded = json_decode($resp->content ?? '', true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            // не кидаємо — просто пропускаємо
        }
        
        return $next($resp);
    }
}
