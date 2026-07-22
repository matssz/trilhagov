<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('municipal_contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('municipality_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parliamentary_amendment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('contract_manager_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('contract_inspector_id')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('reference')->unique();
            $table->string('process_number', 100);
            $table->string('contract_number', 100)->nullable();
            $table->string('object_type', 40);
            $table->boolean('is_renovation')->default(false);
            $table->string('procurement_method', 50);
            $table->string('execution_regime', 60)->nullable();
            $table->string('judgment_criterion', 80)->nullable();
            $table->text('object');
            $table->string('site_location', 255)->nullable();
            $table->decimal('estimated_amount', 15, 2);
            $table->string('supplier_name', 180)->nullable();
            $table->string('supplier_document', 20)->nullable();
            $table->decimal('original_amount', 15, 2)->nullable();
            $table->decimal('current_amount', 15, 2)->nullable();
            $table->date('signed_at')->nullable();
            $table->date('effective_start_at')->nullable();
            $table->date('effective_end_at')->nullable();
            $table->date('work_order_at')->nullable();
            $table->string('status', 30)->default('planning');
            $table->json('planning_checklist')->nullable();
            $table->text('measurement_criteria')->nullable();
            $table->text('payment_terms')->nullable();
            $table->unsignedSmallInteger('warranty_months')->nullable();
            $table->string('technical_responsible', 180)->nullable();
            $table->string('technical_registration', 100)->nullable();
            $table->string('publication_type', 50)->nullable();
            $table->string('publication_reference', 500)->nullable();
            $table->date('published_at')->nullable();
            $table->string('provisional_acceptance_reference', 255)->nullable();
            $table->date('provisional_accepted_at')->nullable();
            $table->string('definitive_acceptance_reference', 255)->nullable();
            $table->date('definitive_accepted_at')->nullable();
            $table->text('suspension_reason')->nullable();
            $table->date('suspended_at')->nullable();
            $table->date('resumed_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['municipality_id', 'process_number'], 'municipal_contract_process_unique');
            $table->index(['municipality_id', 'status']);
            $table->index(['parliamentary_amendment_id', 'status'], 'municipal_contract_amendment_status_index');
            $table->index(['effective_end_at', 'status']);
        });

        Schema::create('contract_measurements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('municipality_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parliamentary_amendment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('municipal_contract_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('evidence_document_id')->nullable()->constrained('amendment_documents')->nullOnDelete();
            $table->unsignedSmallInteger('sequence');
            $table->string('status', 20)->default('draft');
            $table->date('period_start_at');
            $table->date('period_end_at');
            $table->date('measured_at');
            $table->decimal('amount', 15, 2);
            $table->decimal('cumulative_physical_percentage', 5, 2);
            $table->text('notes');
            $table->text('review_notes')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->json('snapshot')->nullable();
            $table->string('snapshot_sha256', 64)->nullable();
            $table->timestamps();

            $table->unique(['municipal_contract_id', 'sequence'], 'contract_measurement_sequence_unique');
            $table->index(['municipality_id', 'status']);
        });

        Schema::create('contract_addenda', function (Blueprint $table) {
            $table->id();
            $table->foreignId('municipality_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parliamentary_amendment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('municipal_contract_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('evidence_document_id')->nullable()->constrained('amendment_documents')->nullOnDelete();
            $table->unsignedSmallInteger('sequence');
            $table->string('type', 30);
            $table->string('status', 20)->default('draft');
            $table->decimal('value_change', 15, 2)->default(0);
            $table->integer('days_change')->default(0);
            $table->text('justification');
            $table->text('technical_basis');
            $table->date('effective_at');
            $table->date('signed_at')->nullable();
            $table->string('publication_reference', 500)->nullable();
            $table->date('published_at')->nullable();
            $table->text('advance_effects_justification')->nullable();
            $table->text('review_notes')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->json('snapshot')->nullable();
            $table->string('snapshot_sha256', 64)->nullable();
            $table->timestamps();

            $table->unique(['municipal_contract_id', 'sequence'], 'contract_addendum_sequence_unique');
            $table->index(['municipality_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_addenda');
        Schema::dropIfExists('contract_measurements');
        Schema::dropIfExists('municipal_contracts');
    }
};
