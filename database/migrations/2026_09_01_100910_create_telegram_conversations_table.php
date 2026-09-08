<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('telegram_conversations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('staff_id')
                ->nullable()
                ->constrained('staff')
                ->nullOnDelete();

            $table->bigInteger('telegram_chat_id')
                ->unique();

            $table->string('state', 50)
                ->default('idle');

            $table->jsonb('context')
                ->nullable();

            $table->timestamp('last_activity_at')
                ->nullable();

            $table->timestamps();

            $table->index([
                'staff_id',
                'state',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('telegram_conversations');
    }
};
