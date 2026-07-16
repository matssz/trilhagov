<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('execution_stages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('municipality_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parliamentary_amendment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('responsible_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('title', 160);
            $table->text('description')->nullable();
            $table->string('status', 30)->default('planned');
            $table->unsignedTinyInteger('progress_percentage')->default(0);
            $table->decimal('planned_amount', 15, 2)->nullable();
            $table->date('planned_start_at')->nullable();
            $table->date('planned_end_at')->nullable();
            $table->date('completed_at')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(10);
            $table->timestamps();

            $table->index(['parliamentary_amendment_id', 'sort_order']);
            $table->index(['municipality_id', 'status']);
        });

        Schema::create('financial_commitments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('municipality_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parliamentary_amendment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('execution_stage_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('commitment_number', 80);
            $table->string('supplier_name', 180);
            $table->string('supplier_document', 20)->nullable();
            $table->string('procurement_process', 100);
            $table->text('object_description');
            $table->decimal('committed_amount', 15, 2);
            $table->date('committed_at');
            $table->string('status', 20)->default('active');
            $table->text('cancellation_reason')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->unique(['parliamentary_amendment_id', 'commitment_number'], 'commitments_amendment_number_unique');
            $table->index(['municipality_id', 'status']);
        });

        Schema::create('financial_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('municipality_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parliamentary_amendment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('financial_commitment_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('payment_reference', 100);
            $table->decimal('amount', 15, 2);
            $table->date('paid_at');
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->unique(['financial_commitment_id', 'payment_reference'], 'payments_commitment_reference_unique');
            $table->index(['municipality_id', 'paid_at']);
            $table->index(['parliamentary_amendment_id', 'paid_at']);
        });

        Schema::table('amendment_documents', function (Blueprint $table) {
            $table->foreignId('execution_stage_id')
                ->nullable()
                ->after('document_type_id')
                ->constrained()
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('amendment_documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('execution_stage_id');
        });

        Schema::dropIfExists('financial_payments');
        Schema::dropIfExists('financial_commitments');
        Schema::dropIfExists('execution_stages');
    }
};
