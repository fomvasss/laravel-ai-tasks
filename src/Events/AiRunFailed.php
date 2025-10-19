<?php

namespace Fomvasss\AiTasks\Events;

use Fomvasss\AiTasks\Models\AiRun;

class AiRunFailed { public function __construct(public AiRun $run) {} }
