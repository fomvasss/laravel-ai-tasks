<?php

namespace Fomvasss\AiTasks\Tasks;

use Fomvasss\AiTasks\Contracts\QueueSerializableAi;
use Fomvasss\AiTasks\Contracts\ShouldQueueAi;
use Fomvasss\AiTasks\DTO\AiPayload;
use Fomvasss\AiTasks\DTO\AiResponse;
use Fomvasss\AiTasks\Support\Prompt;
use Fomvasss\AiTasks\Support\Schema;
use Fomvasss\AiTasks\Traits\QueueableAi;

/**
 *  This examplle task.
 */ 
class GenerateProductDescription extends AiTask implements ShouldQueueAi, QueueSerializableAi
{
    use QueueableAi;

    public function __construct(
        public object $product, // example model with title/features
        public string $locale = 'en'
    ) {}
    
    public function name(): string { return 'product_description'; }

    public function modality(): string { return 'text'; }
    /**
     * @return AiPayload
     */
    public function toPayload(): AiPayload
    {
        $tpl = Prompt::get('product_description_v3')->render([
            'title' => $this->product->title ?? '',
            'features' => $this->product->features ?? [],
            'locale' => $this->locale,
        ]);

        return new AiPayload(
            modality: 'text',
            messages: [['role' => 'user','content' => $tpl]],
            options: ['temperature' => 0.4],
            template: 'product_description_v3',
            schema: 'product_description_v1'
        );
    }

    /**
     * @param AiResponse $resp
     * @return array|AiResponse
     */
    public function postprocess(AiResponse $resp): array|AiResponse
    {
        $data = Schema::parse($resp->content ?? '', 'product_description_v1');
        
        return $data;
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

    public function toQueueArgs(): array
    {
        return [$this->product, $this->locale];
    }
}
