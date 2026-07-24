<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\Drivers;

use Fomvasss\AiTasks\Drivers\Concerns\HasToolChoice;
use Laravel\Ai\StructuredAnonymousAgent;

final class StructuredToolChoiceAgent extends StructuredAnonymousAgent
{
    use HasToolChoice;
}
