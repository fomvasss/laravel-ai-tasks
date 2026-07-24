<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\Drivers\Concerns;

use Laravel\Ai\ToolChoice;

trait HasToolChoice
{
    private ?ToolChoice $toolChoiceValue = null;

    public function withToolChoice(?ToolChoice $toolChoice): static
    {
        $this->toolChoiceValue = $toolChoice;

        return $this;
    }

    public function toolChoice(): ?ToolChoice
    {
        return $this->toolChoiceValue;
    }
}
