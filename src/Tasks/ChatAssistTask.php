<?php

namespace Fomvasss\AiTasks\Tasks;

use Fomvasss\AiTasks\DTO\AiResponse;
use Fomvasss\AiTasks\Tasks\AiTask;
use Fomvasss\AiTasks\DTO\AiPayload;

class ChatAssistTask extends AiTask
{
    public function __construct(
        public array $history, // [{role,user|assistant|system, content}]
        public array $tools = [], // OpenAI-style tools
        public array $options = [], // temperature, tool_choice...
        public string $locale = 'en',
    ) {}

    /**
     * @return string
     */
    public function name(): string { return 'chat_assist'; }

    /**
     * @return string
     */
    public function modality(): string { return 'chat'; }

    /**
     * @return AiPayload
     */
    public function toPayload(): AiPayload
    {
        $system = [
            'role' => 'system',
            'content' => "You are a support assistant. Answer briefly on locale {$this->locale}. If necessary, call for tools."
        ];
        
        return new AiPayload(
            modality: 'chat',
            messages: array_values(array_merge([$system], $this->history)),
            options:  array_merge(['temperature' => 0.2, 'stream' => true], $this->options + ['tools' => $this->tools]),
        );
    }

    public function postprocess(AiResponse $resp): array|AiResponse
    {
        // You can save the summary message in the database, forward it, process it
        
        return $resp;
    }

    public function context(): \Fomvasss\AiTasks\DTO\AiContext
    {
        $ctx = parent::context();
        
        $ctx->subjectType = 'conversation';
        //$ctx->subjectId   = (string) $this->conv->id;

        return $ctx;
    }
}