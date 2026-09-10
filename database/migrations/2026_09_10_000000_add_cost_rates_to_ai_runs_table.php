<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {

    public function up(): void
    {
        Schema::table(config('ai-tasks.table', 'ai_runs'), function (Blueprint $t) {
            // Знімок ставок, за якими пораховано cost. json(), не jsonb — портативно між
            // Postgres і MySQL; вибірок по вмісту тут не робиться, лише читання рядка.
            $t->json('cost_rates')->nullable()->after('cost');
        });
    }

    public function down(): void
    {
        Schema::table(config('ai-tasks.table', 'ai_runs'), function (Blueprint $t) {
            $t->dropColumn('cost_rates');
        });
    }
};
