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
        Schema::create('task_telegram_messages', function (Blueprint $table) {
            $table->id();

            $table->foreignId('task_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('staff_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->bigInteger('chat_id');

            $table->unsignedBigInteger('message_id');

            $table->timestamps();

            $table->unique([
                'task_id',
                'staff_id',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('task_telegram_messages');
    }
};
