<?php

namespace Fomvasss\AiTasks\Events;

use Fomvasss\AiTasks\Models\AiRun;

class AiRunStarted { public function __construct(public AiRun $run) {} }
