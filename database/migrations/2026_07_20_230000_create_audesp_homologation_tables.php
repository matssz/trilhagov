<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audesp_homologation_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('municipality_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('retry_of_id')->nullable()->constrained('audesp_homologation_batches')->nullOnDelete();
            $table->uuid('reference')->unique();
            $table->unsignedSmallInteger('fiscal_year');
            $table->unsignedTinyInteger('reference_month');
            $table->string('source_system', 120);
            $table->string('source_version', 80)->nullable();
            $table->string('schema_version', 20);
            $table->string('status', 40)->default('under_review');
            $table->string('source_original_name', 255);
            $table->string('source_storage_path', 500);
            $table->string('source_mime_type', 120);
            $table->unsignedBigInteger('source_size_bytes');
            $table->string('source_sha256', 64);
            $table->unsignedInteger('item_count')->default(0);
            $table->unsignedInteger('matched_count')->default(0);
            $table->unsignedInteger('divergent_count')->default(0);
            $table->unsignedInteger('unmatched_count')->default(0);
            $table->timestamp('submitted_at')->nullable();
            $table->string('external_protocol', 160)->nullable();
            $table->timestamp('last_return_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['municipality_id', 'source_sha256'], 'audesp_batch_source_hash_unique');
            $table->index(['municipality_id', 'fiscal_year', 'reference_month']);
            $table->index(['municipality_id', 'status']);
        });

        Schema::create('audesp_homologation_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('municipality_id')->constrained()->cascadeOnDelete();
            $table->foreignId('audesp_homologation_batch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parliamentary_amendment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('audesp_amendment_registration_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 30);
            $table->string('source_scope', 1)->nullable();
            $table->string('source_amendment_number', 30)->nullable();
            $table->unsignedSmallInteger('source_amendment_year')->nullable();
            $table->string('operation', 40)->nullable();
            $table->json('source_snapshot');
            $table->json('local_snapshot')->nullable();
            $table->json('differences')->nullable();
            $table->timestamps();

            $table->index(['municipality_id', 'status']);
            $table->index(['parliamentary_amendment_id', 'audesp_homologation_batch_id'], 'audesp_item_amendment_batch_index');
        });

        Schema::create('audesp_homologation_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('municipality_id')->constrained()->cascadeOnDelete();
            $table->foreignId('audesp_homologation_batch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('type', 40);
            $table->string('external_status', 40)->nullable();
            $table->string('protocol', 160)->nullable();
            $table->timestamp('occurred_at');
            $table->string('issue_code', 100)->nullable();
            $table->string('issue_field', 160)->nullable();
            $table->text('message')->nullable();
            $table->string('evidence_original_name', 255)->nullable();
            $table->string('evidence_storage_path', 500)->nullable();
            $table->string('evidence_mime_type', 120)->nullable();
            $table->unsignedBigInteger('evidence_size_bytes')->nullable();
            $table->string('evidence_sha256', 64)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['audesp_homologation_batch_id', 'occurred_at'], 'audesp_event_batch_date_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audesp_homologation_events');
        Schema::dropIfExists('audesp_homologation_items');
        Schema::dropIfExists('audesp_homologation_batches');
    }
};
