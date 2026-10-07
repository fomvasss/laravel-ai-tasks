# AiPayload & AiResponse

## AiPayload

`Fomvasss\AiTasks\DTO\AiPayload` — the request a task builds in `toPayload()`. All properties are readonly.

| Parameter | Type | Default | Description |
|---|---|---|---|
| `modality` | `string` | — | Must match `modality()` of the task |
| `messages` | `array` | `[]` | `Laravel\Ai\Messages\Message` objects, strings or `['role' => ..., 'content' => ...]`. The last user message is the prompt |
| `systemPrompt` | `?string` | `null` | Instructions |
| `options` | `array` | `[]` | See below |
| `meta` | `array` | `[]` | Arbitrary data, stored in `ai_runs.request` |
| `tools` | `array` | `[]` | Declare tools in `AiTask::tools()` instead — when the task defines tools, a schema or a tool choice, the payload is rebuilt from the task's methods |
| `jsonMode` | `bool` | `false` | See [JSON mode](../usage/structured-output.md#json-mode) |
| `providerOverride` | `?array` | `null` | Per-request credentials, see [Provider override](../usage/provider-override.md) |
| `schema` | `?Closure` | `null` | Declare in `AiTask::schema()` instead |
| `toolChoice` | `ToolChoice\|string\|array\|null` | `null` | Declare in `AiTask::toolChoice()` instead |
| `decisions` | `Decisions\|array\|null` | `null` | Resume a run paused for tool approval, see [Tool approval](../usage/tool-approval.md#resuming) |

### Options

| Key | Modality | Description |
|---|---|---|
| `model` | all | Model instead of the driver's default |
| `timeout` | text, image | HTTP timeout in seconds; text default `60` |
| `temperature`, `max_tokens`, `top_p` | text | [Generation options](../usage/running-tasks.md#generation-options) |
| `max_steps` | text with tools | Step budget of the tool loop; wins over `AiTask::maxSteps()`, see [Step budget](../guides/tools-in-practice.md#step-budget) |
| `attachments` | text | Files for vision |
| `path`, `storage`, `disk`, `diarize` | transcription | Audio source and speaker separation |
| `provider_options` | text with `schema()` | Raw provider fields keyed by driver, see [Provider-specific options](../usage/structured-output.md#provider-specific-options) |
| `size`, `quality` | image | See [Modalities](../usage/modalities.md) |
| `voice`, `female`, `instructions` | audio | See [Modalities](../usage/modalities.md) |

## AiResponse

`Fomvasss\AiTasks\DTO\AiResponse`. All properties are readonly.

| Property | Type | Description |
|---|---|---|
| `ok` | `bool` | Whether the call succeeded |
| `content` | `?string` | Text; base64 for image/audio |
| `usage` | `array` | `driver`, `model`, `tokens_in`, `tokens_out`, `cache_read_tokens`, `cache_write_tokens`, `cost`, `cost_rates`; `audio_seconds` for transcription |
| `structured` | `?array` | Decoded output of `schema()` tasks |
| `toolCalls` | `array` | Tools the model invoked, `ToolCall::toArray()` per entry |
| `finishReason` | `?string` | `stop`, `length`, `tool_calls`, `content_filter`, `error`, `unknown` |
| `pendingApprovals` | `array` | Tool calls waiting for approval: `id`, `tool`, `arguments`, `reason` |
| `runId` | `?string` | The `ai_runs` row of this call (since 3.35), also when `postprocess()` returned an array; `null` from `AI::fake()` |
| `resumeMessages` | `array` | The paused turn as stored for `AI::resume()`; internal |
| `error` | `?string` | Error message |
| `raw` | `array` | Reserved; currently always empty |

Methods: `paused(): bool` — the run waits for a tool decision; `pendingToolCalls(): array` — the waiting calls in full `ToolCall::toArray()` form.

## AiContext

`Fomvasss\AiTasks\DTO\AiContext` — returned by `AiTask::context()`: `tenantId`, `taskName`, `subjectType`, `subjectId`, `meta`, `userId`.
