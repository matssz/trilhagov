<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('municipal_regulatory_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('municipality_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('activated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('audesp_responsible_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('fiscal_year');
            $table->unsignedSmallInteger('version')->default(1);
            $table->string('status')->default('draft');
            $table->string('regime_status')->default('under_review');
            $table->decimal('previous_year_rcl', 18, 2)->nullable();
            $table->decimal('individual_limit_percentage', 7, 4)->nullable();
            $table->decimal('health_reserve_percentage', 7, 4)->nullable();
            $table->string('health_reserve_method')->nullable();
            $table->unsignedSmallInteger('amendments_per_councilor_limit')->nullable();
            $table->decimal('minimum_amendment_amount', 15, 2)->nullable();
            $table->boolean('generic_amendments_prohibited')->nullable();
            $table->boolean('prior_technical_review_required')->nullable();
            $table->boolean('work_plan_required')->nullable();
            $table->boolean('pca_check_required')->nullable();
            $table->unsignedSmallInteger('impediment_notice_days')->nullable();
            $table->unsignedSmallInteger('impediment_correction_days')->nullable();
            $table->unsignedSmallInteger('publication_business_days')->nullable();
            $table->unsignedSmallInteger('document_retention_years')->nullable();
            $table->string('bank_traceability_rule')->nullable();
            $table->string('audesp_registration_status')->default('not_started');
            $table->string('legal_review_responsible')->nullable();
            $table->string('legal_review_reference')->nullable();
            $table->date('legal_reviewed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();

            $table->unique(['municipality_id', 'fiscal_year', 'version'], 'municipal_rules_year_version_unique');
            $table->index(['municipality_id', 'status', 'fiscal_year'], 'municipal_rules_status_year_index');
        });

        Schema::create('municipal_normative_instruments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('municipality_id')->constrained()->cascadeOnDelete();
            $table->foreignId('municipal_regulatory_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('type');
            $table->string('title');
            $table->string('reference', 180);
            $table->string('url', 1200)->nullable();
            $table->date('enacted_at')->nullable();
            $table->date('effective_from')->nullable();
            $table->date('effective_until')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['municipal_regulatory_profile_id', 'type'], 'municipal_instruments_profile_type_index');
            $table->index(['municipality_id', 'type'], 'municipal_instruments_municipality_type_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('municipal_normative_instruments');
        Schema::dropIfExists('municipal_regulatory_profiles');
    }
};
