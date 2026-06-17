<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\Tasks;

use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\Messages\Support\Image;
use Fomvasss\AiTasks\Contracts\ShouldQueueAi;
use Fomvasss\AiTasks\DTO\AiPayload;
use Fomvasss\AiTasks\DTO\AiResponse;

class VisionExampleTask extends AiTask implements ShouldQueueAi
{
    public function __construct(
        private readonly string $imageUrl,
        private readonly string $prompt = 'Describe this image',
    ) {}

    public function modality(): string
    {
        return 'text';
    }

    public function toPayload(): AiPayload
    {
        return new AiPayload(
            modality: $this->modality(),
            messages: [
                new UserMessage(
                    $this->prompt,
                    additionalContent: [Image::fromUrl($this->imageUrl)],
                ),
            ],
            options: ['temperature' => 0.2],
        );
    }

    public function serializeForQueue(): array
    {
        return [$this->imageUrl, $this->prompt];
    }
}
