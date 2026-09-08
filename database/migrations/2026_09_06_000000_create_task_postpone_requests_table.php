<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_postpone_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requester_id')->constrained('staff')->cascadeOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('staff')->nullOnDelete();
            $table->unsignedInteger('amount');
            $table->string('unit', 10);
            $table->unsignedInteger('minutes');
            $table->timestamp('old_deadline');
            $table->timestamp('requested_deadline');
            $table->string('status', 20)->default('pending');
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->index(['task_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_postpone_requests');
    }
};
