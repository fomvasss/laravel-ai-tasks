<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\Tests;

use Fomvasss\AiTasks\Drivers\LaravelAiDriver;
use Fomvasss\AiTasks\Support\Cost;
use Laravel\Ai\Responses\Data\TextUsage;
use PHPUnit\Framework\TestCase;

/**
 * tokens_in має скрізь означати одне й те саме — НЕкешовані вхідні токени.
 *
 * З laravel/ai 1.0 TextUsage::inputTokens у всіх gateway'їв повний (разом із кешем на
 * читання й запис), тож tokens_in = uncachedInputTokens() незалежно від провайдера.
 * Без цього кеш рахувався б двічі в Cost::calc(), що складає їх як незалежні доданки.
 */
class UsageNormalizationTest extends TestCase
{
    private function mapUsage(?TextUsage $usage, string $provider = 'openai'): array
    {
        $driver = new LaravelAiDriver($provider, []);
        $method = new \ReflectionMethod($driver, 'mapUsage');
        $method->setAccessible(true);

        return $method->invoke($driver, $usage, $provider, 'test-model');
    }

    public function test_cache_read_is_excluded_from_tokens_in_for_every_driver(): void
    {
        foreach (['openai', 'anthropic', 'gemini', 'groq', 'openrouter', 'deepseek', 'mistral'] as $provider) {
            // 10 000 усього на вході, з них 8 000 прийшло з кешу → повною ціною платимо за 2 000
            $mapped = $this->mapUsage(new TextUsage(10_000, 500, cacheReadInputTokens: 8_000), $provider);

            $this->assertSame(2_000, $mapped['tokens_in'], "[{$provider}] tokens_in має лишитись без кешованої частини");
            $this->assertSame(8_000, $mapped['cache_read_tokens'], "[{$provider}] cache_read має лишитись як є");
            $this->assertSame(500, $mapped['tokens_out'], "[{$provider}] tokens_out не чіпаємо");
        }
    }

    public function test_cache_write_is_excluded_too(): void
    {
        $mapped = $this->mapUsage(new TextUsage(10_000, 500, cacheReadInputTokens: 6_000, cacheWriteInputTokens: 1_500));

        $this->assertSame(2_500, $mapped['tokens_in']);
        $this->assertSame(6_000, $mapped['cache_read_tokens']);
        $this->assertSame(1_500, $mapped['cache_write_tokens']);
    }

    /** З 1.0 outputTokens уже включає reasoning — окремо не додаємо. */
    public function test_reasoning_is_part_of_tokens_out(): void
    {
        $mapped = $this->mapUsage(new TextUsage(1_000, 700, reasoningTokens: 400));

        $this->assertSame(700, $mapped['tokens_out']);
    }

    /** Провайдер не звітує кеш — лічильники null, tokens_in = весь вхід. */
    public function test_unreported_cache_is_a_noop(): void
    {
        $mapped = $this->mapUsage(new TextUsage(1_500, 300));

        $this->assertSame(1_500, $mapped['tokens_in']);
        $this->assertNull($mapped['cache_read_tokens']);
        $this->assertNull($mapped['cache_write_tokens']);
    }

    /** Кеш не може перевищити весь промпт, але від битих даних провайдера мінус не піде в БД. */
    public function test_cache_larger_than_input_clamps_to_zero(): void
    {
        $mapped = $this->mapUsage(new TextUsage(100, 50, cacheReadInputTokens: 999));

        $this->assertNull($mapped['tokens_in'], '0 нормалізується в null, від\'ємного значення бути не має');
    }

    public function test_missing_usage_keeps_driver_and_model_only(): void
    {
        $this->assertSame(['driver' => 'openai', 'model' => 'test-model'], $this->mapUsage(null));
    }

    /** Головний наслідок: кешовані токени не оплачуються двічі. */
    public function test_cost_does_not_double_count_cached_tokens(): void
    {
        $cfg = ['price' => ['in' => 0.22, 'out' => 0.66, 'cache_read' => 0.007]];

        $mapped = $this->mapUsage(new TextUsage(10_000, 500, cacheReadInputTokens: 8_000), 'groq');

        // 2 000 * 0.22/1M + 8 000 * 0.007/1M + 500 * 0.66/1M
        $this->assertEqualsWithDelta(0.000826, Cost::calc('groq', $mapped, $cfg), 0.0000001);
    }
}
