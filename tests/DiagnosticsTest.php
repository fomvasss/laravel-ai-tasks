<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\Tests;

use Fomvasss\AiTasks\AiServiceProvider;
use Fomvasss\AiTasks\Support\Diagnostics;
use Orchestra\Testbench\TestCase;

class DiagnosticsTest extends TestCase
{
    private ?string $published = null;

    protected function getPackageProviders($app): array
    {
        return [AiServiceProvider::class];
    }

    protected function tearDown(): void
    {
        if ($this->published) {
            @unlink($this->published);
        }

        parent::tearDown();
    }

    private function publish(callable $edit): string
    {
        $config = require __DIR__.'/../config/ai-tasks.php';
        $edit($config);

        $this->published = sys_get_temp_dir().'/ai-tasks-'.uniqid().'.php';
        file_put_contents($this->published, '<?php return '.var_export($config, true).';');

        return $this->published;
    }

    public function test_config_drift_is_null_when_the_config_is_not_published(): void
    {
        $this->assertNull(Diagnostics::configDrift('/nonexistent/ai-tasks.php'));
    }

    public function test_an_unchanged_published_config_has_no_drift(): void
    {
        $this->assertSame(['missing' => [], 'unknown' => []], Diagnostics::configDrift($this->publish(fn () => null)));
    }

    public function test_config_drift_reports_missing_and_obsolete_keys(): void
    {
        $path = $this->publish(function (array &$config) {
            unset($config['dashboard']['stuck_after_minutes'], $config['table']);
            $config['dashboard']['signature_header'] = 'X-Old';
        });

        $drift = Diagnostics::configDrift($path);

        $this->assertSame(['table', 'dashboard.stuck_after_minutes'], $drift['missing']);
        $this->assertSame(['dashboard.signature_header'], $drift['unknown']);
    }

    public function test_the_apps_own_lists_are_not_compared(): void
    {
        $path = $this->publish(function (array &$config) {
            $config['drivers'] = ['openai' => ['model' => 'gpt-x']];
            $config['routing'] = ['chat' => ['openai']];
            $config['budgets'] = ['default' => ['monthly_usd' => 10]];
        });

        $this->assertSame(['missing' => [], 'unknown' => []], Diagnostics::configDrift($path));
    }

    public function test_ai_queue_on_the_default_redis_connection_is_too_short(): void
    {
        config([
            'queue.default' => 'redis',
            'queue.connections.redis' => ['driver' => 'redis', 'retry_after' => 90],
        ]);

        $queue = Diagnostics::aiQueue();

        $this->assertSame('redis', $queue['connection']);
        $this->assertSame(90, $queue['retry_after']);
        $this->assertTrue($queue['too_short']);
    }

    public function test_the_horizon_supervisor_connection_of_the_ai_queue_is_used(): void
    {
        config([
            'queue.default' => 'redis',
            'queue.connections.redis' => ['driver' => 'redis', 'retry_after' => 90],
            'queue.connections.redis-ai' => ['driver' => 'redis', 'retry_after' => 360],
            'horizon.defaults' => [
                'supervisor-default' => ['connection' => 'redis', 'queue' => ['default', 'ai-post']],
                'supervisor-ai' => ['connection' => 'redis-ai', 'queue' => ['ai']],
            ],
            'horizon.environments' => ['*' => ['supervisor-default' => [], 'supervisor-ai' => ['maxProcesses' => 6]]],
        ]);

        $queue = Diagnostics::aiQueue();

        $this->assertSame('redis-ai', $queue['connection']);
        $this->assertFalse($queue['too_short']);
    }

    public function test_a_sync_queue_is_never_too_short(): void
    {
        config(['queue.default' => 'sync', 'queue.connections.sync' => ['driver' => 'sync']]);

        $this->assertFalse(Diagnostics::aiQueue()['too_short']);
    }

    public function test_about_shows_the_config_and_queue_rows(): void
    {
        config([
            'queue.default' => 'redis',
            'queue.connections.redis' => ['driver' => 'redis', 'retry_after' => 90],
        ]);

        $this->artisan('about', ['--only' => 'ai_tasks'])
            ->expectsOutputToContain('ai on redis, retry_after 90')
            ->assertSuccessful();
    }
}
