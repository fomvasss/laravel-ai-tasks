<?php

namespace Fomvasss\AiTasks\Tasks;

use Fomvasss\AiTasks\Contracts\QueueSerializableAi;
use Fomvasss\AiTasks\Contracts\ShouldQueueAi;
use Fomvasss\AiTasks\DTO\AiContext;
use Fomvasss\AiTasks\DTO\AiPayload;
use Fomvasss\AiTasks\DTO\AiResponse;
use Fomvasss\AiTasks\Support\Prompt;
use Fomvasss\AiTasks\Support\Schema;
use Fomvasss\AiTasks\Traits\QueueableAi;
use Illuminate\Support\Str;

/**
 *  This examplle task.
 */
class GenerateProductDescription extends AiTask implements ShouldQueueAi, QueueSerializableAi
{
    public function __construct(
        public object $product,
        public string $locale = 'en'
    )
    {
    }

    /**
     * @return array
     */
    public function toQueueArgs(): array
    {
        return [$this->product, $this->locale];
    }

    /**
     * @return array
     */
    public function viaQueues(): array
    {
        return [
            'request' => config('ai.task_queues.product_description.request', 'ai:low'),
            'postprocess' => config('ai.task_queues.product_description.postprocess', 'ai:post')
        ];
    }

    /**
     * @return string
     */
    public function name(): string
    {
        return 'product_description';
    }

    /**
     * @return string
     */
    public function modality(): string
    {
        return 'text';
    }

    public function context(): AiContext
    {
        $tenantId = app(\Fomvasss\AiTasks\Support\TenantResolver::class)->id();

        return new AiContext(
            tenantId: $tenantId,
            taskName: $this->name(),
            subjectType: 'product',
            subjectId: (string)($this->product->id ?? null),
            meta: ['trace_id' => Str::uuid()->toString()]
        );
    }

    /**
     * @return AiPayload
     */
    public function toPayload(): AiPayload
    {
        $tpl = implode(', ', [
            'title' => $this->product->title ?? '',
           // 'features' => $this->product->features ?? [],
            'locale' => $this->locale,
        ]);

        return new AiPayload(
            modality: 'text',
            messages: [['role' => 'user', 'content' => $tpl]],
            options: ['temperature' => 0.4],
            meta: ['product_id' => $this->product->id ?? null],
        );
    }

    /**
     * @param AiResponse $resp
     * @return array|AiResponse
     */
    public function postprocess(AiResponse $resp): array|AiResponse
    {
        return $resp;
    }
}
