<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {

    public function up(): void
    {
        Schema::create('ai_runs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('tenant_id')->index();
            $t->string('task')->index();
            $t->string('driver')->index();
            $t->string('modality');
            $t->string('subject_type')->nullable()->index();
            $t->string('subject_id')->nullable()->index();
            $t->string('status')->index(); // queued|running|ok|error|dead|waiting|skipped
            $t->text('error')->nullable();
            $t->string('idempotency_key')->nullable()->unique();
            $t->json('request')->nullable();
            $t->json('response')->nullable();
            // денормалізовані токени для SQL-аналітики
            $t->unsignedMediumInteger('tokens_in')->nullable();
            $t->unsignedMediumInteger('tokens_out')->nullable();
            $t->unsignedMediumInteger('cache_read_tokens')->nullable();
            $t->unsignedMediumInteger('cache_write_tokens')->nullable();
            $t->decimal('cost', 12, 8)->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->unsignedMediumInteger('duration_ms')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_runs');
    }
};
