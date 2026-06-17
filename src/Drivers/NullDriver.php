<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\Drivers;

use Fomvasss\AiTasks\Contracts\AiDriver;
use Fomvasss\AiTasks\DTO\AiContext;
use Fomvasss\AiTasks\DTO\AiPayload;
use Fomvasss\AiTasks\DTO\AiResponse;

final class NullDriver implements AiDriver
{
    public function supports(string $modality): bool
    {
        return true;
    }

    public function send(AiPayload $p, AiContext $c): AiResponse
    {
        return new AiResponse(true, 'null driver response', ['driver' => 'null', 'tokens_in' => 0, 'tokens_out' => 0]);
    }

    public function stream(AiPayload $p, AiContext $c, callable $onChunk): AiResponse
    {
        $text = 'null driver response';
        $onChunk($text);

        return new AiResponse(true, $text, ['driver' => 'null', 'tokens_in' => 0, 'tokens_out' => 0]);
    }
}
