<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\Tests;

use Fomvasss\AiTasks\AiServiceProvider;
use Fomvasss\AiTasks\DTO\AiPayload;
use Fomvasss\AiTasks\Tasks\AiTask;
use Laravel\Ai\Messages\UserMessage;
use Orchestra\Testbench\TestCase;

class ContextOverridesTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [AiServiceProvider::class];
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function makeTask(?string $tenantId = null, ?string $subjectType = null, ?string $subjectId = null): AiTask
    {
        return new class($tenantId, $subjectType, $subjectId) extends AiTask {
            public function __construct(
                private readonly ?string $tenant,
                private readonly ?string $subjType,
                private readonly ?string $subjId,
            ) {}
            public function modality(): string { return 'text'; }
            public function toPayload(): AiPayload { return new AiPayload('text', messages: [new UserMessage('hi')]); }
            protected function tenantId(): ?string { return $this->tenant; }
            protected function subjectType(): ?string { return $this->subjType; }
            protected function subjectId(): ?string { return $this->subjId; }
        };
    }

    // ── Tests ─────────────────────────────────────────────────────────────

    public function test_default_hooks_return_null_and_fall_back_to_tenant_resolver(): void
    {
        config(['ai-tasks.default_tenant' => 'default']);

        $task = new class extends AiTask {
            public function modality(): string { return 'text'; }
            public function toPayload(): AiPayload { return new AiPayload('text', messages: [new UserMessage('hi')]); }
        };

        $ctx = $task->context();

        $this->assertSame('default', $ctx->tenantId);
        $this->assertNull($ctx->subjectType);
        $this->assertNull($ctx->subjectId);
    }

    public function test_tenant_id_override_wins_over_tenant_resolver(): void
    {
        $task = $this->makeTask(tenantId: 'org-123');

        $this->assertSame('org-123', $task->context()->tenantId);
    }

    public function test_subject_type_and_id_override(): void
    {
        $task = $this->makeTask(tenantId: 'org-123', subjectType: 'chat', subjectId: 'chat-456');

        $ctx = $task->context();

        $this->assertSame('chat', $ctx->subjectType);
        $this->assertSame('chat-456', $ctx->subjectId);
    }

    public function test_context_is_cached_across_calls(): void
    {
        $task = $this->makeTask(tenantId: 'org-123');

        $this->assertSame($task->context(), $task->context());
    }

    public function test_null_tenant_id_override_falls_back_to_tenant_resolver(): void
    {
        config(['ai-tasks.default_tenant' => 'fallback-tenant']);

        $task = $this->makeTask(tenantId: null, subjectType: 'chat', subjectId: 'chat-456');

        $ctx = $task->context();

        $this->assertSame('fallback-tenant', $ctx->tenantId);
        $this->assertSame('chat', $ctx->subjectType);
    }
}
