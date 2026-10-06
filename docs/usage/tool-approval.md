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

### Resuming

Dispatch the same task again with `AiPayload::$decisions` instead of a new text prompt:

```php
public function toPayload(): AiPayload
{
    return new AiPayload(
        modality: 'text',
        messages: $this->history(), // must include the paused assistant turn with its tool call
        decisions: $this->decisions, // e.g. ['call_abc123' => true]; null on the first, proposing call
    );
}
```

`decisions` accepts a `Laravel\Ai\Approvals\Decisions` instance or a map `['tool_call_id' => true|false|Decision::approve()|Decision::reject('reason')]`. Works with `send()` and `queue()`, not with `stream()`.

### Rebuilding the paused turn

The package is deliberately not built on `laravel/ai`'s `RemembersConversations`/`ConversationStore`: your own domain data (chat, message log) is the source of truth for history, and `AiPayload::$messages` is always built from it. So the resume is on you — the history must contain the paused assistant turn as a real message with its tool call attached, not just the text the user saw.

Rebuild it from `AiResponse::$toolCalls`, **not** `$pendingApprovals`. `$pendingApprovals` is a reduced view for display. `$toolCalls` carries the full shape a replay needs — `result_id` and, for reasoning models (OpenAI Responses API), `reasoning_id`/`reasoning_summary`/`reasoning_encrypted_content`. A replay missing `result_id` is rejected (`400: input[N].call_id: expected a string, but got null`):

```php
use Illuminate\Support\Collection;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Responses\Data\ToolCall;

// $pendingToolCall = the matching entry from the proposing call's AiResponse::$toolCalls
$messages[] = new AssistantMessage('', new Collection([
    ToolCall::fromArray($pendingToolCall),
]));
```
