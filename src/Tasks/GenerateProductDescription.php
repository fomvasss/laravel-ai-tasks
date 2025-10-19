<?php

namespace Fomvasss\AiTasks\Tasks;

use Fomvasss\AiTasks\Contracts\ShouldQueueAi;
use Fomvasss\AiTasks\DTO\AiPayload;
use Fomvasss\AiTasks\DTO\AiResponse;
use Fomvasss\AiTasks\Support\Prompt;
use Fomvasss\AiTasks\Support\Schema;
use Fomvasss\AiTasks\Traits\QueueableAi;

/**
 *  Examplle.
 */ 
class GenerateProductDescription extends AiTask implements ShouldQueueAi
{
    use QueueableAi;

    public function __construct(
        public object $product, // очікується модель з title/features
        public string $locale = 'en'
    ) {}

    public function name(): string { return 'product.description'; }

    public function modality(): string { return 'text'; }

    public function viaQueues(): array
    {
        return ['request' => config('ai.task_queues.product.description.request', 'ai:low'),
                'postprocess' => config('ai.task_queues.product.description.postprocess', 'ai:post')];
    }

    public function toPayload(): AiPayload
    {
        $tpl = Prompt::get('product.description.v3')->render([
            'title' => $this->product->title ?? '',
            'features' => $this->product->features ?? [],
            'locale' => app()->getLocale() ?: $this->locale,
        ]);

        return new AiPayload(
            modality: 'text',
            messages: [['role' => 'user','content' => $tpl]],
            options: ['temperature' => 0.4],
            template: 'product.description.v3',
            schema: 'product_description_v1'
        );
    }

    public function postprocess(AiResponse $resp): array|AiResponse
    {
        $data = Schema::parse($resp->content ?? '', 'product_description_v1');
        
        return $data;
    }
}
