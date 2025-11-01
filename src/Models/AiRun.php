<?php

namespace Fomvasss\AiTasks\Models;

use Fomvasss\AiTasks\DTO\AiContext;
use Fomvasss\AiTasks\DTO\AiPayload;
use Fomvasss\AiTasks\DTO\AiResponse;
use Fomvasss\AiTasks\Events\AiRunFailed;
use Fomvasss\AiTasks\Events\AiRunFinished;
use Fomvasss\AiTasks\Events\AiRunStarted;
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

    protected $guarded = ['id'];

    protected $casts = [
        'request' => 'array',
        'response' => 'array',
        'usage' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public static function start(string $driver, AiPayload $p, AiContext $ctx, AiTask $task): self
    {
        $run = static::create([
            'tenant_id' => $ctx->tenantId,
            'task' => $ctx->taskName,
            'driver' => $driver,
            'modality' => $p->modality,
            'subject_type' => $ctx->subjectType,
            'subject_id' => $ctx->subjectId,
            'status' => 'running',
            'idempotency_key' => method_exists($task, 'idempotencyKey') ? $task->idempotencyKey() : null,
            'request' => self::minifyRequest($p),
            'started_at' => now(),
        ]);

        event(new AiRunStarted($run));

        return $run;
    }

    public static function startAsQueue(string $driver, AiPayload $p, AiContext $ctx, AiTask $task): self
    {
        $run = self::create([
            'tenant_id'     => $ctx->tenantId,
            'task'          => $ctx->taskName,
            'driver'        => $driver,
            'modality'      => $p->modality,
            'subject_type'  => $ctx->subjectType,
            'subject_id'    => $ctx->subjectId,
            'status'        => 'queued',
            'idempotency_key'=> $task->idempotencyKey(),
            'request'       => \Fomvasss\AiTasks\Models\AiRun::minifyRequest($p),
            'started_at'    => null,
            'finished_at'   => null,
            'duration_ms'   => null,
        ]);

        return $run;
    }

    public function markRunning(): void
    {
        $this->update([
            'status' => 'running',
            'started_at' => now(),
        ]);

        event(new AiRunStarted($this));
    }

    public function markWaiting(array $resp = []): void
    {
        $resp = array_filter($resp);

        $this->update([
            'status' => 'waiting', 
            'response' => array_merge($this->response ?? [], $resp),
            'finished_at' => null,
            'duration_ms' => null,
        ]);
    }

    public function finish(AiResponse $resp): void
    {
        $ms = $this->started_at ? now()->diffInMilliseconds($this->started_at, true) : null;
        
        $this->update([
            'status' => 'ok',
            'response' => self::storeResponse($resp, $this->modality),
            'usage' => $resp->usage,
            'finished_at' => now(),
            'duration_ms' => $ms,
        ]);

        event(new AiRunFinished($this));
    }

    public function skip(string $reason): void
    {
        $this->update([
            'status' => 'skipped',
            'error' => $reason,
            'finished_at' => now(),
            'duration_ms' => $this->started_at ? max(0, (int) now()->diffInMilliseconds($this->started_at, true)) : null,
        ]);
    }

    public function fail(?string $err, ?array $usage = null): void
    {
        $ms = $this->started_at ? max(0, (int) now()->diffInMilliseconds($this->started_at, true)) : null;
        
        $this->update([
            'status' => 'error',
            'error' => $err ? mb_substr($err, 0, 500) : null,
            'usage' => $usage,
            'finished_at' => now(),
            'duration_ms' => $ms,
        ]);

        event(new AiRunFailed($this));
    }

    public function error(\Throwable $e): void
    {
        $ms = $this->started_at ? max(0, (int) now()->diffInMilliseconds($this->started_at, true)) : null;
        
        $this->update([
            'status' => 'error',
            'error' => mb_substr($e->getMessage(), 0, 500),
            'finished_at' => now(),
            'duration_ms' => $ms,
        ]);

        event(new AiRunFailed($this));
    }

    public static function markAsDead(string $id, \Throwable $e): void
    {
        static::whereKey($id)->update([
            'status' => 'dead',
            'error' => mb_substr($e->getMessage(), 0, 500),
            'finished_at' => now(),
        ]);

        event(new AiRunFailed($run));
    }

    public static function minifyRequest(AiPayload $p): array
    {
        $msg = $p->messages;

        return [
            'modality' => $p->modality,
            'messages' => $msg,
            'options'  => $p->options,
            'meta' => $p->meta,
        ];
    }

    public static function storeResponse(AiResponse $r, ?string $modality = null): array
    {
        $content = $r->content;

        // Якщо зображення у base64 — не зберігати в БД
        if ($modality === 'image' && is_string($content) && ! str_starts_with($content, 'http')) {
            $len = strlen($content);
            $content = "[omitted_base64:${len}bytes]";
        }

        // сирий респонс може містити data[b64_json] — прибираємо важке
        $raw = $r->raw ?? [];
        if (is_array($raw) && isset($raw['data'])) {
            // лишимо тільки мету першого елемента без b64
            $raw = [
                'model'   => $raw['model']   ?? null,
                'created' => $raw['created'] ?? null,
                'has_data'=> true,
            ];
        }

        return [
            'content' => $content,
            'raw'     => $raw,
        ];        
    }
}
