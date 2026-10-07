<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\Tests;

use Fomvasss\AiTasks\AiServiceProvider;
use Fomvasss\AiTasks\Contracts\AiDriver;
use Fomvasss\AiTasks\Core\AI;
use Fomvasss\AiTasks\Core\AiManager;
use Fomvasss\AiTasks\Core\Router;
use Fomvasss\AiTasks\DTO\AiContext;
use Fomvasss\AiTasks\DTO\AiPayload;
use Fomvasss\AiTasks\DTO\AiResponse;
use Fomvasss\AiTasks\Facades\AI as AIFacade;
use Fomvasss\AiTasks\Jobs\PostprocessAiResult;
use Fomvasss\AiTasks\Jobs\ProcessAiPayload;
use Fomvasss\AiTasks\Models\AiRun;
use Fomvasss\AiTasks\Support\RunRetrier;
use Fomvasss\AiTasks\Tasks\AiTask;
use Fomvasss\AiTasks\Traits\ActsAsDispatchingUser;
use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Queue;
use Orchestra\Testbench\TestCase;

/**
 * Код таска в воркері (tool-цикл, хуки, Retry з дашборду) має бачити користувача й локаль
 * моменту dispatch, а після job — нічого: воркер обробляє наступні job'и в тому самому процесі.
 */
class ExecutionContextTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [AiServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('auth.providers.users', ['driver' => 'context-test']);
        $app['config']->set('ai.providers.spy.key', 'test');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->artisan('migrate');

        Auth::provider('context-test', fn () => new ContextTestUserProvider([42 => 'Ann', 7 => 'Bob', 99 => 'Admin']));

        ContextTestProbe::$seen = [];

        $manager = new class(app()) extends AiManager {
            protected function createDriver($name): AiDriver { return new ContextTestSpyDriver; }
        };
        app()->instance(AiManager::class, $manager);
        app()->instance(AI::class, new AI($manager, app(Router::class)));
    }

    /** Простий воркер: job лежить у фейковій черзі, «запит» закінчився — ні користувача, ні локалі */
    private function runQueuedJobAsWorker(): void
    {
        Auth::forgetUser();
        app()->setLocale('en');

        Queue::pushedJobs()[ProcessAiPayload::class][0]['job']->handle(app(AiManager::class));
    }

    public function test_trait_captures_the_guard_user_and_locale(): void
    {
        Auth::setUser(new GenericUser(['id' => 42]));
        app()->setLocale('uk');

        $this->assertSame(['guard' => 'web', 'user_id' => 42, 'locale' => 'uk'], (new ContextTestTask)->executionContext());
    }

    public function test_queued_run_executes_as_the_dispatching_user_and_restores_the_worker(): void
    {
        Queue::fake();
        Auth::setUser(new GenericUser(['id' => 42, 'name' => 'Ann']));
        app()->setLocale('uk');

        $runId = AIFacade::queue(new ContextTestTask, 'spy');

        $this->runQueuedJobAsWorker();

        $this->assertSame(['user' => 42, 'locale' => 'uk'], ContextTestProbe::$seen['shouldRun']);
        $this->assertSame(['user' => 42, 'locale' => 'uk'], ContextTestProbe::$seen['driver']);
        $this->assertNull(Auth::user());
        $this->assertSame('en', app()->getLocale());
        $this->assertSame('ok', AiRun::find($runId)->status);
    }

    /** Контекст зберігається навіть без store_request — інакше Retry і resume не мали б його звідки взяти */
    public function test_context_is_stored_with_the_run_regardless_of_store_request(): void
    {
        Queue::fake();
        config(['ai-tasks.store_request' => false]);
        Auth::setUser(new GenericUser(['id' => 42]));

        $runId = AIFacade::queue(new ContextTestTask, 'spy');

        $this->assertSame(42, AiRun::find($runId)->executionContext()['user_id']);
    }

    public function test_worker_is_restored_when_the_provider_call_throws(): void
    {
        Queue::fake();
        Auth::setUser(new GenericUser(['id' => 42]));
        ContextTestSpyDriver::$throw = true;

        AIFacade::queue(new ContextTestTask, 'spy');

        try {
            $this->runQueuedJobAsWorker();
            $this->fail('the provider error must reach the queue so the job is retried');
        } catch (\RuntimeException) {
        } finally {
            ContextTestSpyDriver::$throw = false;
        }

        $this->assertSame(42, ContextTestProbe::$seen['driver']['user']);
        $this->assertNull(Auth::user());
    }

    public function test_postprocess_runs_as_the_dispatching_user(): void
    {
        Queue::fake();
        Auth::setUser(new GenericUser(['id' => 42]));
        app()->setLocale('uk');

        $runId = AIFacade::queue(new ContextTestTask, 'spy');
        $this->runQueuedJobAsWorker();

        Auth::forgetUser();
        app()->setLocale('en');
        (new PostprocessAiResult($runId, ContextTestTask::class))->handle();

        $this->assertSame(['user' => 42, 'locale' => 'uk'], ContextTestProbe::$seen['postprocess']);
        $this->assertSame(['user' => 42, 'locale' => 'uk'], ContextTestProbe::$seen['onCompleted']);
        $this->assertNull(Auth::user());
    }

    /** Retry з дашборду: tools() і тенант мають зібратись для автора запуску, а не для адміна */
    public function test_retry_rebuilds_the_task_as_the_original_user_not_the_admin(): void
    {
        Queue::fake();
        config(['ai-tasks.store_request' => true]);
        Auth::setUser(new GenericUser(['id' => 42]));
        $runId = AIFacade::queue(new ContextTestTask, 'spy');
        AiRun::find($runId)->update(['status' => 'dead']);
        ContextTestProbe::$seen = [];

        $admin = new GenericUser(['id' => 99]);
        Auth::setUser($admin);

        $this->assertTrue(RunRetrier::retry(AiRun::find($runId)));

        $this->assertSame(42, ContextTestProbe::$seen['tools']['user']);
        $this->assertSame($admin, Auth::user());
    }

    /** isAcceptable() відхилив результат — повторний прогін несе той самий контекст */
    public function test_retry_after_rejected_result_keeps_the_context(): void
    {
        Queue::fake();
        Auth::setUser(new GenericUser(['id' => 42]));

        $runId = AIFacade::queue(new ContextTestRejectingTask, 'spy');
        $this->runQueuedJobAsWorker();
        (new PostprocessAiResult($runId, ContextTestRejectingTask::class))->handle();

        $retry = AiRun::where('id', '!=', $runId)->sole();

        $this->assertSame(42, $retry->executionContext()['user_id']);
        $this->assertSame(42, ContextTestProbe::$seen['tools']['user']);
    }

    /** Приклад із доки: трейт розширено власним заголовком — у воркері видно обидва, після — нічого */
    public function test_task_can_extend_the_trait_context(): void
    {
        Queue::fake();
        Auth::setUser(new GenericUser(['id' => 42]));
        request()->headers->set('X-Country', 'PL');

        AIFacade::queue(new ContextTestCountryTask, 'spy');
        request()->headers->remove('X-Country');
        $this->runQueuedJobAsWorker();

        $this->assertSame(['user' => 42, 'country' => 'PL'], ContextTestProbe::$seen['country']);
        $this->assertNull(request()->headers->get('X-Country'));
        $this->assertNull(Auth::user());
    }

    /**
     * Таск від імені користувача з job'а, де ніхто не залогінений (crm2): tools(), провайдер і хуки
     * бачать його з самого початку, а після виклику — знову нікого.
     */
    public function test_acting_user_applies_to_tools_and_the_call_without_anyone_logged_in(): void
    {
        Auth::forgetUser();

        $response = AIFacade::send(new ContextTestOnBehalfTask(7), 'spy');

        $this->assertSame(7, ContextTestProbe::$seen['tools']['user']);
        $this->assertSame(7, ContextTestProbe::$seen['driver']['user']);
        $this->assertSame('7', AiRun::find($response->runId)->user_id);
        $this->assertNull(Auth::user());
    }

    /** Адмін ставить у чергу від імені користувача: tools() збирається для користувача, адмін лишається */
    public function test_acting_user_on_queue_dispatched_by_someone_else(): void
    {
        Queue::fake();
        Auth::setUser($admin = new GenericUser(['id' => 99]));

        $runId = AIFacade::queue(new ContextTestOnBehalfTask(7), 'spy');

        $this->assertSame(7, ContextTestProbe::$seen['tools']['user']);
        $this->assertSame($admin, Auth::user());

        $this->runQueuedJobAsWorker();

        $this->assertSame(7, ContextTestProbe::$seen['driver']['user']);
        $this->assertSame('7', AiRun::find($runId)->user_id);
    }

    public function test_deleted_user_leaves_nobody_authenticated(): void
    {
        Queue::fake();
        Auth::setUser(new GenericUser(['id' => 5]));

        AIFacade::queue(new ContextTestTask, 'spy');
        $this->runQueuedJobAsWorker();

        $this->assertNull(ContextTestProbe::$seen['driver']['user']);
    }

    /** sync send() у запиті: той самий користувач лишається тим самим екземпляром (стан запиту, токен Sanctum) */
    public function test_sync_send_keeps_the_request_user_instance(): void
    {
        $user = new GenericUser(['id' => 42, 'token' => 'request-only']);
        Auth::setUser($user);

        AIFacade::send(new ContextTestTask, 'spy');

        $this->assertSame($user, ContextTestProbe::$seen['driverUser']);
        $this->assertSame($user, Auth::user());
    }

    public function test_task_without_the_trait_carries_nothing(): void
    {
        Queue::fake();
        Auth::setUser(new GenericUser(['id' => 42]));

        $runId = AIFacade::queue(new ContextTestPlainTask, 'spy');
        $this->runQueuedJobAsWorker();

        $this->assertSame([], AiRun::find($runId)->executionContext());
        $this->assertNull(ContextTestProbe::$seen['driver']['user']);
    }
}

final class ContextTestProbe
{
    public static array $seen = [];

    public static function record(string $where): void
    {
        self::$seen[$where] = ['user' => Auth::id(), 'locale' => app()->getLocale()];
    }
}

class ContextTestTask extends AiTask
{
    use ActsAsDispatchingUser;

    public function modality(): string { return 'text'; }
    public function toPayload(): AiPayload { return new AiPayload('text', ['hi']); }
    public function shouldRun(): bool { ContextTestProbe::record('shouldRun'); return true; }
    public function tools(): array { ContextTestProbe::record('tools'); return []; }
    public function postprocess(AiResponse $response): AiResponse|array { ContextTestProbe::record('postprocess'); return $response; }
    public function onCompleted(AiResponse|array $result, bool $attemptsExhausted): void { ContextTestProbe::record('onCompleted'); }
}

class ContextTestRejectingTask extends ContextTestTask
{
    public function isAcceptable(AiResponse|array $result): bool { return false; }
    public function maxRetries(): int { return 1; }
}

class ContextTestCountryTask extends AiTask
{
    use ActsAsDispatchingUser {
        executionContext as traitExecutionContext;
        withExecutionContext as traitWithExecutionContext;
    }

    public function modality(): string { return 'text'; }
    public function toPayload(): AiPayload { return new AiPayload('text', ['hi']); }

    public function shouldRun(): bool
    {
        ContextTestProbe::$seen['country'] = ['user' => Auth::id(), 'country' => request()->header('X-Country')];

        return true;
    }

    public function executionContext(): array
    {
        return [...$this->traitExecutionContext(), 'country' => request()->header('X-Country')];
    }

    public static function withExecutionContext(array $context, \Closure $call): mixed
    {
        $previous = request()->headers->get('X-Country');
        request()->headers->set('X-Country', $context['country'] ?? null);

        try {
            return static::traitWithExecutionContext($context, $call);
        } finally {
            request()->headers->set('X-Country', $previous);
        }
    }
}

class ContextTestOnBehalfTask extends ContextTestTask
{
    public function __construct(private int $userId = 0) {}

    public function serializeForQueue(): array { return [$this->userId]; }

    protected function actingUser(): ?Authenticatable
    {
        return new GenericUser(['id' => $this->userId]);
    }
}

class ContextTestPlainTask extends AiTask
{
    public function modality(): string { return 'text'; }
    public function toPayload(): AiPayload { return new AiPayload('text', ['hi']); }
}

class ContextTestSpyDriver implements AiDriver
{
    public static bool $throw = false;

    public function supports(string $modality): bool { return true; }

    public function send(AiPayload $p, AiContext $c): AiResponse
    {
        ContextTestProbe::record('driver');
        ContextTestProbe::$seen['driverUser'] = Auth::user();

        if (self::$throw) {
            throw new \RuntimeException('provider down');
        }

        return new AiResponse(true, 'done');
    }

    public function stream(AiPayload $p, AiContext $c, callable $onChunk): AiResponse
    {
        return $this->send($p, $c);
    }
}

class ContextTestUserProvider implements UserProvider
{
    public function __construct(private array $users) {}

    public function retrieveById($identifier): ?Authenticatable
    {
        return isset($this->users[$identifier]) ? new GenericUser(['id' => $identifier, 'name' => $this->users[$identifier]]) : null;
    }

    public function retrieveByToken($identifier, $token): ?Authenticatable { return null; }
    public function updateRememberToken(Authenticatable $user, $token): void {}
    public function retrieveByCredentials(array $credentials): ?Authenticatable { return null; }
    public function validateCredentials(Authenticatable $user, array $credentials): bool { return false; }
    public function rehashPasswordIfRequired(Authenticatable $user, array $credentials, bool $force = false): void {}
}
