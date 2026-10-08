<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\Tests;

use Fomvasss\AiTasks\AiServiceProvider;
use Fomvasss\AiTasks\Support\TenantResolver;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Auth;
use Orchestra\Testbench\TestCase;

/**
 * Заголовок шле клієнт: довіряти йому за замовчуванням — означало б дати будь-кому
 * списати витрати на чужий tenant або обійти свій бюджет.
 */
class TenantResolverTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [AiServiceProvider::class];
    }

    private function resolveWith(array $headers): string
    {
        foreach ($headers as $name => $value) {
            request()->headers->set($name, $value);
        }

        return app(TenantResolver::class)->id();
    }

    public function test_the_header_is_ignored_by_default(): void
    {
        Auth::setUser(new GenericUser(['id' => 7]));

        $this->assertSame('7', $this->resolveWith(['X-Tenant-Id' => 'other']));
    }

    public function test_without_user_the_header_falls_back_to_the_default_tenant(): void
    {
        $this->assertSame('default', $this->resolveWith(['X-Tenant-Id' => 'other']));
    }

    public function test_the_header_is_trusted_when_configured(): void
    {
        config(['ai-tasks.tenant_header' => 'X-Tenant-Id']);
        Auth::setUser(new GenericUser(['id' => 7]));

        $this->assertSame('acme', $this->resolveWith(['X-Tenant-Id' => 'acme']));
    }

    public function test_a_configured_header_name_is_used(): void
    {
        config(['ai-tasks.tenant_header' => 'X-Org']);

        $this->assertSame('acme', $this->resolveWith(['X-Tenant-Id' => 'other', 'X-Org' => 'acme']));
    }

    public function test_a_configured_header_that_is_absent_falls_back_to_the_user(): void
    {
        config(['ai-tasks.tenant_header' => 'X-Tenant-Id']);
        Auth::setUser(new GenericUser(['id' => 7]));

        $this->assertSame('7', $this->resolveWith([]));
    }
}
