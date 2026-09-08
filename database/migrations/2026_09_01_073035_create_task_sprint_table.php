<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_sprint', function (Blueprint $table) {
            $table->id();

            $table->foreignId('task_id')
                ->constrained('tasks')
                ->cascadeOnDelete();

            $table->foreignId('sprint_id')
                ->constrained('sprints')
                ->cascadeOnDelete();

            $table->timestampTz('added_at')->useCurrent();
            $table->timestampTz('removed_at')->nullable();

            $table->timestampsTz();

            $table->index(['task_id', 'added_at']);
            $table->index(['sprint_id', 'added_at']);

            /*
             * Prevent the same task from being added
             * to the same sprint twice.
             */
            $table->unique(['task_id', 'sprint_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_sprint');
    }
};
