<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff', function (Blueprint $table) {
            $table->id();

            $table->string('full_name');
            $table->string('name')->nullable();
            $table->string('username')->nullable()->unique();

            $table->string('login')->nullable()->unique();
            $table->string('password')->nullable();

            $table->string('telegram_chat_id')->unique();
            $table->string('token')->nullable()->unique();

            $table->string('status')->default('active');
            $table->string('lavozim')->nullable();

            $table->string('group_name')->nullable();
            $table->bigInteger('group_chat_id')->nullable();

            $table->rememberToken();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff');
    }
};
