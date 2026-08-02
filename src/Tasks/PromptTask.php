<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\Tasks;

use Fomvasss\AiTasks\DTO\AiPayload;
use Laravel\Ai\Messages\UserMessage;

/**
 * Generic task backing AI::prompt() — for quick one-off calls where a dedicated
 * AiTask class isn't worth writing. Still goes through the same send()/AiRun/
 * budget/routing pipeline as any other task.
 */
class PromptTask extends AiTask
{
    public function __construct(
        private readonly string  $prompt,
        private readonly ?string $system = null,
        private readonly string  $taskName = 'prompt',
    ) {}

    public function name(): string
    {
        return $this->taskName;
    }

    public function modality(): string
    {
        return 'text';
    }

    public function toPayload(): AiPayload
    {
        return new AiPayload(
            modality: $this->modality(),
            messages: [new UserMessage($this->prompt)],
            systemPrompt: $this->system,
        );
    }
}
