<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('municipal_work_item_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('municipality_id')->constrained()->cascadeOnDelete();
            $table->foreignId('municipal_work_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('actor_name', 180);
            $table->string('event_type', 30);
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();
            $table->string('description', 500);
            $table->json('metadata')->nullable();
            $table->timestamp('created_at');

            $table->index(['municipal_work_item_id', 'created_at'], 'municipal_work_item_events_history_index');
            $table->index(['municipality_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('municipal_work_item_events');
    }
};
