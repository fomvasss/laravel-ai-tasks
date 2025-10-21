<?php

namespace Fomvasss\AiTasks\Contracts;

use Fomvasss\AiTasks\Tasks\AiTask;

interface QueueSerializableAi
{
    public function toQueueArgs(): array;
    
    public static function fromQueueArgs(array $args): AiTask;
}
