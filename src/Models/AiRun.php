<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\Models;

use Fomvasss\AiTasks\DTO\AiContext;
use Fomvasss\AiTasks\DTO\AiPayload;
use Fomvasss\AiTasks\DTO\AiResponse;
use Fomvasss\AiTasks\Events\AiRunFailed;
use Fomvasss\AiTasks\Events\AiRunFinished;
use Fomvasss\AiTasks\Tasks\AiTask;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AiRun extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['id'];

    protected $casts = [
        'request'    => 'array',
        'response'   => 'array',
        'started_at' => 'datetime',
        'finished_at'=> 'datetime',
        'cost'       => 'float',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->table = config('ai-tasks.table', 'ai_runs');
    }

    public static function start(string $driver, AiPayload $p, AiContext $ctx, AiTask $task): self
    {
        return static::create([
            'tenant_id'       => $ctx->tenantId,
            'task'            => $ctx->taskName,
            'driver'          => $driver,
            'modality'        => $p->modality,
            'subject_type'    => $ctx->subjectType,
            'subject_id'      => $ctx->subjectId,
            'dispatch'        => 'sync',
            'status'          => 'running',
            'idempotency_key' => null,
            'request'         => static::minifyRequest($p, $task),
            'started_at'      => now(),
        ]);
    }

    public static function startAsQueue(string $driver, AiPayload $p, AiContext $ctx, AiTask $task, ?string $idempotencyKey = null): self
    {
        return static::create([
            'tenant_id'       => $ctx->tenantId,
            'task'            => $ctx->taskName,
            'driver'          => $driver,
            'modality'        => $p->modality,
            'subject_type'    => $ctx->subjectType,
            'subject_id'      => $ctx->subjectId,
            'dispatch'        => 'queue',
            'status'          => 'queued',
            'idempotency_key' => $idempotencyKey ?? $task->idempotencyKey(),
            'request'         => static::minifyRequest($p, $task),
        ]);
    }

    /**
     * Runs that should have progressed by now: queued/running, untouched for longer than the
     * threshold. Catches the case a lost queue payload leaves behind — the row stays 'queued'
     * forever because nothing ever picks it up to fail it.
     *
     * COALESCE(started_at, created_at) is the last known moment of progress (created_at while
     * queued, started_at once running). It is also the reason this is not written as a plain
     * `started_at` comparison: NULL ordering/comparison differs between MySQL and Postgres.
     */
    public function scopeStuck(Builder $query, ?int $minutes = null): Builder
    {
        $minutes ??= (int) config('ai-tasks.dashboard.stuck_after_minutes', 15);

        return $query->whereIn('status', ['queued', 'running'])
            ->whereRaw('COALESCE(started_at, created_at) < ?', [now()->subMinutes($minutes)]);
    }

    public function isStuck(?int $minutes = null): bool
    {
        if (! in_array($this->status, ['queued', 'running'], true)) {
            return false;
        }

        $minutes ??= (int) config('ai-tasks.dashboard.stuck_after_minutes', 15);

        return ($this->started_at ?? $this->created_at)?->lt(now()->subMinutes($minutes)) ?? false;
    }

    /**
     * A 'running' run is only retryable once stuck — otherwise a re-dispatch would duplicate work
     * a worker is doing right now.
     */
    public function canRetry(): bool
    {
        return match ($this->status) {
            'error', 'dead' => true,
            'queued', 'running' => $this->isStuck(),
            default => false,
        };
    }

    /**
     * Closes a run a human gave up on. Unlike fail()/markAsDead() this fires no AiRunFailed:
     * the event means "the run failed on its own", and consumers may notify on it — the real
     * failure here happened earlier and silently, this is only bookkeeping catching up.
     */
    public function abandon(string $reason): void
    {
        $this->update([
            'status'      => 'dead',
            'error'       => $reason,
            'finished_at' => now(),
        ]);
    }

    public function markRunning(): void
    {
        $this->update([
            'status'     => 'running',
            'started_at' => now(),
        ]);
    }

    public function markWaiting(array $extra = []): void
    {
        $this->update([
            'status'   => 'waiting',
            'response' => array_filter($extra),
        ]);
    }

    public function finish(AiResponse $resp): void
    {
        $ms = $this->started_at
            ? (int) now()->diffInMilliseconds($this->started_at, true)
            : null;

        $this->update([
            'status'            => 'ok',
            'model'             => $resp->usage['model'] ?? null,
            'response'          => array_filter([
                'content' => $resp->content,
                'structured' => $resp->structured,
                'tool_calls' => $resp->toolCalls ?: null,
                'finish_reason' => $resp->finishReason,
                'pending_approvals' => $resp->pendingApprovals ?: null,
            ], fn (mixed $v): bool => $v !== null),
            'tokens_in'         => $resp->usage['tokens_in']          ?? null,
            'tokens_out'        => $resp->usage['tokens_out']         ?? null,
            'cache_read_tokens' => $resp->usage['cache_read_tokens']  ?? null,
            'cache_write_tokens'=> $resp->usage['cache_write_tokens'] ?? null,
            'cost'              => $resp->usage['cost']               ?? null,
            'finished_at'       => now(),
            'duration_ms'       => $ms,
        ]);

        event(new AiRunFinished($this));
    }

    public function skip(string $reason): void
    {
        $this->update([
            'status'      => 'skipped',
            'error'       => $reason,
            'finished_at' => now(),
        ]);
    }

    /**
     * $usage may carry cost/cache tokens even for a failed run — e.g. a provider call that
     * completed and was billed, but was then rejected by a post-call check (budget exceeded).
     * Storing them here keeps the run's status honest ('error') while still letting Budget
     * count the spend — see getSpentBetween(), which sums by cost IS NOT NULL, not by status.
     */
    public function fail(string $error, array $usage = []): void
    {
        $ms = $this->started_at
            ? (int) now()->diffInMilliseconds($this->started_at, true)
            : null;

        $this->update([
            'status'             => 'error',
            'error'              => $error,
            'model'              => $usage['model'] ?? $this->model,
            'tokens_in'          => $usage['tokens_in']  ?? null,
            'tokens_out'         => $usage['tokens_out'] ?? null,
            'cache_read_tokens'  => $usage['cache_read_tokens']  ?? null,
            'cache_write_tokens' => $usage['cache_write_tokens'] ?? null,
            'cost'               => $usage['cost'] ?? null,
            'finished_at'        => now(),
            'duration_ms'        => $ms,
        ]);

        event(new AiRunFailed($this));
    }

    public static function markAsDead(string $id, \Throwable $e): void
    {
        static::whereKey($id)->update([
            'status'      => 'dead',
            'error'       => $e->getMessage(),
            'finished_at' => now(),
        ]);

        if ($run = static::find($id)) {
            event(new AiRunFailed($run));
        }
    }

    private static function minifyRequest(AiPayload $p, AiTask $task): array
    {
        $options = $p->options;

        // attachments can hold base64/binary payloads (vision, transcription) — storing them
        // would bloat ai_runs.request; task reconstruction uses task_args, not options
        if (! empty($options['attachments'])) {
            $options['attachments'] = '[' . count((array) $options['attachments']) . ' attachment(s) omitted]';
        }

        $data = [
            'modality'   => $p->modality,
            'options'    => $options,
            'meta'       => $p->meta,
            'task_class' => $task::class,
        ];

        if (config('ai-tasks.store_request')) {
            // needed to reconstruct the task for ai:retry and webhook completion;
            // gated by store_request because ctor args usually contain the prompt text
            $data['task_args'] = $task->serializeForQueue();

            if ($p->systemPrompt !== null) {
                $data['system'] = $p->systemPrompt;
            }
            $data['messages'] = array_map(function ($m) {
                if (is_string($m)) {
                    return ['role' => 'user', 'content' => $m];
                }
                if (is_array($m)) {
                    return ['role' => $m['role'] ?? 'user', 'content' => $m['content'] ?? ''];
                }
                return ['role' => self::messageRole($m), 'content' => $m->content ?? ''];
            }, $p->messages);
        }

        return $data;
    }

    private static function messageRole(object $m): string
    {
        return $m instanceof \Laravel\Ai\Messages\Message ? $m->role->value : 'user';
    }
}
