# Tools in practice

What came up when giving a model real actions in an application — creating records, changing a cart, posting comments. API reference: [Tools & MCP](../usage/tools.md), [Tool choice & approval](../usage/tool-approval.md).

## Reusing your own MCP server tools

If the application already exposes a `laravel/mcp` server, its tool classes can be given to a task directly — no client connection, `laravel/ai` wraps them itself. Keep the permission gate the server applies:

```php
public function tools(): array
{
    return collect([TasksTool::class, CreateCommentTool::class, StartTimerTool::class])
        ->map(fn (string $class) => new $class())
        ->filter(fn ($tool) => $tool->shouldRegister()) // same visibility as on the MCP server
        ->values()
        ->all();
}
```

MCP **resources** don't reach the model this way — `AiPayload` carries only tools. Data the server offers as a resource (the current user's profile, reference lists) has to go into the system prompt or get a tool equivalent. If you reuse the server's instructions as the system prompt, remove the sentences that point to resources instead of adding "ignore that" later — the earlier rule wins.

## Acting as a user

Tools usually call `auth()->user()`, policies and scopes. Where they run decides who that is:

| Call | Where the tool loop runs | `auth()` without the trait | with `ActsAsDispatchingUser` |
|---|---|---|---|
| `AI::send()` in a web request | the request | the logged-in user | the same user, the same instance |
| `AI::send()` in your own queued job / listener | that job | whatever the job sets | whatever the job set at dispatch |
| `AI::queue()` | the package worker (`ProcessAiPayload`) | nobody | the user who dispatched it |

Add the trait to a task whose tools or hooks act as the user (since 3.34):

```php
use Fomvasss\AiTasks\Traits\ActsAsDispatchingUser;

class AssistantReplyTask extends AiTask
{
    use ActsAsDispatchingUser;
}
```

It captures the guard, the user id and the app locale at dispatch, stores them with the run and applies them wherever the package runs the task's code: the provider call with its tool loop and approval checks, `shouldRun()`, `postprocess()`, `onCompleted()`, `onFailed()`, a retry after `isAcceptable()`, and a **Retry** from the dashboard or `ai:retry` — which then acts as the original user, not as whoever clicked. The user is re-read by id on that guard (made the default guard for the call), and everything is restored afterwards, also when the call throws, so the next job of the worker never inherits the user.

Tools needing more than the user — a header, a cart, a country — extend the context:

```php
public function executionContext(): array
{
    return [...$this->traitExecutionContext(), 'country' => request()->header('X-Country')];
}

public static function withExecutionContext(array $context, \Closure $call): mixed
{
    $previous = request()->headers->get('X-Country');
    request()->headers->set('X-Country', $context['country'] ?? null);

    try {
        return static::traitWithExecutionContext($context, $call);
    } finally {
        request()->headers->set('X-Country', $previous);
    }
}
```

with the trait imported as `use ActsAsDispatchingUser { executionContext as traitExecutionContext; withExecutionContext as traitWithExecutionContext; }`. The context is stored as JSON: scalars and arrays only. Values that belong to one tool (a chat id the tool reports to) can still go into the tool's constructor — they're serialized with the job.

**Running `AI::send()` inside your own queued job** after `Auth::setUser($user)` also works — the whole tool loop then runs in that job. Reset the user when the job ends: `Queue::before(fn () => Auth::forgetUser())` in a service provider covers every job, including one that threw; a `finally` in the job alone doesn't cover a job killed by a timeout.

When tools act as a user, **every tool must check access to every id it receives** — a tool that checks only "may create tasks" but not "may access this project" becomes a hole the model will eventually walk through.

## Tool errors

`laravel/ai` returns validation errors (`ValidationException`) to the model so it can fix its arguments. Any other exception — a `findOrFail()` on a wrong id, an authorization failure — fails the whole run with a generic error. Wrap tools so the model gets the error as a tool result and can recover:

```php
class SafeTool implements Tool
{
    public function __construct(private readonly Tool $tool) {}

    public function handle(Request $request): Stringable|string
    {
        try {
            return $this->tool->handle($request);
        } catch (ModelNotFoundException) {
            return json_encode(['success' => false, 'message' => 'Not found. Check the id and try again.']);
        } catch (AuthorizationException) {
            return json_encode(['success' => false, 'message' => 'Not allowed for this user.']);
        } catch (\Throwable $e) {
            report($e);

            return json_encode(['success' => false, 'message' => 'The action failed.']);
        }
    }

    public function name(): string { return ToolNameResolver::resolve($this->tool); }
    public function description(): Stringable|string { return $this->tool->description(); }
    public function schema(JsonSchema $schema): array { return $this->tool->schema($schema); }
}
```

Forgiving tool schemas help too — accept an id with or without a prefix, infer a type from the field that was given. Every rejected call is a wasted step.

## Step budget

The tool loop stops after a fixed number of steps: `round(number of tools × 1.5)` (5 without tools). With one or two tools that's 2–3 steps, and a chain like "find, then read, then act" is cut off silently with whatever text the model has at that point. Give multi-step tasks enough tools, or check `finishReason` (`tool_calls` on the last step means it was cut).

## "Done!" without doing it

Models claim actions they didn't perform ("the timer is started"). Check the reply against `AiResponse::$toolCalls`: when a reply announces an action but no tool was called, run the task again with `toolChoice()` returning `'required'` and a note in the prompt that the previous draft was rejected. If there's still no tool call, tell the user honestly that it didn't work.

Some providers reject `tool_choice: required` in reasoning mode with a 400 — catch it and retry with only the prompt instruction. Match the claim with word boundaries (`\b...\b`); a capabilities list like "I can add..." otherwise matches "added".

## Approval before irreversible actions

Built on `laravel/ai`'s `Approvable`, see [Tool approval](../usage/tool-approval.md). Lessons:

- **Gate MCP server tools on the wrapper, not on the tool.** An MCP server tool returned from `tools()` is wrapped in `McpServerTool` automatically, and only the wrapper is asked — `needsApproval()` declared on the MCP tool itself is ignored and it runs without pausing. Wrap it yourself: `(new McpServerTool($tool))->requireApproval('...')`, or a `McpServerTool` subclass overriding `needsApproval()` when the answer depends on the call — see [MCP server tools](../usage/tool-approval.md#mcp-server-tools).
- **Validate before asking.** Make `needsApproval()` return `false` when the call is invalid anyway (missing item, wrong quantity) — the error goes back to the model at once. Otherwise the customer confirms, the tool fails, the model retries with a new call id, and the customer is asked to confirm the same thing again.
- **Count consecutive failures** of a tool (in cache, per chat) and after a few tell the model to offer a human.
- **Resume with `AI::resume()` / `AI::queueResume()`**, keeping only `$response->runId` with your chat — the package stores the paused turn and refuses a second, expired or tool-less resume. See [Resuming](../usage/tool-approval.md#resuming).
- **Render the confirmation text yourself** from the pending call's arguments rather than trusting the model's wording.
- **Build the resumed history as of the pause** in `toPayload()` when `resumingRun()` is set — cut your chat at the message that led to the pause.
- **Classify the customer's answer cheaply first** — exact "yes"/"no" matches in the supported languages — and call an AI classifier only for the rest.

## Validate ids the model returns

Even with an enum schema, models occasionally return an id that wasn't in the list. Check every id in `postprocess()` against the candidates you sent and drop the unknown ones.
