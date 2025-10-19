<?php

namespace Fomvasss\AiTasks\Tests;

use Fomvasss\AiTasks\AiServiceProvider;
use Orchestra\Testbench\TestCase;

class PackageBootTest extends TestCase
{
    protected function getPackageProviders($app)
    {
        return [AiServiceProvider::class];
    }

    public function test_config_loaded()
    {
        $this->assertNotEmpty(config('ai.default'));
    }
}
