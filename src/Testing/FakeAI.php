<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\Testing;

use Fomvasss\AiTasks\Core\AI;
use Fomvasss\AiTasks\DTO\AiResponse;
use Fomvasss\AiTasks\Models\AiRun;
use Fomvasss\AiTasks\Tasks\AiTask;
use Fomvasss\AiTasks\Tasks\PromptTask;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert as PHPUnit;

final class FakeAI
{
    /** @var array<int, array{method: string, task: AiTask, drivers: array}> */
    private array $recorded = [];

    /**
     * Task name → fake answer, '*' = catch-all. An answer is the response text, an array (the
     * structured output of a schema() task, JSON-encoded into content too) or a whole AiResponse.
     *
     * @var array<string, string|array|AiResponse>
     */
    private array $responses;

    public function __construct(string|array|AiResponse|null $responses = null)
    {
        $this->responses = match (true) {
            is_array($responses) => $responses,
            $responses !== null => ['*' => $responses],
            default => ['*' => 'fake ai response'],
        };
    }

    public function prompt(string $prompt, ?string $system = null, array|string $drivers = [], string $name = 'prompt'): AiResponse
    {
        return $this->send(new PromptTask($prompt, $system, $name), $drivers);
    }

    public function send(AiTask $task, array|string $drivers = []): AiResponse
    {
        $this->record('send', $task, $drivers);

        return $this->completeLikeReal($task, $this->resolve($task));
    }

    public function stream(AiTask $task, callable $onChunk, array|string $drivers = []): AiResponse
    {
        $this->record('stream', $task, $drivers);
        $resp = $this->resolve($task);
        $onChunk((string) $resp->content);

        return $this->completeLikeReal($task, $resp);
    }

    public function queue(AiTask $task, array|string $drivers = [], \DateTimeInterface|\DateInterval|int|null $delay = null): string
    {
        // same guard as the real AI::queue(), so tests catch unreconstructable tasks early
        $ctor = (new \ReflectionClass($task))->getConstructor();
        if ($ctor && $ctor->getNumberOfRequiredParameters() > 0 && empty($task->serializeForQueue())) {
            throw new \LogicException(
                $task::class . ' has constructor parameters but serializeForQueue() returns []. ' .
                'Implement serializeForQueue() to enable queue reconstruction and idempotency.'
            );
        }

        $this->record('queue', $task, $drivers);

        return (string) Str::uuid();
    }

    /**
     * Mirror the real pipeline's completion: run postprocess(), then onCompleted() and
     * the AiTaskCompleted event via AI::complete(), with an unsaved AiRun stand-in.
     */
    private function completeLikeReal(AiTask $task, AiResponse $resp): AiResponse
    {
        $result = $task->postprocess($resp);

        $finalResponse = $result instanceof AiResponse ? $result : new AiResponse(true, json_encode($result));

        $run = new AiRun([
            'tenant_id' => $task->context()->tenantId,
            'task' => $task->name(),
            'driver' => 'fake',
            'modality' => $task->modality(),
            'dispatch' => 'sync',
            'status' => 'ok',
        ]);

        AI::complete($task, $result, $finalResponse, $run);

        return $finalResponse;
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

    private function resolve(AiTask $task): AiResponse
    {
        $answer = $this->responses[$task->name()] ?? $this->responses['*'] ?? 'fake ai response';

        if ($answer instanceof AiResponse) {
            return $answer;
        }

        $usage = ['driver' => 'fake', 'tokens_in' => 0, 'tokens_out' => 0, 'cost' => 0.0];

        return is_array($answer)
            ? new AiResponse(true, json_encode($answer, JSON_UNESCAPED_UNICODE), $usage, structured: $answer)
            : new AiResponse(true, $answer, $usage);
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
