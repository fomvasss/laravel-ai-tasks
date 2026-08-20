<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\Drivers\Concerns;

/**
 * laravel/ai resolves temperature/maxTokens/topP by calling same-named methods on the
 * agent (see TextGenerationOptions::forAgent()) — this trait exposes AiPayload options
 * through those methods so per-task values reach the provider.
 */
trait HasGenerationOptions
{
    private ?float $temperatureValue = null;

    private ?int $maxTokensValue = null;

    private ?float $topPValue = null;

    public function withGenerationOptions(?float $temperature, ?int $maxTokens, ?float $topP): static
    {
        $this->temperatureValue = $temperature;
        $this->maxTokensValue = $maxTokens;
        $this->topPValue = $topP;

        return $this;
    }

    public function temperature(): ?float
    {
        return $this->temperatureValue;
    }

    public function maxTokens(): ?int
    {
        return $this->maxTokensValue;
    }

    public function topP(): ?float
    {
        return $this->topPValue;
    }
}
