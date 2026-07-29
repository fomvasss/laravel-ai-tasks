<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\Drivers;

use Closure;
use Fomvasss\AiTasks\Drivers\Concerns\HasToolChoice;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\StructuredAnonymousAgent;

final class StructuredToolChoiceAgent extends StructuredAnonymousAgent implements HasProviderOptions
{
    use HasToolChoice;

    /**
     * @param array<string, array<string, mixed>> $providerOptions keyed by driver name (Lab value), e.g. ['deepseek' => ['thinking' => ['type' => 'disabled']]]
     */
    public function __construct(
        string $instructions,
        iterable $messages,
        iterable $tools,
        Closure $schema,
        private readonly array $providerOptions = [],
    ) {
        parent::__construct($instructions, $messages, $tools, $schema);
    }

    /**
     * @return array<string, mixed>
     */
    public function providerOptions(Lab|string $provider): array
    {
        $key = $provider instanceof Lab ? $provider->value : $provider;

        return $this->providerOptions[$key] ?? [];
    }
}
