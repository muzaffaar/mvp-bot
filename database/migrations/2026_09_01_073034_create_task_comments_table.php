<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_comments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('task_id')
                ->constrained('tasks')
                ->cascadeOnDelete();

            $table->foreignId('staff_id')
                ->constrained('staff')
                ->restrictOnDelete();

            /*
             * Optional relationship to the task event
             * that caused/contains this comment.
             */
            $table->foreignId('task_log_id')
                ->nullable()
                ->constrained('task_logs')
                ->nullOnDelete();

            $table->text('body');

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['task_id', 'created_at']);
            $table->index(['staff_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_comments');
    }
};
