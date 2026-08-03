<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\Tasks;

use Fomvasss\AiTasks\Contracts\ShouldQueueAi;
use Fomvasss\AiTasks\DTO\AiPayload;
use Fomvasss\AiTasks\DTO\AiResponse;
use Laravel\Ai\Files\Image as AiImage;
use Laravel\Ai\Messages\UserMessage;

/**
 * @deprecated Unused starter example, not wired into anything else in the package.
 *             Write your own AiTask subclass instead (see "Creating a Task" in the
 *             README). Will be removed in the next major version.
 */
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
            messages: [new UserMessage($this->prompt)],
            options: [
                'temperature'  => 0.2,
                'attachments'  => [AiImage::fromUrl($this->imageUrl)],
            ],
        );
    }

    public function serializeForQueue(): array
    {
        return [$this->imageUrl, $this->prompt];
    }
}
