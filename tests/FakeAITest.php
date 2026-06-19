<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\Tests;

use Fomvasss\AiTasks\AiServiceProvider;
use Fomvasss\AiTasks\DTO\AiPayload;
use Fomvasss\AiTasks\Facades\AI;
use Fomvasss\AiTasks\Tasks\AiTask;
use Laravel\Ai\Messages\UserMessage;
use Orchestra\Testbench\TestCase;

class FakeAITest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [AiServiceProvider::class];
    }

    private function makeTask(string $name = 'summarize', string $text = 'hello'): AiTask
    {
        return (new class($text) extends AiTask {
            public function __construct(private string $text) {}
            public function modality(): string { return 'text'; }
            public function toPayload(): AiPayload
            {
                return new AiPayload('text', [new UserMessage($this->text)]);
            }
        })->setName($name);
    }

    public function test_fake_returns_default_response(): void
    {
        AI::fake();

        $resp = AI::send($this->makeTask());

        $this->assertSame('fake ai response', $resp->content);
        $this->assertTrue($resp->ok);
    }

    public function test_fake_returns_custom_string_response(): void
    {
        AI::fake('Summary here.');

        $resp = AI::send($this->makeTask());

        $this->assertSame('Summary here.', $resp->content);
    }

    public function test_fake_maps_response_by_task_name(): void
    {
        AI::fake([
            'summarize'  => 'This is a summary.',
            'translate'  => 'Це переклад.',
        ]);

        $this->assertSame('This is a summary.', AI::send($this->makeTask('summarize'))->content);
        $this->assertSame('Це переклад.',       AI::send($this->makeTask('translate'))->content);
    }

    public function test_fake_falls_back_to_wildcard(): void
    {
        AI::fake([
            'summarize' => 'Summary.',
            '*'         => 'Default response.',
        ]);

        $this->assertSame('Default response.', AI::send($this->makeTask('unknown'))->content);
    }

    public function test_assert_sent(): void
    {
        $fake = AI::fake();

        AI::send($this->makeTask('summarize'));

        $fake->assertSent(AiTask::class);
    }

    public function test_assert_sent_with_callback(): void
    {
        $fake = AI::fake();

        AI::send($this->makeTask('summarize', 'hello world'));

        $fake->assertSent(AiTask::class, fn(AiTask $t) => $t->name() === 'summarize');
    }

    public function test_assert_not_sent(): void
    {
        $fake = AI::fake();

        $fake->assertNotSent(AiTask::class);
    }

    public function test_assert_queued(): void
    {
        $fake = AI::fake();

        AI::queue($this->makeTask('summarize'));

        $fake->assertQueued(AiTask::class);
    }

    public function test_assert_sent_count(): void
    {
        $fake = AI::fake();

        AI::send($this->makeTask());
        AI::send($this->makeTask());
        AI::stream($this->makeTask(), fn($c) => null);

        $fake->assertSentCount(3);
    }

    public function test_assert_nothing_sent(): void
    {
        $fake = AI::fake();

        $fake->assertNothingSent();
    }

    public function test_stream_calls_callback(): void
    {
        AI::fake('chunk text');

        $received = '';
        AI::stream($this->makeTask(), function (string $chunk) use (&$received) {
            $received .= $chunk;
        });

        $this->assertSame('chunk text', $received);
    }

    public function test_queue_returns_uuid_string(): void
    {
        AI::fake();

        $runId = AI::queue($this->makeTask());

        $this->assertIsString($runId);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $runId);
    }

    public function test_queue_accepts_integer_delay(): void
    {
        AI::fake();

        $runId = AI::queue($this->makeTask(), delay: 300);

        $this->assertIsString($runId);
    }

    public function test_queue_accepts_carbon_delay(): void
    {
        AI::fake();

        $runId = AI::queue($this->makeTask(), delay: now()->addMinutes(5));

        $this->assertIsString($runId);
    }
}
