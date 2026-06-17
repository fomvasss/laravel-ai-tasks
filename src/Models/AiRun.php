<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\Models;

use Fomvasss\AiTasks\DTO\AiContext;
use Fomvasss\AiTasks\DTO\AiPayload;
use Fomvasss\AiTasks\DTO\AiResponse;
use Fomvasss\AiTasks\Events\AiRunFailed;
use Fomvasss\AiTasks\Events\AiRunFinished;
use Fomvasss\AiTasks\Tasks\AiTask;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AiRun extends Model
{
    use HasUuids;

    protected $table = 'ai_runs';

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

    public static function start(string $driver, AiPayload $p, AiContext $ctx, AiTask $task): self
    {
        return static::create([
            'tenant_id'       => $ctx->tenantId,
            'task'            => $ctx->taskName,
            'driver'          => $driver,
            'modality'        => $p->modality,
            'subject_type'    => $ctx->subjectType,
            'subject_id'      => $ctx->subjectId,
            'status'          => 'running',
            'idempotency_key' => $task->idempotencyKey(),
            'request'         => static::minifyRequest($p),
            'started_at'      => now(),
        ]);
    }

    public static function startAsQueue(string $driver, AiPayload $p, AiContext $ctx, AiTask $task): self
    {
        return static::create([
            'tenant_id'       => $ctx->tenantId,
            'task'            => $ctx->taskName,
            'driver'          => $driver,
            'modality'        => $p->modality,
            'subject_type'    => $ctx->subjectType,
            'subject_id'      => $ctx->subjectId,
            'status'          => 'queued',
            'idempotency_key' => $task->idempotencyKey(),
            'request'         => static::minifyRequest($p),
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
            'response'          => ['content' => $resp->content],
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

    public function fail(string $error, array $usage = []): void
    {
        $ms = $this->started_at
            ? (int) now()->diffInMilliseconds($this->started_at, true)
            : null;

        $this->update([
            'status'      => 'error',
            'error'       => $error,
            'tokens_in'   => $usage['tokens_in']  ?? null,
            'tokens_out'  => $usage['tokens_out'] ?? null,
            'finished_at' => now(),
            'duration_ms' => $ms,
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

    private static function minifyRequest(AiPayload $p): array
    {
        $data = [
            'modality' => $p->modality,
            'options'  => $p->options,
            'meta'     => $p->meta,
        ];

        if (config('ai.store_request')) {
            if ($p->systemPrompt !== null) {
                $data['system'] = $p->systemPrompt;
            }
            $data['messages'] = array_map(
                fn($m) => ['role' => self::messageRole($m), 'content' => $m->content],
                $p->messages,
            );
        }

        return $data;
    }

    private static function messageRole(object $m): string
    {
        return match (true) {
            $m instanceof \Prism\Prism\ValueObjects\Messages\AssistantMessage => 'assistant',
            default => 'user',
        };
    }
}
