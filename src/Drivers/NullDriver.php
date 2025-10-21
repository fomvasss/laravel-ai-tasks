<?php

namespace Fomvasss\AiTasks\Drivers;

use Fomvasss\AiTasks\Contracts\AiDriver;
use Fomvasss\AiTasks\DTO\AiContext;
use Fomvasss\AiTasks\DTO\AiPayload;
use Fomvasss\AiTasks\DTO\AiResponse;

final class NullDriver implements AiDriver
{
    public function __construct(private array $cfg = []) {}

    public function supports(string $m): bool { return true; }

    public function send(AiPayload $p, AiContext $c): AiResponse
    {
        return new AiResponse(true, '{"short":"stub","html":"<p>stub</p>"}', ['driver' => 'null']);
    }

    public function stream(AiPayload $p, AiContext $c, callable $onChunk): AiResponse
    {
        return $this->send($p, $c);
    }

    public function queue(AiPayload $p, AiContext $c, ?string $q = null): string
    {
        return dispatch(new \Fomvasss\AiTasks\Jobs\ProcessAiPayload('null', $p, $c))->id;
    }
}