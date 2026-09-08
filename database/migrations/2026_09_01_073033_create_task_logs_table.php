<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_logs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('task_id')
                ->constrained('tasks')
                ->cascadeOnDelete();

            /*
             * Staff who performed the action.
             */
            $table->foreignId('actor_id')
                ->nullable()
                ->constrained('staff')
                ->nullOnDelete();

            $table->string('event_type', 50)->index();

            /*
             * Status transition.
             */
            $table->string('from_status', 40)->nullable();
            $table->string('to_status', 40)->nullable();

            /*
             * Assignee transition.
             */
            $table->foreignId('from_assignee_id')
                ->nullable()
                ->constrained('staff')
                ->nullOnDelete();

            $table->foreignId('to_assignee_id')
                ->nullable()
                ->constrained('staff')
                ->nullOnDelete();

            /*
             * Optional human-readable information.
             */
            $table->text('message')->nullable();

            /*
             * Flexible event-specific data.
             */
            $table->jsonb('metadata')->nullable();

            $table->timestampTz('created_at')->useCurrent();

            /*
             * A log is immutable.
             *
             * No updated_at.
             */

            $table->index(['task_id', 'created_at']);
            $table->index(['actor_id', 'created_at']);
            $table->index(['event_type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_logs');
    }
};
