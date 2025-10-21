<?php

namespace Fomvasss\AiTasks\Support\Pipes;

use Fomvasss\AiTasks\DTO\AiResponse;

class QualityScore
{
    public function handle(AiResponse $resp, \Closure $next)
    {
        // демо: додати "оцінку" у usage
        $resp->usage['quality'] = 0.9;
        
        return $next($resp);
    }
}
