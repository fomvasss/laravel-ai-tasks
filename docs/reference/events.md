# Events

Namespace `Fomvasss\AiTasks\Events`.

| Event | When | Properties |
|---|---|---|
| `AiTaskQueued` | Task dispatched to the queue | `task`, `run` |
| `AiTaskStarted` | Provider call begins (per driver attempt) | `task`, `context`, `run` |
| `AiTaskCompleted` | Final result ready — postprocess done, retries settled | `task`, `response`, `run`, `attemptsExhausted` |
| `AiTaskFailed` | One driver attempt failed; the chain may still succeed | `task`, `error`, `run` |
| `AiTaskFailedFinally` | The task ended without a result; fired together with `AiTask::onFailed()` | `task`, `reason`, `run` (`null` if nothing started) |
| `AiTaskCompletedHandlerFailed` | `AiTask::onCompleted()` threw | `task`, `exception`, `run` |
| `AiRunFinished` | Low-level: a run row finished `ok` | `run` |
| `AiRunFailed` | Low-level: a run row failed — for a queued run once, after its retries are exhausted | `run` |

```php
use Fomvasss\AiTasks\Events\AiTaskCompleted;
use Illuminate\Support\Facades\Event;

Event::listen(AiTaskCompleted::class, function (AiTaskCompleted $event) {
    // $event->task, $event->response, $event->run, $event->attemptsExhausted
});
```

For a task with a single consumer, the [`onCompleted()`](../usage/queued-tasks.md#the-oncompleted-hook) and [`onFailed()`](../usage/queued-tasks.md#the-onfailed-hook) hooks are simpler than listeners.

Closing a run with the dashboard **Dead** button or `AiRun::abandon()` fires no event.
