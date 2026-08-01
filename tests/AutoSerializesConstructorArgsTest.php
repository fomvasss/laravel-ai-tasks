<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\Tests;

use Fomvasss\AiTasks\AiServiceProvider;
use Fomvasss\AiTasks\DTO\AiPayload;
use Fomvasss\AiTasks\Jobs\ProcessAiPayload;
use Fomvasss\AiTasks\Tasks\AiTask;
use Fomvasss\AiTasks\Traits\AutoSerializesConstructorArgs;
use Illuminate\Contracts\Database\ModelIdentifier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Ai\Messages\UserMessage;
use Orchestra\Testbench\TestCase;

class AutoSerializesConstructorArgsTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [AiServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('test_articles', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->timestamps();
        });
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function makeArticle(string $title = 'Original'): TestArticle
    {
        return TestArticle::create(['title' => $title]);
    }

    // ── serializeForQueue() ───────────────────────────────────────────────

    public function test_model_constructor_arg_is_swapped_for_a_model_identifier(): void
    {
        $article = $this->makeArticle();
        $task    = new ModelArgTask($article);

        $args = $task->serializeForQueue();

        $this->assertCount(1, $args);
        $this->assertInstanceOf(ModelIdentifier::class, $args[0]);
        $this->assertSame(TestArticle::class, $args[0]->class);
        $this->assertSame($article->id, $args[0]->id);
    }

    public function test_scalar_constructor_args_pass_through_unchanged(): void
    {
        $article = $this->makeArticle();
        $task    = new MixedArgTask($article, limit: 5);

        $args = $task->serializeForQueue();

        $this->assertInstanceOf(ModelIdentifier::class, $args[0]);
        $this->assertSame(5, $args[1]);
    }

    public function test_no_arg_constructor_serializes_to_empty_array(): void
    {
        $task = new NoArgTask();

        $this->assertSame([], $task->serializeForQueue());
    }

    public function test_throws_when_a_constructor_parameter_is_not_promoted(): void
    {
        $task = new UnpromotedArgTask('x');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('non-promoted parameter $text');

        $task->serializeForQueue();
    }

    // ── fromQueueArgs() ───────────────────────────────────────────────────

    public function test_from_queue_args_restores_a_fresh_model_not_a_stale_snapshot(): void
    {
        $article = $this->makeArticle('Original');
        $task    = new ModelArgTask($article);
        $args    = $task->serializeForQueue();

        $article->update(['title' => 'Updated']);

        $restored = ModelArgTask::fromQueueArgs($args);

        $this->assertSame('Updated', $restored->title());
    }

    public function test_idempotency_key_is_stable_across_repeated_calls(): void
    {
        $task = new ModelArgTask($this->makeArticle());

        $this->assertSame($task->idempotencyKey(), $task->idempotencyKey());
    }

    // ── Round-trip through real PHP serialization (as Redis/queue transport does) ──

    public function test_task_ctor_args_survive_native_php_serialization_inside_the_job(): void
    {
        $article = $this->makeArticle('Original');
        $task    = new ModelArgTask($article);

        $job = new ProcessAiPayload(
            driverName: 'driverA',
            payload: $task->toPayload(),
            context: $task->context(),
            runId: (string) Str::uuid(),
            taskClass: $task::class,
            taskCtorArgs: $task->serializeForQueue(),
        );

        $roundTripped = unserialize(serialize($job));

        $article->update(['title' => 'Updated']);

        $restored = ModelArgTask::fromQueueArgs($roundTripped->taskCtorArgs);

        $this->assertSame('Updated', $restored->title());
    }
}

// ── Fixtures ─────────────────────────────────────────────────────────────

class TestArticle extends Model
{
    protected $table = 'test_articles';
    protected $guarded = [];
}

class ModelArgTask extends AiTask
{
    use AutoSerializesConstructorArgs;

    public function __construct(
        private readonly TestArticle $article,
    ) {}

    public function modality(): string { return 'text'; }

    public function toPayload(): AiPayload
    {
        return new AiPayload('text', messages: [new UserMessage($this->article->title)]);
    }

    public function title(): string { return $this->article->title; }
}

class MixedArgTask extends AiTask
{
    use AutoSerializesConstructorArgs;

    public function __construct(
        private readonly TestArticle $article,
        private readonly int $limit,
    ) {}

    public function modality(): string { return 'text'; }
    public function toPayload(): AiPayload { return new AiPayload('text'); }
}

class NoArgTask extends AiTask
{
    use AutoSerializesConstructorArgs;

    public function modality(): string { return 'text'; }
    public function toPayload(): AiPayload { return new AiPayload('text'); }
}

class UnpromotedArgTask extends AiTask
{
    use AutoSerializesConstructorArgs;

    public function __construct(string $text)
    {
        $this->stashed = $text;
    }

    private string $stashed;

    public function modality(): string { return 'text'; }
    public function toPayload(): AiPayload { return new AiPayload('text'); }
}
