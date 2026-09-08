<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_efficiencies', function (Blueprint $table) {
            $table->id();

            $table->foreignId('staff_id')
                ->constrained('staff')
                ->cascadeOnDelete();

            /*
             * Calculation period.
             *
             * Examples:
             * 2026-08-01 → 2026-08-31
             * 2026-09-01 → 2026-09-30
             */
            $table->date('period_start');
            $table->date('period_end');

            /*
             * Main efficiency metrics.
             */
            $table->decimal('on_time_rate', 5, 2)
                ->default(0);

            $table->decimal('first_pass_rate', 5, 2)
                ->default(0);

            $table->decimal('volume', 12, 2)
                ->default(0);

            /*
             * Average response time in minutes.
             */
            $table->decimal('response_speed', 12, 2)
                ->default(0);

            /*
             * Supporting statistics.
             */
            $table->unsignedInteger('created_tasks')
                ->default(0);

            $table->unsignedInteger('completed_tasks')
                ->default(0);

            $table->unsignedInteger('overdue_tasks')
                ->default(0);

            $table->unsignedInteger('reworked_tasks')
                ->default(0);

            $table->timestampTz('calculated_at')->nullable();

            $table->timestampsTz();

            /*
             * One efficiency snapshot per staff/member
             * for a given period.
             */
            $table->unique([
                'staff_id',
                'period_start',
                'period_end',
            ]);

            $table->index([
                'period_start',
                'period_end',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_efficiencies');
    }
};
