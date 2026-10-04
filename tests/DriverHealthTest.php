<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\Tests;

use Fomvasss\AiTasks\AiServiceProvider;
use Fomvasss\AiTasks\DTO\AiPayload;
use Fomvasss\AiTasks\Support\DriverHealth;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response as HttpResponse;
use Laravel\Ai\Exceptions\ProviderConnectionException;
use Orchestra\Testbench\TestCase;

class DriverHealthTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [AiServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('cache.default', 'array');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->artisan('migrate');
    }

    private function payload(?array $override = null): AiPayload
    {
        return new AiPayload('text', providerOverride: $override);
    }

    public function test_unknown_until_anything_is_recorded(): void
    {
        $this->assertSame('unknown', DriverHealth::get('deepseek')['status']);
    }

    public function test_consecutive_failures_degrade_then_mark_down_and_success_resets(): void
    {
        $down = ProviderConnectionException::forProvider('deepseek');

        DriverHealth::recordFailure('deepseek', $this->payload(), $down);
        $this->assertSame('degraded', DriverHealth::get('deepseek')['status']);

        DriverHealth::recordFailure('deepseek', $this->payload(), $down);
        DriverHealth::recordFailure('deepseek', $this->payload(), $down);
        $h = DriverHealth::get('deepseek');
        $this->assertSame('down', $h['status']);
        $this->assertSame(3, $h['failures']);
        $this->assertStringContainsString('deepseek', $h['last_error']);

        DriverHealth::recordSuccess('deepseek', $this->payload());
        $h = DriverHealth::get('deepseek');
        $this->assertSame('ok', $h['status']);
        $this->assertSame(0, $h['failures']);
        $this->assertNotNull($h['last_error_at'], 'остання помилка лишається видимою після відновлення');
    }

    /** 4xx — проблема запиту, а не провайдера */
    public function test_rejected_request_is_not_a_driver_failure(): void
    {
        $rejected = new RequestException(new HttpResponse(new Psr7Response(422)));

        DriverHealth::recordFailure('openai', $this->payload(), $rejected);

        $this->assertSame(0, DriverHealth::get('openai')['failures']);
    }

    /** Свій ключ тенанта — інший акаунт/API, його збої не означають, що лежить спільний драйвер */
    public function test_runs_with_own_key_are_ignored(): void
    {
        $own = $this->payload(['driver' => 'openai', 'key' => 'tenant-key']);

        DriverHealth::recordFailure('openai', $own, ProviderConnectionException::forProvider('openai'));
        DriverHealth::recordSuccess('openai', $own);

        $this->assertSame('unknown', DriverHealth::get('openai')['status']);
    }

    public function test_dashboard_shows_driver_state(): void
    {
        config(['ai.providers.deepseek.key' => 'k', 'ai-tasks.drivers.deepseek' => ['model' => 'deepseek-flash']]);

        foreach ([1, 2, 3] as $i) {
            DriverHealth::recordFailure('deepseek', $this->payload(), ProviderConnectionException::forProvider('deepseek'));
        }

        $this->get('/'.config('ai-tasks.dashboard.path'))
            ->assertOk()
            ->assertSeeInOrder(['deepseek', 'down', '3 in a row', 'Could not connect to AI provider [deepseek]']);
    }
}
