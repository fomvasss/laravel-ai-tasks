<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\Events;

use Fomvasss\AiTasks\Models\AiRun;

final class AiRunFinished
{
    public function __construct(
        public readonly AiRun $run,
    ) {}
}
