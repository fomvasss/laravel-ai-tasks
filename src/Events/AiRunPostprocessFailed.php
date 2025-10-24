<?php

namespace Fomvasss\AiTasks\Events;

use Fomvasss\AiTasks\Models\AiRun;

class AiRunPostprocessFailed
{
    public function __construct(
        public AiRun $run,
        public \Throwable $error,
    ) {}
}