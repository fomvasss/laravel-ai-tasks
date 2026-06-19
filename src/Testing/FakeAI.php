<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\Testing;

use Fomvasss\AiTasks\DTO\AiResponse;
use Fomvasss\AiTasks\Tasks\AiTask;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert as PHPUnit;

final class FakeAI
{
    /** @var array<int, array{method: string, task: AiTask, drivers: array}> */
    private array $recorded = [];

    /** @var array<string, string> task name → response text, '*' = catch-all */
    private array $responses;

    public function __construct(string|array|null $responses = null)
    {
        if (is_string($responses)) {
            $this->responses = ['*' => $responses];
        } elseif (is_array($responses)) {
            $this->responses = $responses;
        } else {
            $this->responses = ['*' => 'fake ai response'];
        }
    }

    public function send(AiTask $task, array|string $drivers = []): AiResponse
    {
        $this->record('send', $task, $drivers);
        $text = $this->resolve($task);
        $resp = new AiResponse(true, $text, ['driver' => 'fake', 'tokens_in' => 0, 'tokens_out' => 0, 'cost' => 0.0]);

        $result = $task->postprocess($resp);

        return $result instanceof AiResponse ? $result : new AiResponse(true, json_encode($result));
    }

    public function stream(AiTask $task, callable $onChunk, array|string $drivers = []): AiResponse
    {
        $this->record('stream', $task, $drivers);
        $text = $this->resolve($task);
        $onChunk($text);

        $resp = new AiResponse(true, $text, ['driver' => 'fake', 'tokens_in' => 0, 'tokens_out' => 0, 'cost' => 0.0]);

        $result = $task->postprocess($resp);

        return $result instanceof AiResponse ? $result : new AiResponse(true, json_encode($result));
    }

    public function queue(AiTask $task, array|string $drivers = [], \DateTimeInterface|\DateInterval|int|null $delay = null): string
    {
        $this->record('queue', $task, $drivers);

        return (string) Str::uuid();
    }

    // ── Assertions ────────────────────────────────────────────────────────

    public function assertSent(string $taskClass, ?callable $callback = null): void
    {
        $sent = collect($this->recorded)->filter(fn($r) => $r['task'] instanceof $taskClass);

        PHPUnit::assertTrue(
            $sent->isNotEmpty(),
            "Expected [{$taskClass}] to be sent but it was not.",
        );

        if ($callback !== null) {
            PHPUnit::assertTrue(
                $sent->filter(fn($r) => $callback($r['task'], $r['method']))->isNotEmpty(),
                "No [{$taskClass}] was sent that satisfies the given callback.",
            );
        }
    }

    public function assertNotSent(string $taskClass): void
    {
        $sent = collect($this->recorded)->filter(fn($r) => $r['task'] instanceof $taskClass);

        PHPUnit::assertTrue(
            $sent->isEmpty(),
            "Expected [{$taskClass}] not to be sent but it was.",
        );
    }

    public function assertQueued(string $taskClass, ?callable $callback = null): void
    {
        $queued = collect($this->recorded)
            ->filter(fn($r) => $r['method'] === 'queue' && $r['task'] instanceof $taskClass);

        PHPUnit::assertTrue(
            $queued->isNotEmpty(),
            "Expected [{$taskClass}] to be queued but it was not.",
        );

        if ($callback !== null) {
            PHPUnit::assertTrue(
                $queued->filter(fn($r) => $callback($r['task']))->isNotEmpty(),
                "No [{$taskClass}] was queued that satisfies the given callback.",
            );
        }
    }

    public function assertSentCount(int $count): void
    {
        PHPUnit::assertCount($count, $this->recorded, "Expected {$count} AI calls but got " . count($this->recorded) . '.');
    }

    public function assertNothingSent(): void
    {
        PHPUnit::assertEmpty($this->recorded, 'Expected no AI calls but ' . count($this->recorded) . ' were made.');
    }

    /** @return array<int, array{method: string, task: AiTask, drivers: array}> */
    public function recorded(): array
    {
        return $this->recorded;
    }

    // ── Internals ─────────────────────────────────────────────────────────

    private function resolve(AiTask $task): string
    {
        return $this->responses[$task->name()] ?? $this->responses['*'] ?? 'fake ai response';
    }

    private function record(string $method, AiTask $task, array|string $drivers): void
    {
        $this->recorded[] = [
            'method'  => $method,
            'task'    => $task,
            'drivers' => (array) $drivers,
        ];
    }
}
