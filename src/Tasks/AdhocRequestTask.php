<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\Tasks;

use Fomvasss\AiTasks\DTO\AiPayload;
use Laravel\Ai\Messages\UserMessage;

/**
 * Task backing the `ai:request` console command. A real (queue-reconstructable) class,
 * unlike the anonymous class it replaced, so `ai:request --queue` works. Deduplication
 * is disabled — every ad-hoc CLI invocation should actually run.
 */
class AdhocRequestTask extends AiTask
{
    public function __construct(
        private readonly string $prompt,
        private readonly string $modalityName = 'text',
        private readonly ?float $temperature = null,
        private readonly ?string $tenant = null,
    ) {}

    public function name(): string
    {
        return 'adhoc';
    }

    public function modality(): string
    {
        return $this->modalityName;
    }

    protected function tenantId(): ?string
    {
        return $this->tenant;
    }

    public function toPayload(): AiPayload
    {
        return new AiPayload(
            modality: $this->modality(),
            messages: [new UserMessage($this->prompt)],
            options: $this->temperature !== null ? ['temperature' => $this->temperature] : [],
        );
    }

    public function idempotencyKey(): ?string
    {
        return null;
    }

    public function serializeForQueue(): array
    {
        return [$this->prompt, $this->modalityName, $this->temperature, $this->tenant];
    }
}
