<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\Tasks;

use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\UserMessage;
use Fomvasss\AiTasks\DTO\AiContext;
use Fomvasss\AiTasks\DTO\AiPayload;
use Fomvasss\AiTasks\DTO\AiResponse;

/**
 * @deprecated Unused starter example, not wired into anything else in the package.
 *             Its hardcoded English systemPrompt can't be customized. Write your own
 *             AiTask subclass instead (see "Creating a Task" in the README). Will be
 *             removed in the next major version.
 */
class ChatAssistTask extends AiTask
{
    public function __construct(
        private readonly array  $history,
        private readonly array  $tools   = [],
        private readonly string $locale  = 'en',
    ) {}

    public function name(): string
    {
        return 'chat_assist';
    }

    public function modality(): string
    {
        return 'text';
    }

    public function toPayload(): AiPayload
    {
        $messages = array_map(function (array $msg) {
            return $msg['role'] === 'assistant'
                ? new AssistantMessage($msg['content'])
                : new UserMessage($msg['content']);
        }, $this->history);

        return new AiPayload(
            modality: $this->modality(),
            messages: $messages,
            systemPrompt: "You are a support assistant. Answer briefly in locale: {$this->locale}.",
        );
    }

    public function tools(): array
    {
        return $this->tools;
    }

    public function context(): AiContext
    {
        return new AiContext(
            tenantId: app(\Fomvasss\AiTasks\Support\TenantResolver::class)->id(),
            taskName: $this->name(),
            subjectType: 'conversation',
        );
    }
}
