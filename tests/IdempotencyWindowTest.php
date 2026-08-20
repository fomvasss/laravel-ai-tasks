<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\Tests;

use Fomvasss\AiTasks\AiServiceProvider;
use Fomvasss\AiTasks\DTO\AiPayload;
use Fomvasss\AiTasks\Tasks\AiTask;
use Laravel\Ai\Messages\UserMessage;
use Orchestra\Testbench\TestCase;

class IdempotencyWindowTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [AiServiceProvider::class];
    }

    private function makeTask(string $text, ?string $window): AiTask
    {
        return new class($text, $window) extends AiTask {
            public function __construct(private string $text, private ?string $window) {}
            public function modality(): string { return 'text'; }
            public function serializeForQueue(): array { return [$this->text]; }
            public function idempotencyWindow(): ?string { return $this->window; }
            public function toPayload(): AiPayload
            {
                return new AiPayload('text', [new UserMessage($this->text)]);
            }
        };
    }

    public function test_null_window_key_matches_legacy_key(): void
    {
        $task = $this->makeTask('hello', null);

        $legacy = hash('xxh3', json_encode([
            $task->context()->tenantId,
            $task->name(),
            $task->modality(),
            ['hello'],
        ]));

        $this->assertSame($legacy, $task->idempotencyKey());
    }

    public function test_different_windows_produce_different_keys(): void
    {
        $a = $this->makeTask('hello', '2026-08-15');
        $b = $this->makeTask('hello', '2026-08-16');

        $this->assertNotSame($a->idempotencyKey(), $b->idempotencyKey());
    }

    public function test_same_window_produces_same_key(): void
    {
        $a = $this->makeTask('hello', '2026-08-16');
        $b = $this->makeTask('hello', '2026-08-16');

        $this->assertSame($a->idempotencyKey(), $b->idempotencyKey());
    }

    public function test_windowed_key_differs_from_unwindowed(): void
    {
        $a = $this->makeTask('hello', null);
        $b = $this->makeTask('hello', '2026-08-16');

        $this->assertNotSame($a->idempotencyKey(), $b->idempotencyKey());
    }
}
