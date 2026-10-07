# Tool choice & approval

Both work on top of the tools a task returns from `tools()` — see [Tools & MCP](tools.md).

## Tool choice

Implement `toolChoice()` to force whether and which tool the model must call. Backed by `laravel/ai`'s `ToolChoice` (Gemini, OpenAI, Anthropic).

```php
use Laravel\Ai\ToolChoice;

public function toolChoice(): ToolChoice|string|array|null
{
    return ToolChoice::required;               // model must call some tool
    // return ToolChoice::none;                // model must not call any tool
    // return ToolChoice::tool('current_date'); // model must call this tool
    // return 'required';                      // string modes are coerced too
}
```

The forced choice is released after the first step, so a forced tool call is still followed by a normal text answer using the tool's result. The default `null` keeps the provider's own default (usually `auto`). No effect without `tools()`.

## Tool approval

For a tool that performs an irreversible or expensive action (place an order, delete a file, send money), implement `laravel/ai`'s native `Contracts\Approvable`. The package adds no approval protocol of its own — it surfaces what `laravel/ai` does:

```php
use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Concerns\InteractsWithApprovals;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Tool;

class CreateOrderTool implements Tool, Approvable
{
    use InteractsWithApprovals;

    protected function needsApproval(Request $request): Approval|bool
    {
        return Approval::required('Placing a real order requires customer confirmation.');
    }
}
```

When the model calls such a tool, the run pauses instead of executing it: `AiResponse::$pendingApprovals` is populated (`id`/`tool`/`arguments`/`reason` per call) and the tool is **not** run.

### MCP server tools

A `Laravel\Mcp\Server\Tool` returned from `tools()` is wrapped in `Laravel\Ai\Tools\McpServerTool`, and the approval check runs on that wrapper. The wrapper never requires approval on its own and does not ask the wrapped tool, so `Approvable`/`needsApproval()` on the MCP tool class is ignored and the tool runs without pausing. Wrap the tool yourself instead:

```php
use Laravel\Ai\Tools\McpServerTool;

public function tools(): array
{
    return [
        new SearchOrdersTool,                                                              // runs freely
        (new McpServerTool(new CreateOrderTool))->requireApproval('Places a real order.'), // pauses
    ];
}
```

When the decision depends on the call (for example, skip approval for a call that would fail validation anyway), extend `McpServerTool` and override `needsApproval(Request $request): Approval|bool`; `$this->tool` is the wrapped MCP tool. `McpServerTool` is `Approvable` since `laravel/ai` 1.1.

### Resuming

A paused run is stored with status `paused`, together with everything the continuation needs: the whole paused turn (the model's tool calls with their result ids and reasoning replay blocks, and the results of tools in the same step that needed no approval — those already ran), the pending calls and an expiry. The response says so:

```php
$response = AI::send(new AssistantReplyTask($chat));

if ($response->paused()) {
    // show $response->pendingApprovals to the user; keep $response->runId
}
```

On the queued path `postprocess()`/`onCompleted()` get the same response (`paused()`, `runId`); a pause is never retried by `isAcceptable()`.

Continue with the user's decisions (since 3.35):

```php
$response = AI::resume(new AssistantReplyTask($chat), $runId, ['fc_abc123' => true]);
$newRunId = AI::queueResume(new AssistantReplyTask($chat), $runId, ['fc_abc123' => true]);
```

- Decisions are keyed by `pendingApprovals[].id` (for OpenAI the `fc_...` item id, not `call_id`): `true`/`false`, `Decision::approve()`, `Decision::reject('reason')`, `Decision::edit([...])`, or a `Decisions` instance; `'*'` covers the rest.
- `$task` is a fresh instance of the task that paused. Its `toPayload()` is called with `resumingRun()` set and must return the history **as of the pause** — up to and including the prompt that led to it, without what came after (the confirmation text you showed, the user's "yes"). The package appends the stored turn and the decisions. A task whose payload comes from its constructor arguments needs nothing extra.
- The pause is claimed atomically: a second resume of the same run throws `ApprovalResumeException`, so two answers can't run the tool twice. It is also refused, leaving the run paused, when the run isn't paused, belongs to another task class, is older than `approvals.ttl_minutes` (default 60), or the task no longer provides a tool the pause waits for.
- The continuation runs under the paused run's [execution context](../guides/tools-in-practice.md#acting-as-a-user): tools act as the user who started the run, whoever answers.
- A new run is created; its `request.meta.resumed_from` is the paused run's id, and the paused run becomes `ok` with `response.resume.resumed_at`.
- `toolChoice()` is not applied to the continuation — a forced choice would force another tool call.
- Not available for `stream()`.

A rejection without a reason ends the turn with an empty answer. Set `approvals.reject_reason` (`AI_APPROVAL_REJECT_REASON`) and such a rejection carries that text, so the model answers the user itself.

### Resuming by hand

Before 3.35 the continuation was built by the application, and `AiPayload::$decisions` still works that way: the history must contain the paused assistant turn as a real message with its tool call, rebuilt from `AiResponse::pendingToolCalls()` (the full form — `$pendingApprovals` lacks `result_id` and the reasoning fields, and a replay without `result_id` is rejected with `400: input[N].call_id: expected a string`). It replays only the calls waiting for approval: results of tools that ran in the same step are lost. Prefer `AI::resume()`.

```php
use Illuminate\Support\Collection;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Responses\Data\ToolCall;

$messages[] = new AssistantMessage('', new Collection(array_map(ToolCall::fromArray(...), $response->pendingToolCalls())));

return new AiPayload('text', $messages, decisions: ['fc_abc123' => true]);
```
