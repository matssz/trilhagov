<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('health_asps_assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('municipality_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parliamentary_amendment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('evidence_document_id')->nullable()->constrained('amendment_documents')->nullOnDelete();
            $table->foreignId('supersedes_id')->nullable()->constrained('health_asps_assessments')->nullOnDelete();
            $table->uuid('reference')->unique();
            $table->unsignedSmallInteger('fiscal_year');
            $table->unsignedSmallInteger('version')->default(1);
            $table->string('status', 30)->default('draft');
            $table->string('conclusion', 30)->nullable();
            $table->string('asps_category', 60)->nullable();
            $table->json('criteria');
            $table->json('exclusion_reasons')->nullable();
            $table->string('budget_function', 2)->nullable();
            $table->string('budget_subfunction', 3)->nullable();
            $table->string('funding_source_code', 100)->nullable();
            $table->string('application_code', 100)->nullable();
            $table->string('health_fund_reference', 180)->nullable();
            $table->string('health_plan_reference', 500)->nullable();
            $table->text('technical_justification')->nullable();
            $table->text('reviewer_notes')->nullable();
            $table->json('snapshot')->nullable();
            $table->char('snapshot_sha256', 64)->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->unique(['parliamentary_amendment_id', 'version'], 'health_asps_amendment_version_unique');
            $table->index(['municipality_id', 'fiscal_year', 'status'], 'health_asps_municipal_status_index');
            $table->index(['municipality_id', 'conclusion'], 'health_asps_municipal_conclusion_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('health_asps_assessments');
    }
};
