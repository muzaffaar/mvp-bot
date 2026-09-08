<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qr_login_sessions', function (Blueprint $table) {
            $table->id();

            /*
             * Secret encoded inside the Telegram QR/deep-link.
             * We NEVER store the plain value.
             */
            $table->string('token_hash')->unique();

            /*
             * Hash of the browser session that created this QR login.
             */
            $table->string('browser_hash')->index();

            $table->foreignId('staff_id')
                ->nullable()
                ->constrained('staff')
                ->nullOnDelete();

            $table->string('status')
                ->default('pending');

            $table->timestamp('expires_at');

            $table->timestamp('approved_at')
                ->nullable();

            $table->timestamp('consumed_at')
                ->nullable();

            $table->timestamps();

            $table->index([
                'status',
                'expires_at',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qr_login_sessions');
    }
};
