<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('technical_impediments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('municipality_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parliamentary_amendment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('evidence_document_id')->nullable()->constrained('amendment_documents')->nullOnDelete();
            $table->string('category');
            $table->string('nature')->default('under_analysis');
            $table->string('status')->default('identified');
            $table->string('title', 180);
            $table->text('description');
            $table->text('impact');
            $table->date('identified_at');
            $table->date('resolution_due_at')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['municipality_id', 'status']);
            $table->index(['parliamentary_amendment_id', 'nature']);
        });

        Schema::create('technical_diligences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('municipality_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parliamentary_amendment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('technical_impediment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('evidence_document_id')->nullable()->constrained('amendment_documents')->nullOnDelete();
            $table->string('status')->default('open');
            $table->string('title', 180);
            $table->text('request_details');
            $table->date('requested_at');
            $table->date('due_at');
            $table->text('response_notes')->nullable();
            $table->string('response_protocol', 120)->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();

            $table->index(['technical_impediment_id', 'status']);
            $table->index(['municipality_id', 'due_at']);
        });

        Schema::create('amendment_remappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('municipality_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parliamentary_amendment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('technical_impediment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('draft');
            $table->text('original_object');
            $table->text('proposed_object');
            $table->text('justification');
            $table->decimal('amount', 15, 2);
            $table->date('requested_at')->nullable();
            $table->text('decision_notes')->nullable();
            $table->string('decision_reference', 160)->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['technical_impediment_id', 'status']);
            $table->index(['municipality_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('amendment_remappings');
        Schema::dropIfExists('technical_diligences');
        Schema::dropIfExists('technical_impediments');
    }
};
