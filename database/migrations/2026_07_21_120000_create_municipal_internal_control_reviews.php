<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('municipal_internal_control_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('municipality_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parliamentary_amendment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('municipal_governance_report_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('reviewed_by')->constrained('users')->restrictOnDelete();
            $table->unsignedSmallInteger('sequence');
            $table->string('reference', 80);
            $table->string('phase', 30);
            $table->string('conclusion', 40);
            $table->json('criteria');
            $table->text('summary');
            $table->text('findings')->nullable();
            $table->text('recommendations')->nullable();
            $table->string('annual_audit_plan_reference', 255);
            $table->text('legal_basis');
            $table->json('snapshot');
            $table->char('snapshot_sha256', 64);
            $table->string('evidence_path')->nullable();
            $table->string('evidence_original_name')->nullable();
            $table->string('evidence_mime', 100)->nullable();
            $table->unsignedBigInteger('evidence_size')->nullable();
            $table->char('evidence_sha256', 64)->nullable();
            $table->timestamp('issued_at');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['parliamentary_amendment_id', 'sequence'], 'internal_control_review_sequence_unique');
            $table->unique(['municipality_id', 'reference'], 'internal_control_review_reference_unique');
            $table->index(['municipality_id', 'conclusion']);
            $table->index(['municipality_id', 'phase']);
        });

        Schema::create('municipal_internal_control_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('municipality_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parliamentary_amendment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('municipal_internal_control_review_id')->constrained()->cascadeOnDelete();
            $table->foreignId('responsible_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('responded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 30)->default('open');
            $table->string('title', 220);
            $table->text('instructions');
            $table->date('due_at');
            $table->text('response_summary')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['municipality_id', 'status', 'due_at'], 'internal_control_action_status_due_index');
            $table->index(['responsible_user_id', 'status']);
        });

        Schema::create('municipal_internal_control_action_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('municipality_id')->constrained()->cascadeOnDelete();
            $table->foreignId('municipal_internal_control_action_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('actor_name');
            $table->string('event_type', 30);
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->text('description');
            $table->string('evidence_path')->nullable();
            $table->string('evidence_original_name')->nullable();
            $table->string('evidence_mime', 100)->nullable();
            $table->unsignedBigInteger('evidence_size')->nullable();
            $table->char('evidence_sha256', 64)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['municipal_internal_control_action_id', 'created_at'], 'internal_control_action_event_time_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('municipal_internal_control_action_events');
        Schema::dropIfExists('municipal_internal_control_actions');
        Schema::dropIfExists('municipal_internal_control_reviews');
    }
};
