<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_data_syncs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('municipality_id')->constrained()->cascadeOnDelete();
            $table->foreignId('initiated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source', 50);
            $table->string('status', 20);
            $table->timestamp('source_updated_at')->nullable();
            $table->unsignedInteger('items_fetched')->default(0);
            $table->unsignedInteger('items_created')->default(0);
            $table->unsignedInteger('items_updated')->default(0);
            $table->unsignedInteger('divergences_found')->default(0);
            $table->text('error_message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['municipality_id', 'source', 'created_at'], 'external_syncs_source_index');
        });

        Schema::create('external_amendment_candidates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('municipality_id')->constrained()->cascadeOnDelete();
            $table->foreignId('external_data_sync_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('parliamentary_amendment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source', 50);
            $table->string('external_id', 100);
            $table->string('external_code', 100)->nullable();
            $table->string('amendment_code', 120)->nullable();
            $table->unsignedSmallInteger('fiscal_year')->nullable();
            $table->string('author_name')->nullable();
            $table->text('object')->nullable();
            $table->decimal('expected_amount', 15, 2)->nullable();
            $table->string('external_status', 80)->nullable();
            $table->date('accepted_at')->nullable();
            $table->string('bank_status', 120)->nullable();
            $table->string('match_status', 30);
            $table->json('differences')->nullable();
            $table->json('payload');
            $table->char('source_hash', 64);
            $table->text('review_notes')->nullable();
            $table->timestamp('last_seen_at');
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->unique(['municipality_id', 'source', 'external_id'], 'external_candidate_unique');
            $table->index(['municipality_id', 'match_status']);
            $table->index(['municipality_id', 'fiscal_year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_amendment_candidates');
        Schema::dropIfExists('external_data_syncs');
    }
};
