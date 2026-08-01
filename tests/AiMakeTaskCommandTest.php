<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\Tests;

use Fomvasss\AiTasks\AiServiceProvider;
use Illuminate\Support\Facades\File;
use Orchestra\Testbench\TestCase;

class AiMakeTaskCommandTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [AiServiceProvider::class];
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(app_path('Ai'));
        parent::tearDown();
    }

    public function test_plain_task_also_wires_up_auto_serialize_trait(): void
    {
        $this->artisan('ai:make-task', ['name' => 'PlainStubTask'])->assertExitCode(0);

        $content = File::get(app_path('Ai/Tasks/PlainStubTask.php'));

        $this->assertStringContainsString('use Fomvasss\AiTasks\Traits\AutoSerializesConstructorArgs;', $content);
        $this->assertStringContainsString('use AutoSerializesConstructorArgs;', $content);
        $this->assertStringContainsString('class PlainStubTask extends AiTask', $content);
        $this->assertStringNotContainsString('ShouldQueueAi', $content);

        require app_path('Ai/Tasks/PlainStubTask.php');
        $this->assertContains(
            \Fomvasss\AiTasks\Traits\AutoSerializesConstructorArgs::class,
            class_uses(\App\Ai\Tasks\PlainStubTask::class),
        );
    }

    public function test_queued_task_additionally_implements_should_queue_ai(): void
    {
        $this->artisan('ai:make-task', ['name' => 'QueuedStubTask', '--queued' => true])->assertExitCode(0);

        $content = File::get(app_path('Ai/Tasks/QueuedStubTask.php'));

        $this->assertStringContainsString('use Fomvasss\AiTasks\Traits\AutoSerializesConstructorArgs;', $content);
        $this->assertStringContainsString('use AutoSerializesConstructorArgs;', $content);
        $this->assertStringContainsString('class QueuedStubTask extends AiTask implements ShouldQueueAi', $content);
        $this->assertStringNotContainsString('return []; // return constructor args', $content);

        require app_path('Ai/Tasks/QueuedStubTask.php');
        $this->assertTrue(trait_exists(\Fomvasss\AiTasks\Traits\AutoSerializesConstructorArgs::class));
        $this->assertContains(
            \Fomvasss\AiTasks\Traits\AutoSerializesConstructorArgs::class,
            class_uses(\App\Ai\Tasks\QueuedStubTask::class),
        );
    }
}
