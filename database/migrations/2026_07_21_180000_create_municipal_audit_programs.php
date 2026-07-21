<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('municipal_audit_programs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('municipality_id')->constrained()->cascadeOnDelete();
            $table->foreignId('municipal_audit_plan_item_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('lead_auditor_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('supervisor_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('concluded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 30)->default('draft');
            $table->string('title', 220);
            $table->text('objective');
            $table->text('scope');
            $table->string('sampling_method', 30);
            $table->text('population_description');
            $table->unsignedInteger('population_size')->nullable();
            $table->unsignedInteger('sample_size')->nullable();
            $table->text('materiality_criteria');
            $table->date('start_at');
            $table->date('due_at');
            $table->text('supervisor_notes')->nullable();
            $table->text('conclusion')->nullable();
            $table->json('snapshot')->nullable();
            $table->char('snapshot_sha256', 64)->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('concluded_at')->nullable();
            $table->timestamps();

            $table->index(['municipality_id', 'status', 'due_at'], 'audit_program_status_due_idx');
        });

        Schema::create('municipal_audit_procedures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('municipality_id')->constrained()->cascadeOnDelete();
            $table->foreignId('municipal_audit_program_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('executed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('sequence');
            $table->string('title', 220);
            $table->text('objective');
            $table->text('test_method');
            $table->text('sample_description');
            $table->text('expected_evidence');
            $table->string('status', 30)->default('planned');
            $table->text('result')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->timestamps();

            $table->unique(['municipal_audit_program_id', 'sequence'], 'audit_procedure_sequence_unique');
        });

        Schema::create('municipal_audit_evidences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('municipality_id')->constrained()->cascadeOnDelete();
            $table->foreignId('municipal_audit_procedure_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('uploader_name');
            $table->string('description', 500);
            $table->string('original_name');
            $table->string('storage_path');
            $table->string('mime_type', 150);
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['municipal_audit_procedure_id', 'created_at'], 'audit_evidence_time_idx');
        });

        Schema::create('municipal_audit_findings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('municipality_id')->constrained()->cascadeOnDelete();
            $table->foreignId('municipal_audit_program_id')->constrained()->cascadeOnDelete();
            $table->foreignId('municipal_audit_procedure_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('severity', 20);
            $table->string('title', 220);
            $table->text('criteria');
            $table->text('condition');
            $table->text('cause')->nullable();
            $table->text('effect')->nullable();
            $table->text('recommendation');
            $table->date('recommended_due_at')->nullable();
            $table->timestamps();

            $table->index(['municipal_audit_program_id', 'severity'], 'audit_finding_severity_idx');
        });

        Schema::create('municipal_audit_program_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('municipality_id')->constrained()->cascadeOnDelete();
            $table->foreignId('municipal_audit_program_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('actor_name');
            $table->string('event_type', 40);
            $table->text('description');
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['municipal_audit_program_id', 'created_at'], 'audit_program_event_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('municipal_audit_program_events');
        Schema::dropIfExists('municipal_audit_findings');
        Schema::dropIfExists('municipal_audit_evidences');
        Schema::dropIfExists('municipal_audit_procedures');
        Schema::dropIfExists('municipal_audit_programs');
    }
};
