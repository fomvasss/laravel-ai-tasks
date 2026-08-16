<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\Drivers;

use Fomvasss\AiTasks\Drivers\Concerns\HasGenerationOptions;
use Fomvasss\AiTasks\Drivers\Concerns\HasToolChoice;
use Laravel\Ai\AnonymousAgent;

final class AnonymousToolChoiceAgent extends AnonymousAgent
{
    use HasGenerationOptions, HasToolChoice;
}
