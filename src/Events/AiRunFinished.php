<?php

namespace Fomvasss\AiTasks\Events;

use Fomvasss\AiTasks\Models\AiRun;

class AiRunFinished { public function __construct(public AiRun $run) {} }
