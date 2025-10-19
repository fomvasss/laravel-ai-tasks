<?php

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
            $t->string('status')->index(); // queued|running|ok|error|dead|waiting
            $t->string('error')->nullable();
            $t->string('idempotency_key')->nullable()->index();
            $t->json('request');   // метадані/шаблон/опції (без великих blob)
            $t->json('response')->nullable(); // метадані/шлях у storage
            $t->json('usage')->nullable(); // tokens_in/out, cost, duration_ms
            $t->timestamp('started_at')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->unsignedInteger('duration_ms')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_runs');
    }
};
