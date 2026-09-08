<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('task_telegram_messages', function (Blueprint $table) {
            if (! Schema::hasColumn('task_telegram_messages', 'role')) {
                $table->string('role')->default('notification')->after('message_id');
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('task_telegram_messages', 'role')) {
            Schema::table('task_telegram_messages', function (Blueprint $table) {
                $table->dropColumn('role');
            });
        }
    }
};
