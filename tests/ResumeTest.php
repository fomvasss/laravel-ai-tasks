<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\Tests;

use Fomvasss\AiTasks\AiServiceProvider;
use Fomvasss\AiTasks\Core\AiManager;
use Fomvasss\AiTasks\DTO\AiPayload;
use Fomvasss\AiTasks\DTO\AiResponse;
use Fomvasss\AiTasks\Exceptions\ApprovalResumeException;
use Fomvasss\AiTasks\Facades\AI;
use Fomvasss\AiTasks\Jobs\PostprocessAiResult;
use Fomvasss\AiTasks\Jobs\ProcessAiPayload;
use Fomvasss\AiTasks\Models\AiRun;
use Fomvasss\AiTasks\Tasks\AiTask;
use Fomvasss\AiTasks\Traits\ActsAsDispatchingUser;
use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Concerns\InteractsWithApprovals;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Tools\Request;
use Orchestra\Testbench\TestCase;

/**
 * Пауза перед tool з підтвердженням і продовження через AI::resume(): пакет сам зберігає
 * паузований хід і відтворює його — застосунок передає лише рішення.
 */
class ResumeTest extends TestCase
{
    private \Illuminate\Http\Client\ResponseSequence $sequence;

    protected function getPackageProviders($app): array
    {
        return [AiServiceProvider::class, \Laravel\Ai\AiServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('ai.providers.openai.key', 'test');
        $app['config']->set('ai-tasks.drivers.openai.model', 'gpt-test');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->artisan('migrate');

        ResumeTestOrderTool::$placed = [];
        ResumeTestOrderTool::$placedBy = [];
        ResumeTestTask::$completed = null;
        ResumeTestLookupTool::$calls = 0;

        Http::fake(['api.openai.com/*' => $this->sequence = Http::sequence()]);
    }

    /** Responses API: модель викликає tools — [call_id, name, arguments] */
    private static function toolCalls(array ...$calls): array
    {
        return [
            'id' => 'resp_' . uniqid(),
            'status' => 'completed',
            'model' => 'gpt-test',
            'output' => array_map(fn (array $c): array => [
                'type' => 'function_call',
                'id' => 'fc_' . $c[0],
                'call_id' => $c[0],
                'name' => $c[1],
                'arguments' => json_encode($c[2] ?: new \stdClass),
                'status' => 'completed',
            ], $calls),
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ];
    }

    private static function text(string $text): array
    {
        return [
            'id' => 'resp_' . uniqid(),
            'status' => 'completed',
            'model' => 'gpt-test',
            'output' => [['type' => 'message', 'status' => 'completed', 'content' => [['type' => 'output_text', 'text' => $text]]]],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ];
    }

    /** Наступні відповіді провайдера — одна послідовність на тест: повторний Http::fake() її не замінює */
    private function provider(array ...$responses): void
    {
        foreach ($responses as $response) {
            $this->sequence->push($response);
        }
    }

    private function pause(?array $step = null): AiRun
    {
        $this->provider($step ?? self::toolCalls(['call_order', 'place_order', ['qty' => 2]]));

        $response = AI::send(new ResumeTestTask('buy two'), 'openai');

        $this->assertTrue($response->paused());

        return AiRun::findOrFail($response->runId);
    }

    /** Що пішло провайдеру в останньому запиті — input Responses API */
    private function lastInput(): array
    {
        return Http::recorded()->last()[0]->data()['input'];
    }

    public function test_a_gated_tool_call_pauses_the_run_and_stores_the_turn(): void
    {
        $run = $this->pause();

        $this->assertSame('paused', $run->status);
        $this->assertSame([], ResumeTestOrderTool::$placed);
        $this->assertSame('place_order', $run->response['pending_approvals'][0]['tool']);
        $this->assertSame('fc_call_order', $run->response['resume']['messages'][0]['tool_calls'][0]['id']);
        $this->assertSame('openai', $run->response['resume']['messages'][0]['replay_blocks_provider']);
        $this->assertNotNull($run->response['resume']['expires_at']);
    }

    public function test_resume_runs_the_approved_tool_and_continues(): void
    {
        $paused = $this->pause();
        $this->provider(self::text('Order placed.'));

        $response = AI::resume(new ResumeTestTask('buy two'), $paused->id, ['fc_call_order' => true], 'openai');

        $this->assertSame('Order placed.', $response->content);
        $this->assertSame([['qty' => 2]], ResumeTestOrderTool::$placed);
        // історія — рівно та, що й до паузи: один prompt користувача, без дублів
        $userTexts = array_map(fn ($i) => $i['content'][0]['text'], array_values(array_filter($this->lastInput(), fn ($i) => ($i['role'] ?? null) === 'user')));
        $this->assertSame(['buy two'], $userTexts);
        $this->assertSame('ok', $paused->fresh()->status);
        $this->assertSame($paused->id, AiRun::findOrFail($response->runId)->request['meta']['resumed_from']);
    }

    public function test_a_second_resume_of_the_same_pause_is_refused(): void
    {
        $paused = $this->pause();
        $this->provider(self::text('Order placed.'));
        AI::resume(new ResumeTestTask('buy two'), $paused->id, ['fc_call_order' => true], 'openai');

        $this->expectException(ApprovalResumeException::class);

        AI::resume(new ResumeTestTask('buy two'), $paused->id, ['fc_call_order' => true], 'openai');
    }

    public function test_an_expired_pause_is_refused_and_nothing_runs(): void
    {
        $paused = $this->pause();
        $this->travel(61)->minutes();

        try {
            AI::resume(new ResumeTestTask('buy two'), $paused->id, ['fc_call_order' => true], 'openai');
            $this->fail('expired pause resumed');
        } catch (ApprovalResumeException $e) {
            $this->assertStringContainsString('expired', $e->getMessage());
        }

        $this->assertSame([], ResumeTestOrderTool::$placed);
        $this->assertSame('paused', $paused->fresh()->status);
    }

    /** Tool прибрали з tools() за час паузи — пауза лишається, щоб застосунок вирішив, що далі */
    public function test_resume_is_refused_when_the_tool_is_gone(): void
    {
        $paused = $this->pause();

        try {
            AI::resume(new ResumeTestTask('buy two', withOrderTool: false), $paused->id, ['fc_call_order' => true], 'openai');
            $this->fail('resumed without the tool');
        } catch (ApprovalResumeException $e) {
            $this->assertStringContainsString('place_order', $e->getMessage());
        }

        $this->assertSame('paused', $paused->fresh()->status);
    }

    public function test_resume_with_another_task_class_is_refused(): void
    {
        $paused = $this->pause();

        $this->expectException(ApprovalResumeException::class);

        AI::resume(new ResumeTestOtherTask, $paused->id, ['fc_call_order' => true], 'openai');
    }

    /** Відмова без тексту з reject_reason: модель отримує причину й відповідає сама */
    public function test_rejection_carries_the_configured_reason(): void
    {
        config(['ai-tasks.approvals.reject_reason' => 'The customer declined.']);
        $paused = $this->pause();
        $this->provider(self::text('Okay, nothing ordered.'));

        $response = AI::resume(new ResumeTestTask('buy two'), $paused->id, ['fc_call_order' => false], 'openai');

        $this->assertSame('Okay, nothing ordered.', $response->content);
        $this->assertSame([], ResumeTestOrderTool::$placed);
        $this->assertContains('The customer declined.', array_column($this->lastInput(), 'output'));
    }

    /**
     * Модель за один крок викликала й lookup без підтвердження, і замовлення. Lookup уже виконано
     * до паузи — на resume його результат має бути в історії, а сам він не повторюється.
     */
    public function test_mixed_step_keeps_the_result_of_the_tool_that_already_ran(): void
    {
        $paused = $this->pause(self::toolCalls(['call_lookup', 'lookup', []], ['call_order', 'place_order', ['qty' => 2]]));
        $roles  = array_column($paused->response['resume']['messages'], 'role');

        $this->assertSame(1, ResumeTestLookupTool::$calls);
        $this->assertContains('tool_result', $roles);

        $this->provider(self::text('Done.'));
        AI::resume(new ResumeTestTask('buy two'), $paused->id, ['fc_call_order' => true], 'openai');

        $this->assertSame(1, ResumeTestLookupTool::$calls);
        $this->assertSame([['qty' => 2]], ResumeTestOrderTool::$placed);

        // провайдер бачить обидва виклики з результатами: lookup — з паузи, order — щойно виконаний
        $outputs = array_column(array_filter($this->lastInput(), fn ($i) => ($i['type'] ?? null) === 'function_call_output'), 'output', 'call_id');
        $this->assertSame(['call_lookup' => 'in stock', 'call_order' => 'placed'], $outputs);
    }

    public function test_queue_resume_does_not_collide_with_the_paused_runs_idempotency_key(): void
    {
        Queue::fake();
        $paused = $this->pause();

        $runId = AI::queueResume(new ResumeTestTask('buy two'), $paused->id, ['fc_call_order' => true], 'openai');

        $this->assertNotSame($paused->id, $runId);
        $this->assertSame($paused->id, AiRun::findOrFail($runId)->request['meta']['resumed_from']);
    }

    /** Увесь шлях через чергу: пауза у воркері, postprocess бачить її, queueResume виконує tool */
    public function test_queued_pause_and_queued_resume(): void
    {
        Queue::fake();
        $this->provider(self::toolCalls(['call_order', 'place_order', ['qty' => 2]]), self::text('Order placed.'));

        $runId = AI::queue(new ResumeTestTask('buy two'), 'openai');
        $this->work(ProcessAiPayload::class, 0);
        $this->assertSame('paused', AiRun::find($runId)->status);

        (new PostprocessAiResult($runId, ResumeTestTask::class, ['buy two', true]))->handle();
        $this->assertSame($runId, ResumeTestTask::$completed->runId);
        $this->assertTrue(ResumeTestTask::$completed->paused());

        $resumedId = AI::queueResume(new ResumeTestTask('buy two'), $runId, ['fc_call_order' => true], 'openai');
        $this->work(ProcessAiPayload::class, 1);

        $this->assertSame([['qty' => 2]], ResumeTestOrderTool::$placed);
        $this->assertSame('ok', AiRun::find($resumedId)->status);
        $this->assertSame('Order placed.', AiRun::find($resumedId)->response['content']);
    }

    /** Відповідає інша людина (менеджер, webhook) — tools однаково діють від імені автора паузи */
    public function test_resume_acts_as_the_user_who_paused(): void
    {
        config(['auth.providers.users' => ['driver' => 'resume-test']]);
        Auth::provider('resume-test', fn () => new ResumeTestUserProvider);
        Auth::setUser(new GenericUser(['id' => 42]));

        $this->provider(self::toolCalls(['call_order', 'place_order', ['qty' => 2]]), self::text('Done.'));
        $paused = AI::send(new ResumeTestUserTask('buy two'), 'openai');

        Auth::setUser($manager = new GenericUser(['id' => 99]));
        AI::resume(new ResumeTestUserTask('buy two'), $paused->runId, ['fc_call_order' => true], 'openai');

        $this->assertSame([42], ResumeTestOrderTool::$placedBy);
        $this->assertSame($manager, Auth::user());
    }

    /** Порожній content паузи не має вважатись поганою відповіддю — інакше користувача спитають удруге */
    public function test_a_pause_is_not_retried_by_is_acceptable(): void
    {
        Queue::fake();
        $this->provider(self::toolCalls(['call_order', 'place_order', ['qty' => 2]]));

        $runId = AI::queue(new ResumeTestPickyTask('buy two'), 'openai');
        $this->work(ProcessAiPayload::class, 0);
        (new PostprocessAiResult($runId, ResumeTestPickyTask::class, ['buy two', true]))->handle();

        $this->assertSame(1, AiRun::count());
        $this->assertTrue(ResumeTestTask::$completed->paused());
    }

    private function work(string $jobClass, int $index): void
    {
        Queue::pushedJobs()[$jobClass][$index]['job']->handle(app(AiManager::class));
    }

    public function test_pending_tool_calls_carry_the_full_call(): void
    {
        $paused = $this->pause();
        $response = new AiResponse(true, toolCalls: $paused->response['tool_calls'], pendingApprovals: $paused->response['pending_approvals']);

        $this->assertSame('fc_call_order', $response->pendingToolCalls()[0]['id']);
        $this->assertSame('call_order', $response->pendingToolCalls()[0]['result_id']);
    }
}

class ResumeTestTask extends AiTask
{
    public function __construct(private string $prompt, private bool $withOrderTool = true) {}

    public function modality(): string { return 'text'; }

    public function toPayload(): AiPayload
    {
        return new AiPayload('text', [new UserMessage($this->prompt)]);
    }

    public function tools(): array
    {
        return array_filter([new ResumeTestLookupTool, $this->withOrderTool ? new ResumeTestOrderTool : null]);
    }

    public function serializeForQueue(): array { return [$this->prompt, $this->withOrderTool]; }

    public static ?AiResponse $completed = null;

    public function onCompleted(AiResponse|array $result, bool $attemptsExhausted): void
    {
        self::$completed = $result;
    }
}

class ResumeTestPickyTask extends ResumeTestTask
{
    public function isAcceptable(AiResponse|array $result): bool { return filled($result->content); }
    public function maxRetries(): int { return 2; }
}

class ResumeTestUserTask extends ResumeTestTask
{
    use ActsAsDispatchingUser;
}

class ResumeTestUserProvider implements UserProvider
{
    public function retrieveById($identifier): ?Authenticatable { return new GenericUser(['id' => $identifier]); }
    public function retrieveByToken($identifier, $token): ?Authenticatable { return null; }
    public function updateRememberToken(Authenticatable $user, $token): void {}
    public function retrieveByCredentials(array $credentials): ?Authenticatable { return null; }
    public function validateCredentials(Authenticatable $user, array $credentials): bool { return false; }
    public function rehashPasswordIfRequired(Authenticatable $user, array $credentials, bool $force = false): void {}
}

class ResumeTestOtherTask extends AiTask
{
    public function modality(): string { return 'text'; }
    public function toPayload(): AiPayload { return new AiPayload('text', ['hi']); }
}

class ResumeTestOrderTool implements Tool, Approvable
{
    use InteractsWithApprovals;

    public static array $placed = [];

    public static array $placedBy = [];

    public function name(): string { return 'place_order'; }
    public function description(): string { return 'Places an order.'; }
    public function schema(JsonSchema $schema): array { return ['qty' => $schema->integer()]; }

    public function handle(Request $request): string
    {
        self::$placed[] = $request->all();
        self::$placedBy[] = Auth::id();

        return 'placed';
    }

    protected function needsApproval(Request $request): Approval|bool
    {
        return Approval::required('Places a real order.');
    }
}

class ResumeTestLookupTool implements Tool
{
    public static int $calls = 0;

    public function name(): string { return 'lookup'; }
    public function description(): string { return 'Looks up stock.'; }
    public function schema(JsonSchema $schema): array { return []; }

    public function handle(Request $request): string
    {
        self::$calls++;

        return 'in stock';
    }
}
