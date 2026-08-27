<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\Tests;

use Fomvasss\AiTasks\Drivers\LaravelAiDriver;
use Fomvasss\AiTasks\Support\Cost;
use Laravel\Ai\Responses\Data\Usage;
use PHPUnit\Framework\TestCase;

/**
 * tokens_in має скрізь означати одне й те саме — НЕкешовані вхідні токени.
 *
 * Частина gateway'їв laravel/ai (groq, openrouter, openai-compatible) віддає
 * Usage::promptTokens включно з кешованими токенами, решта (openai, xai, gemini, azure,
 * deepseek, anthropic, bedrock) — без них. Без нормалізації кеш рахувався б двічі
 * в Cost::calc() і будь-яка тарифікація поверх tokens_in залежала б від провайдера.
 */
class UsageNormalizationTest extends TestCase
{
    private function mapUsage(Usage $usage, string $provider, array $cfg = []): array
    {
        $driver = new LaravelAiDriver($provider, $cfg);
        $method = new \ReflectionMethod($driver, 'mapUsage');
        $method->setAccessible(true);

        return $method->invoke($driver, $usage, $provider, 'test-model');
    }

    public function test_cache_read_is_subtracted_for_inclusive_drivers(): void
    {
        foreach (['groq', 'openrouter', 'openai-compatible', 'openai_compatible'] as $provider) {
            // 10 000 усього на вході, з них 8 000 прийшло з кешу → повною ціною платимо за 2 000
            $mapped = $this->mapUsage(new Usage(10_000, 500, 0, 8_000), $provider);

            $this->assertSame(2_000, $mapped['tokens_in'], "[{$provider}] tokens_in має лишитись без кешованої частини");
            $this->assertSame(8_000, $mapped['cache_read_tokens'], "[{$provider}] cache_read має лишитись як є");
            $this->assertSame(500, $mapped['tokens_out'], "[{$provider}] tokens_out не чіпаємо");
        }
    }

    /** У провайдерів з інклюзивним promptTokens там сидить і cache_write — його теж віднімаємо. */
    public function test_cache_write_is_subtracted_too_for_inclusive_drivers(): void
    {
        $mapped = $this->mapUsage(new Usage(10_000, 500, 1_500, 6_000), 'openrouter');

        $this->assertSame(2_500, $mapped['tokens_in']);
        $this->assertSame(6_000, $mapped['cache_read_tokens']);
        $this->assertSame(1_500, $mapped['cache_write_tokens']);
    }

    /** deepseek виправлений upstream у laravel/ai 0.11 — тут віднімати вже не можна. */
    public function test_cache_read_is_left_alone_for_exclusive_drivers(): void
    {
        foreach (['openai', 'anthropic', 'gemini', 'xai', 'bedrock', 'deepseek'] as $provider) {
            $mapped = $this->mapUsage(new Usage(2_000, 500, 0, 8_000), $provider);

            $this->assertSame(2_000, $mapped['tokens_in'], "[{$provider}] tokens_in уже без кешу — віднімати нічого не можна");
            $this->assertSame(8_000, $mapped['cache_read_tokens'], "[{$provider}] cache_read має лишитись як є");
        }
    }

    /** laravel/ai < 0.11 віддавав інклюзивний promptTokens і для deepseek — лікується конфігом. */
    public function test_legacy_deepseek_can_be_normalized_via_config(): void
    {
        $mapped = $this->mapUsage(
            new Usage(10_000, 500, 0, 8_000),
            'deepseek',
            ['cache_inclusive_prompt_tokens' => true],
        );

        $this->assertSame(2_000, $mapped['tokens_in']);
    }

    public function test_no_cache_read_is_a_noop(): void
    {
        $mapped = $this->mapUsage(new Usage(1_500, 300), 'groq');

        $this->assertSame(1_500, $mapped['tokens_in']);
        $this->assertNull($mapped['cache_read_tokens'], 'нуль нормалізується в null, як і решта лічильників');
    }

    /** Кеш не може перевищити весь промпт, але від битих даних провайдера мінус не піде в БД. */
    public function test_cache_read_larger_than_prompt_tokens_clamps_to_zero(): void
    {
        $mapped = $this->mapUsage(new Usage(100, 50, 0, 999), 'groq');

        $this->assertNull($mapped['tokens_in'], '0 нормалізується в null, від\'ємного значення бути не має');
    }

    public function test_config_can_disable_normalization_for_a_listed_driver(): void
    {
        $mapped = $this->mapUsage(
            new Usage(10_000, 500, 0, 8_000),
            'groq',
            ['cache_inclusive_prompt_tokens' => false],
        );

        $this->assertSame(10_000, $mapped['tokens_in'], 'конфіг має перекривати вбудований список');
    }

    public function test_config_can_enable_normalization_for_an_unlisted_driver(): void
    {
        $mapped = $this->mapUsage(
            new Usage(10_000, 500, 0, 8_000),
            'some-new-provider',
            ['cache_inclusive_prompt_tokens' => true],
        );

        $this->assertSame(2_000, $mapped['tokens_in']);
    }

    /** Головний наслідок: без нормалізації кешовані токени оплачувались би двічі. */
    public function test_cost_no_longer_double_counts_cached_tokens(): void
    {
        $cfg = ['price' => ['in' => 0.22, 'out' => 0.66, 'cache_read' => 0.007]];

        $mapped = $this->mapUsage(new Usage(10_000, 500, 0, 8_000), 'groq', $cfg);
        $cost = Cost::calc('groq', $mapped, $cfg);

        // 2 000 * 0.22/1M + 8 000 * 0.007/1M + 500 * 0.66/1M
        $this->assertEqualsWithDelta(0.000826, $cost, 0.0000001);

        // Для порівняння — скільки б вийшло на сирому (ненормалізованому) promptTokens
        $rawCost = Cost::calc('groq', [
            'tokens_in' => 10_000,
            'tokens_out' => 500,
            'cache_read_tokens' => 8_000,
        ], $cfg);

        $this->assertGreaterThan($cost, $rawCost, 'сира семантика завищує вартість — саме це й лікуємо');
    }
}
