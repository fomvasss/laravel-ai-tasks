<?php

namespace Fomvasss\AiTasks\Support\Pipes;

use Fomvasss\AiTasks\DTO\AiResponse;

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
