<?php

namespace Fomvasss\AiTasks\Models;

use Fomvasss\AiTasks\DTO\AiContext;
use Fomvasss\AiTasks\DTO\AiPayload;
use Fomvasss\AiTasks\DTO\AiResponse;
use Fomvasss\AiTasks\Tasks\AiTask;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class AiRun extends Model
{
    use HasUuids;

    protected $table = 'ai_runs';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'request' => 'array',
        'response' => 'array',
        'usage' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public static function start(string $driver, AiPayload $p, AiContext $c, AiTask $task): self
    {
        return static::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $c->tenantId,
            'task' => $c->taskName,
            'driver' => $driver,
            'modality' => $p->modality,
            'subject_type' => $c->subjectType,
            'subject_id' => $c->subjectId,
            'status' => 'running',
            'idempotency_key' => method_exists($task, 'idempotencyKey') ? $task->idempotencyKey() : null,
            'request' => self::minifyRequest($p),
            'started_at' => now(),
        ]);
    }

    public function finish(AiResponse $resp): void
    {
        $ms = max(0, (int) now()->diffInMilliseconds($this->started_at));
        $this->update([
            'status' => 'ok',
            'response' => self::storeResponse($resp),
            'usage' => $resp->usage,
            'finished_at' => now(),
            'duration_ms' => $ms,
        ]);
    }

    public function fail(?string $err, ?array $usage = null): void
    {
        $ms = $this->started_at ? max(0, (int) now()->diffInMilliseconds($this->started_at)) : null;
        $this->update([
            'status' => 'error',
            'error' => $err ? mb_substr($err, 0, 500) : null,
            'usage' => $usage,
            'finished_at' => now(),
            'duration_ms' => $ms,
        ]);
    }

    public function error(\Throwable $e): void
    {
        $ms = $this->started_at ? max(0, (int) now()->diffInMilliseconds($this->started_at)) : null;
        $this->update([
            'status' => 'error',
            'error' => mb_substr($e->getMessage(), 0, 500),
            'finished_at' => now(),
            'duration_ms' => $ms,
        ]);
    }

    public static function markAsDead(string $id, \Throwable $e): void
    {
        static::whereKey($id)->update([
            'status' => 'dead',
            'error' => mb_substr($e->getMessage(), 0, 500),
            'finished_at' => now(),
        ]);
    }

    public static function minifyRequest(AiPayload $p): array
    {
        $msg = $p->messages;

        return [
            'modality' => $p->modality,
            'messages' => $msg,
            'options'  => $p->options,
            'template' => $p->template,
            'schema'   => $p->schema,
        ];
    }

    public static function storeResponse(AiResponse $r): array
    {
        return [
            'content' => $r->content,
            'raw_meta'=> ['model' => $r->raw['model'] ?? null],
        ];
    }
}
