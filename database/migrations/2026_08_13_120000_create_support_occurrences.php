<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_occurrences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('municipality_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('fingerprint', 64)->unique();
            $table->string('source', 40)->default('exception');
            $table->string('level', 30)->default('error');
            $table->string('status', 30)->default('open');
            $table->string('title', 180);
            $table->text('message');
            $table->string('route_name')->nullable();
            $table->string('method', 12)->nullable();
            $table->text('url')->nullable();
            $table->string('ip_address', 80)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->json('context')->nullable();
            $table->unsignedInteger('occurrence_count')->default(1);
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['municipality_id', 'status']);
            $table->index(['source', 'level']);
            $table->index('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_occurrences');
    }
};
