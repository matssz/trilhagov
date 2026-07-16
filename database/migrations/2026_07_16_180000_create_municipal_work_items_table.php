<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('municipal_work_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('municipality_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parliamentary_amendment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('responsible_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source_key', 190);
            $table->string('category', 30);
            $table->string('title', 180);
            $table->text('guidance');
            $table->string('action_url', 500);
            $table->string('priority', 20);
            $table->string('status', 20)->default('pending');
            $table->date('due_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('first_detected_at');
            $table->timestamp('last_evaluated_at');
            $table->timestamp('completed_at')->nullable();
            $table->string('completion_reason', 500)->nullable();
            $table->timestamps();

            $table->unique(['municipality_id', 'source_key'], 'municipal_work_items_source_unique');
            $table->index(['municipality_id', 'status', 'priority'], 'municipal_work_items_queue_index');
            $table->index(['municipality_id', 'responsible_user_id', 'status'], 'municipal_work_items_owner_index');
            $table->index(['municipality_id', 'due_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('municipal_work_items');
    }
};
