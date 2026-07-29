<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {

    public function up(): void
    {
        Schema::table(config('ai-tasks.table', 'ai_runs'), function (Blueprint $t) {
            $t->string('model')->nullable()->after('driver');
        });
    }

    public function down(): void
    {
        Schema::table(config('ai-tasks.table', 'ai_runs'), function (Blueprint $t) {
            $t->dropColumn('model');
        });
    }
};
