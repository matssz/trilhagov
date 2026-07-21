<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('municipal_document_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('municipality_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('based_on_id')->nullable()->constrained('municipal_document_templates')->nullOnDelete();
            $table->string('document_type', 40);
            $table->string('name', 160);
            $table->string('prefix', 12);
            $table->unsignedInteger('version');
            $table->text('subject_template');
            $table->longText('body_template');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['municipality_id', 'document_type', 'version'], 'municipal_template_type_version_unique');
            $table->index(['municipality_id', 'is_active', 'document_type'], 'municipal_template_active_type_index');
        });

        Schema::create('municipal_official_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('municipality_id')->constrained()->cascadeOnDelete();
            $table->foreignId('municipal_document_template_id')->constrained()->restrictOnDelete();
            $table->foreignId('parliamentary_amendment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('technical_impediment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('technical_diligence_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('municipal_internal_control_review_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('supersedes_id')->nullable()->constrained('municipal_official_documents')->nullOnDelete();
            $table->uuid('reference')->unique();
            $table->unsignedSmallInteger('fiscal_year');
            $table->unsignedInteger('sequence')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->string('official_number', 80)->nullable();
            $table->string('document_type', 40);
            $table->string('status', 30)->default('draft');
            $table->string('recipient_name', 180);
            $table->string('recipient_role', 180)->nullable();
            $table->string('recipient_entity', 180);
            $table->string('recipient_email', 180)->nullable();
            $table->string('delivery_method', 40)->nullable();
            $table->string('protocol_number', 160)->nullable();
            $table->string('subject', 500);
            $table->longText('body');
            $table->date('response_due_at')->nullable();
            $table->json('snapshot')->nullable();
            $table->string('snapshot_sha256', 64)->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->unique(['municipality_id', 'fiscal_year', 'document_type', 'sequence'], 'municipal_official_document_sequence_unique');
            $table->index(['municipality_id', 'status', 'created_at'], 'municipal_official_document_status_index');
        });

        Schema::create('municipal_official_document_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('municipality_id')->constrained()->cascadeOnDelete();
            $table->foreignId('municipal_official_document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('type', 40);
            $table->timestamp('occurred_at');
            $table->string('protocol_number', 160)->nullable();
            $table->text('message')->nullable();
            $table->string('evidence_original_name', 255)->nullable();
            $table->string('evidence_storage_path', 500)->nullable();
            $table->string('evidence_mime_type', 120)->nullable();
            $table->unsignedBigInteger('evidence_size_bytes')->nullable();
            $table->string('evidence_sha256', 64)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['municipal_official_document_id', 'occurred_at'], 'municipal_official_document_event_date_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('municipal_official_document_events');
        Schema::dropIfExists('municipal_official_documents');
        Schema::dropIfExists('municipal_document_templates');
    }
};
