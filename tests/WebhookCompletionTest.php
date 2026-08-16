<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\Tests;

use Fomvasss\AiTasks\AiServiceProvider;
use Fomvasss\AiTasks\DTO\AiPayload;
use Fomvasss\AiTasks\Jobs\PostprocessAiResult;
use Fomvasss\AiTasks\Models\AiRun;
use Fomvasss\AiTasks\Tasks\AiTask;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Messages\UserMessage;
use Orchestra\Testbench\TestCase;

class WebhookTestTask extends AiTask
{
    public function __construct(private readonly string $text) {}

    public function modality(): string
    {
        return 'text';
    }

    public function serializeForQueue(): array
    {
        return [$this->text];
    }

    public function toPayload(): AiPayload
    {
        return new AiPayload('text', [new UserMessage($this->text)]);
    }
}

class WebhookCompletionTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [AiServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->artisan('migrate');
    }

    private function waitingRun(string $providerRunId): AiRun
    {
        $task = new WebhookTestTask('translate me');
        $run = AiRun::startAsQueue('openai', $task->toPayload(), $task->context(), $task);
        $run->markWaiting(['provider_run_id' => $providerRunId]);

        return $run;
    }

    public function test_webhook_finishes_waiting_run_and_dispatches_postprocess_with_task_class(): void
    {
        config(['ai-tasks.store_request' => true]);
        Queue::fake();

        $run = $this->waitingRun('resp_123');

        $response = $this->postJson('/ai-webhooks/openai', [
            'data' => ['id' => 'resp_123', 'status' => 'succeeded', 'output' => 'done'],
        ]);

        $response->assertOk()->assertJson(['ok' => true]);

        $run->refresh();
        $this->assertSame('ok', $run->status);
        $this->assertSame('done', $run->response['content']);

        Queue::assertPushed(PostprocessAiResult::class, function (PostprocessAiResult $job) use ($run) {
            return $job->aiRunId === $run->id
                && $job->taskClass === WebhookTestTask::class
                && $job->taskCtorArgs === ['translate me'];
        });
    }

    public function test_webhook_without_stored_args_finishes_run_but_skips_postprocess(): void
    {
        config(['ai-tasks.store_request' => false]);
        Queue::fake();

        $run = $this->waitingRun('resp_456');

        $this->postJson('/ai-webhooks/openai', [
            'data' => ['id' => 'resp_456', 'status' => 'succeeded', 'output' => 'done'],
        ])->assertOk();

        $run->refresh();
        $this->assertSame('ok', $run->status);

        // task has a required ctor param and no stored args — not reconstructable
        Queue::assertNotPushed(PostprocessAiResult::class);
    }

    public function test_webhook_failure_marks_run_failed(): void
    {
        Queue::fake();

        $run = $this->waitingRun('resp_789');

        $this->postJson('/ai-webhooks/openai', [
            'data' => ['id' => 'resp_789', 'status' => 'failed', 'error' => ['message' => 'boom']],
        ])->assertOk();

        $run->refresh();
        $this->assertSame('error', $run->status);
        $this->assertSame('boom', $run->error);
    }

    public function test_webhook_with_secret_configured_rejects_unsigned_request(): void
    {
        config(['ai-tasks.drivers.openai.webhook.secret' => 'whsec_' . base64_encode('k')]);
        Queue::fake();

        $run = $this->waitingRun('resp_signed');

        $this->postJson('/ai-webhooks/openai', [
            'data' => ['id' => 'resp_signed', 'status' => 'succeeded', 'output' => 'done'],
        ])->assertStatus(401);

        $run->refresh();
        $this->assertSame('waiting', $run->status);
    }

    public function test_webhook_with_secret_configured_accepts_correctly_signed_request(): void
    {
        $rawKey = 'k';
        $secret = 'whsec_' . base64_encode($rawKey);
        config(['ai-tasks.drivers.openai.webhook.secret' => $secret]);
        Queue::fake();

        $run = $this->waitingRun('resp_signed_ok');

        $body = json_encode(['data' => ['id' => 'resp_signed_ok', 'status' => 'succeeded', 'output' => 'done']]);
        $id = 'msg_1';
        $ts = (string) now()->getTimestamp();
        $sig = base64_encode(hash_hmac('sha256', "{$id}.{$ts}.{$body}", $rawKey, true));

        $this->call('POST', '/ai-webhooks/openai', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_WEBHOOK_ID' => $id,
            'HTTP_WEBHOOK_TIMESTAMP' => $ts,
            'HTTP_WEBHOOK_SIGNATURE' => "v1,{$sig}",
        ], $body)->assertOk();

        $run->refresh();
        $this->assertSame('ok', $run->status);
    }
}
