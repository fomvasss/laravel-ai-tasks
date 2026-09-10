<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\Tests;

use Fomvasss\AiTasks\Support\Cost;
use PHPUnit\Framework\TestCase;

class CostTest extends TestCase
{
    private array $cfg = [
        'price' => [
            'in'          => 3.00,
            'out'         => 15.00,
            'cache_write' => 3.75,
            'cache_read'  => 0.30,
        ],
    ];

    public function test_basic_cost_calculation(): void
    {
        $usage = ['tokens_in' => 1_000_000, 'tokens_out' => 1_000_000];
        $cost  = Cost::calc('openai', $usage, $this->cfg);

        $this->assertEquals(18.0, $cost);
    }

    public function test_cost_with_cache_tokens(): void
    {
        $usage = [
            'tokens_in'          => 0,
            'tokens_out'         => 0,
            'cache_write_tokens' => 1_000_000,
            'cache_read_tokens'  => 1_000_000,
        ];
        $cost = Cost::calc('anthropic', $usage, $this->cfg);

        $this->assertEquals(4.05, round((float) $cost, 2));
    }

    public function test_returns_null_when_no_price_config(): void
    {
        $cost = Cost::calc('ollama', ['tokens_in' => 100, 'tokens_out' => 50], []);

        $this->assertNull($cost);
    }

    public function test_per_model_rates_win_over_driver_price(): void
    {
        $cfg = $this->cfg + ['prices' => ['gpt-mini' => ['in' => 0.15, 'out' => 0.60]]];

        $cost = Cost::calc('openai', ['model' => 'gpt-mini', 'tokens_in' => 1_000_000, 'tokens_out' => 1_000_000], $cfg);

        $this->assertEquals(0.75, $cost);
    }

    /** Модель, якої немає в prices, рахується спільними ставками драйвера — як і до появи prices. */
    public function test_unlisted_model_falls_back_to_driver_price(): void
    {
        $cfg = $this->cfg + ['prices' => ['gpt-mini' => ['in' => 0.15, 'out' => 0.60]]];

        $cost = Cost::calc('openai', ['model' => 'other-model', 'tokens_in' => 1_000_000, 'tokens_out' => 1_000_000], $cfg);

        $this->assertEquals(18.0, $cost);
    }

    /** Моделі через шлюз приходять з префіксом, а в конфізі пишуть голе ім'я. */
    public function test_gateway_prefixed_model_matches_bare_key(): void
    {
        $cfg = ['prices' => ['claude-sonnet-5' => ['in' => 3.0, 'out' => 15.0]]];

        $rates = Cost::ratesFor($cfg, 'anthropic/claude-sonnet-5');

        $this->assertSame('model:claude-sonnet-5', $rates['source']);
        $this->assertSame(3.0, $rates['in']);
    }

    public function test_rates_snapshot_records_model_and_source(): void
    {
        $rates = Cost::ratesFor($this->cfg, 'gpt-5.6-luna');

        $this->assertSame([
            'model'       => 'gpt-5.6-luna',
            'source'      => 'driver',
            'in'          => 3.00,
            'out'         => 15.00,
            'cache_read'  => 0.30,
            'cache_write' => 3.75,
        ], $rates);
    }

    public function test_rates_are_null_without_any_price_config(): void
    {
        $this->assertNull(Cost::ratesFor([], 'gpt-mini'));
    }

    public function test_char_cost_uses_per_model_rate(): void
    {
        $cfg = [
            'price'  => ['per_char' => 15.0],
            'prices' => ['tts-cheap' => ['per_char' => 5.0]],
        ];

        $this->assertEquals(5.0, Cost::calcByChars('openai', 1_000_000, $cfg, 'tts-cheap'));
        $this->assertEquals(15.0, Cost::calcByChars('openai', 1_000_000, $cfg, 'tts-other'));
    }

    public function test_small_token_count(): void
    {
        $usage = ['tokens_in' => 500, 'tokens_out' => 200];
        $cost  = Cost::calc('openai', $usage, $this->cfg);

        $this->assertIsFloat($cost);
        $this->assertGreaterThan(0, $cost);
    }
}
