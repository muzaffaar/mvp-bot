<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        /**
         * Remove the old incorrect constraint.
         *
         * Old:
         * UNIQUE(task_id, staff_id)
         */
        DB::statement("
            ALTER TABLE task_telegram_messages
            DROP CONSTRAINT IF EXISTS task_telegram_messages_task_id_staff_id_unique
        ");

        /**
         * Bot-generated messages don't belong to a staff member.
         */
        DB::statement("
            ALTER TABLE task_telegram_messages
            ALTER COLUMN staff_id DROP NOT NULL
        ");

        /**
         * Clean up the partially-created relation/index from the
         * previously failed migration.
         *
         * PostgreSQL unique constraints create underlying indexes.
         * A failed migration can leave one behind.
         */
        DB::statement("
            DROP INDEX IF EXISTS task_telegram_messages_chat_message_unique
        ");

        /**
         * Create the correct unique constraint.
         *
         * One Telegram message is uniquely identified inside its chat.
         */
        DB::statement("
            ALTER TABLE task_telegram_messages
            ADD CONSTRAINT task_telegram_messages_chat_message_unique
            UNIQUE (chat_id, message_id)
        ");
    }

    public function down(): void
    {
        DB::statement("
            ALTER TABLE task_telegram_messages
            DROP CONSTRAINT IF EXISTS task_telegram_messages_chat_message_unique
        ");
    }
};
