<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {

    public function up(): void
    {
        Schema::table(config('ai-tasks.table', 'ai_runs'), function (Blueprint $t) {
            // Хто запустив прогін — string, як tenant_id: id користувача буває int, uuid чи ulid
            $t->string('user_id')->nullable()->after('tenant_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table(config('ai-tasks.table', 'ai_runs'), function (Blueprint $t) {
            $t->dropIndex(['user_id']);
            $t->dropColumn('user_id');
        });
    }
};
