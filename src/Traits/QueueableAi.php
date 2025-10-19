<?php

namespace Fomvasss\AiTasks\Traits;

trait QueueableAi
{
    public ?string $queue = null;
    public ?string $connection = null;

    public function viaQueues(): array { return []; }

    public function onQueue(?string $queue): static { $this->queue = $queue; return $this; }

    public function onConnection(?string $connection): static { $this->connection = $connection; return $this; }

    public function preferredQueueFor(string $stage, ?string $fallback = null): ?string
    {
        return $this->viaQueues()[$stage] ?? $this->queue ?? $fallback;
    }

    public function preferredConnection(): ?string { return $this->connection; }
}
