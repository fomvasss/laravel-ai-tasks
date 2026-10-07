<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\Tests;

use Fomvasss\AiTasks\AiServiceProvider;
use Fomvasss\AiTasks\DTO\AiPayload;
use Fomvasss\AiTasks\Facades\AI;
use Fomvasss\AiTasks\Models\AiRun;
use Fomvasss\AiTasks\Tasks\AiTask;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;

/** ai_runs.user_id — хто запустив прогін: для аудиту, витрат по користувачах і фільтра в дашборді */
class RunUserIdTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [AiServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->artisan('migrate');
        config(['ai-tasks.drivers.null' => []]);
    }

    private function task(?string $userId = null): AiTask
    {
        return new class($userId) extends AiTask {
            public function __construct(private ?string $explicitUser) {}
            public function modality(): string { return 'text'; }
            public function toPayload(): AiPayload { return new AiPayload('text', ['hi']); }
            public function serializeForQueue(): array { return [$this->explicitUser]; }
            protected function userId(): ?string { return $this->explicitUser; }
        };
    }

    public function test_send_records_the_authenticated_user(): void
    {
        Auth::setUser(new GenericUser(['id' => 42]));

        AI::send($this->task(), 'null');

        $this->assertSame('42', AiRun::sole()->user_id);
    }

    /** Користувача беремо в момент dispatch — у воркері auth уже немає */
    public function test_queue_records_the_user_at_dispatch(): void
    {
        Queue::fake();
        Auth::setUser(new GenericUser(['id' => 7]));

        AI::queue($this->task(), 'null');

        $this->assertSame('7', AiRun::sole()->user_id);
    }

    public function test_run_without_a_user_records_null(): void
    {
        AI::send($this->task(), 'null');

        $this->assertNull(AiRun::sole()->user_id);
    }

    public function test_task_can_name_the_user_explicitly(): void
    {
        Auth::setUser(new GenericUser(['id' => 1]));

        AI::send($this->task('01JABCUSER'), 'null');

        $this->assertSame('01JABCUSER', AiRun::sole()->user_id);
    }

    /** Міграція публікується — між `composer update` і `migrate` прогін має записатись і без колонки */
    public function test_run_is_stored_when_the_column_is_not_migrated_yet(): void
    {
        Schema::table('ai_runs', function ($t) {
            $t->dropIndex(['user_id']);
            $t->dropColumn('user_id');
        });
        AiRun::forgetSchemaCache();
        Auth::setUser(new GenericUser(['id' => 42]));

        AI::send($this->task(), 'null');

        $this->assertSame('ok', AiRun::sole()->status);
        $this->get('/ai-tasks?user=42')->assertOk();

        Schema::table('ai_runs', fn ($t) => $t->string('user_id')->nullable()->index());
        AiRun::forgetSchemaCache();
    }

    /** runId є й тоді, коли postprocess() повернув масив */
    public function test_response_carries_the_run_id_even_for_an_array_result(): void
    {
        $task = new class extends AiTask {
            public function modality(): string { return 'text'; }
            public function toPayload(): AiPayload { return new AiPayload('text', ['hi']); }
            public function postprocess(\Fomvasss\AiTasks\DTO\AiResponse $response): array { return ['ok' => true]; }
        };

        $response = AI::send($task, 'null');

        $this->assertSame(AiRun::sole()->id, $response->runId);
    }

    public function test_dashboard_filters_by_user(): void
    {
        Auth::setUser(new GenericUser(['id' => 42]));
        AI::send($this->task()->setName('mine'), 'null');
        Auth::setUser(new GenericUser(['id' => 43]));
        AI::send($this->task()->setName('theirs'), 'null');
        Auth::forgetUser();

        $runs = $this->getJson('/ai-tasks/data?user=42')->assertOk()->json('runs');

        $this->assertSame(['mine'], array_column($runs, 'task'));
        $this->assertSame('42', $runs[0]['user_id']);
    }
}
