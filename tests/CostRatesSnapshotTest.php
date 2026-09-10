<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\Tests;

use Fomvasss\AiTasks\AiServiceProvider;
use Fomvasss\AiTasks\Drivers\LaravelAiDriver;
use Fomvasss\AiTasks\DTO\AiResponse;
use Fomvasss\AiTasks\Models\AiRun;
use Orchestra\Testbench\TestCase;

/**
 * `cost` рахується з конфіга в момент прогону, тож поруч має лежати знімок ставок:
 * інакше рядок, записаний до зміни тарифів провайдера чи моделі, заднім числом уже
 * не пояснити — у конфізі вже інші числа.
 */
class CostRatesSnapshotTest extends TestCase
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

    private function withCost(array $usage, array $cfg): array
    {
        $driver = new LaravelAiDriver('openai', $cfg);
        $method = new \ReflectionMethod($driver, 'withCost');
        $method->setAccessible(true);

        return $method->invoke($driver, $usage, 'openai');
    }

    public function test_driver_records_rates_next_to_cost(): void
    {
        $usage = $this->withCost(
            ['model' => 'gpt-mini', 'tokens_in' => 1_000_000, 'tokens_out' => 1_000_000],
            ['price' => ['in' => 0.20, 'out' => 1.20], 'prices' => ['gpt-mini' => ['in' => 0.15, 'out' => 0.60]]],
        );

        $this->assertEquals(0.75, $usage['cost']);
        $this->assertSame('model:gpt-mini', $usage['cost_rates']['source']);
        $this->assertSame(0.15, $usage['cost_rates']['in']);
    }

    /** Немає ставок — немає ні вартості, ні знімка: порожній об'єкт у колонці лише збивав би. */
    public function test_no_rates_means_no_snapshot(): void
    {
        $usage = $this->withCost(['model' => 'gpt-mini', 'tokens_in' => 100], []);

        $this->assertNull($usage['cost']);
        $this->assertArrayNotHasKey('cost_rates', $usage);
    }

    public function test_finished_run_persists_the_snapshot(): void
    {
        $run = AiRun::create([
            'tenant_id' => 't1',
            'task'      => 'demo',
            'driver'    => 'openai',
            'modality'  => 'text',
            'status'    => 'running',
        ]);

        $run->finish(new AiResponse(true, 'ok', [
            'model'      => 'gpt-mini',
            'tokens_in'  => 1_000,
            'tokens_out' => 200,
            'cost'       => 0.00027,
            'cost_rates' => ['model' => 'gpt-mini', 'source' => 'model:gpt-mini', 'in' => 0.15, 'out' => 0.60],
        ]));

        $fresh = $run->fresh();

        $this->assertSame('model:gpt-mini', $fresh->cost_rates['source']);
        $this->assertSame(0.15, $fresh->cost_rates['in']);
    }

    public function test_failed_run_keeps_the_snapshot_too(): void
    {
        $run = AiRun::create([
            'tenant_id' => 't1',
            'task'      => 'demo',
            'driver'    => 'openai',
            'modality'  => 'text',
            'status'    => 'running',
        ]);

        $run->fail('budget exceeded', [
            'model'      => 'gpt-mini',
            'tokens_in'  => 1_000,
            'cost'       => 0.00015,
            'cost_rates' => ['model' => 'gpt-mini', 'source' => 'driver', 'in' => 0.15],
        ]);

        $this->assertSame('driver', $run->fresh()->cost_rates['source']);
    }
}
