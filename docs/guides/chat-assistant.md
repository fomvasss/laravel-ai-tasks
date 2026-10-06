# Building a chat assistant

The most common integration: a customer writes in a chat or messenger, and an AI replies on behalf of the business or hands the conversation to a human. This page collects the patterns that held up in production.

## The task skeleton

```php
class ChatReplyTask extends AiTask implements ShouldQueueAi
{
    use SerializesModelsAi;

    public function __construct(private readonly ChatMessage $question) {}

    public function modality(): string { return 'text'; }

    // one run per incoming message
    public function idempotencyKey(): ?string
    {
        return 'chat-reply-'.$this->question->id;
    }

    protected function tenantId(): ?string { return $this->question->chat->organization_id; }
    protected function subjectType(): ?string { return 'chat'; }
    protected function subjectId(): ?string { return (string) $this->question->chat_id; }

    public function shouldRun(): bool { /* see Debounce */ }
    public function toPayload(): AiPayload { /* see History and System prompt */ }
    public function schema(): ?\Closure { /* see Response shape */ }
    public function postprocess(AiResponse $response): array { /* normalize */ }

    public function maxRetries(): int { return 1; }
    public function isAcceptable(AiResponse|array $result): bool { /* see Retries */ }

    public function onCompleted(AiResponse|array $result, bool $attemptsExhausted): void { /* save, broadcast */ }
    public function onFailed(\Throwable|string $reason): void { /* hand over to a human */ }
}
```

Queue it from wherever the message is saved, after checking that AI replies are enabled for this chat:

```php
if ($chat->isAiActive()) {
    AI::queue(new ChatReplyTask($message));
}
```

## History

The package doesn't store conversations — your own messages table is the source of truth, and the history is rebuilt from it on every turn. `laravel/ai`'s `RemembersConversations` doesn't apply: the package always uses an anonymous agent.

What worked when building `messages`:

- **The last N messages** (20–200 depending on the task), oldest first. Too short a window makes the model ask again for things the customer already said.
- **Skip what the customer must never see quoted back**: internal notes, summaries, system and integration messages.
- **Merge consecutive messages of the same role** — several short customer messages become one `UserMessage`, no `[user, user]` pairs.
- **Strip HTML** and normalize whitespace.
- **Mark attachments in text** — `[attachment: photo]` for a message that only carries a file. The model sees only text; without the marker it answers the previous message.
- **Mark long pauses** — `[2 days passed]` between messages more than a few hours apart. Cheaper than a timestamp on every line, and it fixes "tomorrow" referring to the wrong day.
- **Exclude the current message** from the history when it's already saved, otherwise it's sent twice — in the history and as the final prompt.
- **Distinguish AI and human operator replies** — both are `assistant`; prefix the operator's ones so the model knows who promised what.
- **Store a reply on failure too.** If a turn fails and nothing is saved as the assistant's reply, the next turn sees an unanswered question and tries to finish it.

System events the user reacts to (a status changed, a reminder fired) can go into the same table under their own role and be sent as `UserMessage('[System notification · 14:05] ...')`, with a small separate limit and a time window. Otherwise a reply like "ok, continue" arrives without its context.

## System prompt

Order it from stable to volatile:

1. the tenant's instruction and knowledge base
2. the fixed rules: language, role conventions, when to hand over, the response format
3. what changes every turn: current date and time in the tenant's timezone, the customer's local time, contact data already known

A byte-stable beginning is what providers cache. With OpenAI, also set a stable cache key per assistant:

```php
options: [
    'provider_options' => [
        'openai' => ['prompt_cache_key' => 'assistant-'.$assistant->id],
    ],
],
```

Use a separate key for drafts and previews, so they don't evict the production prefix. If the knowledge base is assembled from several sources (instruction, files, FAQ), cache the assembled text and invalidate it when the sources change.

Lessons on content:

- **Label structured knowledge.** FAQ pairs given as `Question: ... / Answer: ...` are recognized as ready answers; unlabelled ones are not. Put the hand-curated part last, closest to the dialog.
- **Remove instructions you don't want instead of overriding them.** A later "ignore the rule above" loses to an earlier emphatic rule.
- **List known contact fields** (and which are still unknown) so the model doesn't ask twice.
- **Let the model ask a clarifying question** before handing over on a vague message; escalate on an explicit request or a real knowledge gap.
- **Describe only the tools that are actually available** — generate that part of the prompt from the same flags as `tools()`.

## Response shape

Ask for a structured reply instead of plain text:

```php
public function schema(): ?\Closure
{
    return fn (JsonSchema $schema): array => [
        'message' => $schema->string(),
        'action' => $schema->string()->enum(['reply', 'transfer_to_manager', 'ignore']),
        'confidence' => $schema->number(),
        'locale' => $schema->string(),
        'contact' => $schema->object([
            'name' => $schema->string()->nullable(),
            'email' => $schema->string()->nullable(),
            'phone' => $schema->string()->nullable(),
        ])->nullable(),
    ];
}
```

- `action` lets the model hand over or stay silent explicitly, instead of you guessing from the text.
- `locale` is the language the customer actually wrote in — store it and answer in it next time.
- `contact` collects details the customer mentioned; validate before saving.
- An optional field (e.g. reply suggestions for the operator) can be added to the schema only when the feature is on — one call instead of two.
- A nullable enum needs `null` in the enum list too, otherwise strict JSON Schema rejects it: `->enum([...$values, null])->nullable()`.
- **Keep a "Key rules" block in the system prompt that mirrors the schema.** Providers without strict structured output (DeepSeek, most OpenAI-compatible ones) receive the schema only as a hint.

### Normalize in postprocess()

Return an array with an `ok` flag that the rest of the pipeline relies on:

```php
public function postprocess(AiResponse $response): array
{
    $data = $response->structured ?: $this->parseContent((string) $response->content);

    $message = is_string($data['message'] ?? null) ? trim($data['message']) : null;
    $action = in_array($data['action'] ?? null, ['reply', 'transfer_to_manager', 'ignore'], true)
        ? $data['action'] : 'reply';

    return [
        'ok' => $response->ok && ($message !== null && $message !== '' || $action === 'ignore'),
        'message' => $message,
        'action' => $action,
        'finish_reason' => $response->finishReason,
    ];
}
```

On providers without strict structured output `structured` can be empty while the answer sits in `content` — as JSON in markdown fences, JSON embedded in prose, or plain text. A `parseContent()` fallback that strips fences, extracts the JSON object and otherwise treats the text as the message turns most of those into usable replies. Reject a broken JSON fragment rather than showing it to the customer. Type-check every field — without a strict schema nothing guarantees types.

**Render past AI replies in the history in the schema's JSON shape**, not as plain text. Models continue the format of the conversation more than they follow the instruction: with plain-text history a non-strict provider answered in prose almost every time; with JSON-shaped history it answered in JSON every time.

## Retries

```php
public function maxRetries(): int
{
    return 1;
}

public function isAcceptable(AiResponse|array $result): bool
{
    return ! empty($result['ok']);
}
```

One retry fixes most blank replies. More rarely help — the retry sends the identical payload. Make sure **legitimate negative results are acceptable** (`action: ignore` with empty text, "nothing to do" verdicts), otherwise every one of them is retried and then escalated.

## Debounce and staleness

Customers send a question in three messages. Answer once:

```php
public function shouldRun(): bool
{
    $chat = $this->question->chat;

    return config('services.ai.enabled')
        && $chat->isAiActive()                         // a human may have taken over
        && ! $chat->hasNewerCustomerMessage($this->question); // the newer message's task answers the whole burst
}
```

`shouldRun()` runs before the provider call. Generation takes seconds, so check the same conditions again in `onCompleted()` and `onFailed()` — the customer may write again or an operator may reply meanwhile. Drop the answer then.

The `AiTaskStarted` event fires after `shouldRun()` passed — the right moment for a "typing..." indicator. Keep that call short (a 2-second timeout, failures only logged).

## Side effects

All of them in `onCompleted()`, never in `postprocess()` (which runs on rejected attempts too):

```php
public function onCompleted(AiResponse|array $result, bool $attemptsExhausted): void
{
    if ($this->superseded()) {
        return;
    }

    if ($attemptsExhausted) {
        $this->handOver('error');
        return;
    }

    if ($result['action'] === 'transfer_to_manager') {
        $this->handOver('requested');
    }

    $reply = $this->saveReply($result);  // the reply first
    broadcast(new MessageCreated($reply));

    try {
        $this->saveContact($result);      // secondary effects can't lose the reply
    } catch (\Throwable $e) {
        report($e);
    }
}

public function onFailed(\Throwable|string $reason): void
{
    if (! $this->superseded()) {
        $this->handOver('error');
    }
}
```

- Re-read records in `onCompleted()` — the queued task holds a fresh copy, but the run may have taken a while.
- Apply anything that can move records (merging duplicate chats or contacts) before creating the reply, then check that AI is still active on the target.
- Mark AI-written records with a source (`source = ai`) — useful in the UI, and needed to stop two automatic responders from replying to each other.

## Sync variants of the same task

A "try your instruction" playground and an offline evaluation command need exactly the production prompt. Subclass the production task instead of copying it:

```php
class PlaygroundReplyTask extends ChatReplyTask
{
    public function name(): string { return 'playground_reply'; }  // own routing key and dashboard group
    public function shouldRun(): bool { return true; }
    public function onCompleted(AiResponse|array $result, bool $attemptsExhausted): void {}
    public function onFailed(\Throwable|string $reason): void {}
}
```

Called with `AI::send()`, remember that `send()` returns the `postprocess()` array JSON-encoded in `content`, and doesn't run the `isAcceptable()` retry loop — check acceptability yourself:

```php
$result = json_decode(AI::send($task)->content ?? '', true) ?: [];

if (! $task->isAcceptable($result)) {
    abort(422, 'The model returned no usable answer, try again.');
}
```
