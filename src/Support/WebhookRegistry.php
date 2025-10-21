<?php

namespace Fomvasss\AiTasks\Support;

use Closure;
use Illuminate\Support\Arr;
use InvalidArgumentException;

class WebhookRegistry
{
    /** @var array<string, Closure> */
    protected array $handlers = [];

    /**
     * Register handler for driver.
     * $handler = function(\Illuminate\Http\Request $r): \Fomvasss\AiTasks\DTO\WebhookPayload
     */
    public function extend(string $driver, Closure $handler): void
    {
        $this->handlers[$driver] = $handler;
    }

    public function has(string $driver): bool
    {
        return array_key_exists($driver, $this->handlers);
    }

    public function handler(string $driver): Closure
    {
        if (! $this->has($driver)) {
            throw new InvalidArgumentException("Webhook handler for driver [$driver] not registered.");
        }
        
        return $this->handlers[$driver];
    }
}