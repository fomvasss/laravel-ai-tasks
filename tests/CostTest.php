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

    public function test_small_token_count(): void
    {
        $usage = ['tokens_in' => 500, 'tokens_out' => 200];
        $cost  = Cost::calc('openai', $usage, $this->cfg);

        $this->assertIsFloat($cost);
        $this->assertGreaterThan(0, $cost);
    }
}
