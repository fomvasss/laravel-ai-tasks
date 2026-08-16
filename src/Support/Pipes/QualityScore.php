<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\Support\Pipes;

use Fomvasss\AiTasks\DTO\AiResponse;

class QualityScore
{
    public function handle(AiResponse $resp, \Closure $next)
    {
        // демо: додати "оцінку" у usage (AiResponse immutable — будуємо нову)
        return $next(new AiResponse(
            ok: $resp->ok,
            content: $resp->content,
            usage: [...$resp->usage, 'quality' => 0.9],
            raw: $resp->raw,
            error: $resp->error,
            toolCalls: $resp->toolCalls,
            structured: $resp->structured,
            finishReason: $resp->finishReason,
            pendingApprovals: $resp->pendingApprovals,
        ));
    }
}
