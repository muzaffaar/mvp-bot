<?php

use App\Enums\SprintStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sprints', function (Blueprint $table) {
            $table->id();

            $table->string('name', 255);
            $table->text('description')->nullable();

            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();

            $table->string('status', 30)
                ->default(SprintStatus::PLANNED->value)
                ->index();

            $table->foreignId('created_by')
                ->constrained('staff')
                ->restrictOnDelete();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['status', 'start_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sprints');
    }
};
