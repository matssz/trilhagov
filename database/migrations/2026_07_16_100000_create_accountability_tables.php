<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accountability_processes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('municipality_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parliamentary_amendment_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('responsible_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('status', 30)->default('preparing');
            $table->date('due_at')->nullable();
            $table->date('submitted_at')->nullable();
            $table->string('protocol_number', 100)->nullable();
            $table->date('approved_at')->nullable();
            $table->decimal('returned_amount', 15, 2)->default(0);
            $table->date('returned_at')->nullable();
            $table->string('return_reference', 120)->nullable();
            $table->text('reconciliation_notes')->nullable();
            $table->text('submission_notes')->nullable();
            $table->timestamps();

            $table->index(['municipality_id', 'status']);
            $table->index(['municipality_id', 'due_at']);
        });

        Schema::create('accountability_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('municipality_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parliamentary_amendment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('accountability_process_id')->constrained()->cascadeOnDelete();
            $table->foreignId('amendment_document_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('category', 30);
            $table->string('title', 180);
            $table->text('description')->nullable();
            $table->boolean('is_required')->default(true);
            $table->string('status', 30)->default('pending');
            $table->text('notes')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(10);
            $table->timestamps();

            $table->index(['accountability_process_id', 'status']);
            $table->index(['municipality_id', 'category']);
        });

        Schema::create('accountability_diligences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('municipality_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parliamentary_amendment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('accountability_process_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('title', 180);
            $table->text('description');
            $table->date('received_at');
            $table->date('due_at');
            $table->string('status', 30)->default('open');
            $table->text('response_notes')->nullable();
            $table->string('response_protocol', 120)->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();

            $table->index(['accountability_process_id', 'status']);
            $table->index(['municipality_id', 'due_at', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accountability_diligences');
        Schema::dropIfExists('accountability_requirements');
        Schema::dropIfExists('accountability_processes');
    }
};
