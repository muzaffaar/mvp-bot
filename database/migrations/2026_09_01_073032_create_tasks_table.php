<?php

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();

            // Human-readable identifier:
            // TASK-2026-000104
            $table->string('task_number', 30)->unique();

            $table->string('status', 40)
                ->default(TaskStatus::CREATED->value)
                ->index();

            $table->string('title', 500);
            $table->text('description')->nullable();

            /*
             * Staff relationships
             */
            $table->foreignId('author_id')
                ->constrained('staff')
                ->restrictOnDelete();

            $table->foreignId('assignor_id')
                ->nullable()
                ->constrained('staff')
                ->nullOnDelete();

            $table->foreignId('assignee_id')
                ->nullable()
                ->constrained('staff')
                ->nullOnDelete();

            $table->string('assignment_type', 20)
                ->default('direct')
                ->index();

            $table->string('priority', 20)
                ->default(TaskPriority::NORMAL->value)
                ->index();

            $table->timestampTz('deadline')->nullable();

            $table->jsonb('metadata')->nullable();

            /*
             * Task source.
             *
             * Examples:
             * telegram
             * panel
             * api
             */
            $table->string('source_type', 30)->nullable();

            // External source identifier.
            $table->string('source_id', 255)->nullable();

            // Telegram message ID if applicable.
            $table->string('source_message_id', 255)->nullable();

            // Link back to Telegram/source.
            $table->text('source_url')->nullable();

            /*
             * Confidence/accuracy of automatically
             * extracted task information.
             *
             * Example: 0.90 = 90%
             */
            $table->decimal('ajralish_aniqligi', 5, 2)
                ->nullable();

            /*
             * Lifecycle timestamps
             */
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('closed_at')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            /*
             * Query optimization
             */
            $table->index(['assignee_id', 'status']);
            $table->index(['status', 'deadline']);
            $table->index(['priority', 'deadline']);
            $table->index(['author_id', 'created_at']);
            $table->index(['assignor_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
