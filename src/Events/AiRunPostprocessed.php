<?php

namespace Fomvasss\AiTasks\Events;

use Fomvasss\AiTasks\Models\AiRun;

class AiRunPostprocessed
{
    public function __construct(
        public AiRun $run,
        public mixed $result,
    ) {}
}