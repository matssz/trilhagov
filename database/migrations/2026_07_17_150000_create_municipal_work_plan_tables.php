<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('municipal_work_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('municipality_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parliamentary_amendment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('draft');
            $table->unsignedSmallInteger('revision_number')->default(0);
            $table->string('beneficiary_type')->nullable();
            $table->string('beneficiary_name')->nullable();
            $table->string('beneficiary_cnpj', 14)->nullable();
            $table->string('beneficiary_contact')->nullable();
            $table->text('object_description')->nullable();
            $table->text('public_need')->nullable();
            $table->text('physical_target')->nullable();
            $table->text('finalistic_target')->nullable();
            $table->string('budget_program')->nullable();
            $table->string('budget_action')->nullable();
            $table->text('application_plan')->nullable();
            $table->text('cost_memory')->nullable();
            $table->text('maintenance_plan')->nullable();
            $table->boolean('health_related')->default(false);
            $table->boolean('health_reserve_verified')->default(false);
            $table->boolean('includes_engineering')->default(false);
            $table->string('engineering_project_status')->default('not_applicable');
            $table->string('environmental_license_status')->default('not_applicable');
            $table->string('pca_status')->default('not_checked');
            $table->date('planned_start_at')->nullable();
            $table->date('planned_end_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->unique('parliamentary_amendment_id');
            $table->index(['municipality_id', 'status']);
        });

        Schema::create('municipal_work_plan_stages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('municipality_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parliamentary_amendment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('municipal_work_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('title');
            $table->text('physical_delivery');
            $table->decimal('planned_amount', 15, 2);
            $table->date('planned_start_at');
            $table->date('planned_end_at');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['municipal_work_plan_id', 'sort_order']);
        });

        Schema::create('municipal_admissibility_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('municipality_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parliamentary_amendment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('municipal_work_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reviewed_by')->constrained('users')->restrictOnDelete();
            $table->unsignedSmallInteger('plan_revision');
            $table->string('conclusion');
            $table->json('criteria');
            $table->text('rationale');
            $table->text('corrections_requested')->nullable();
            $table->json('plan_snapshot');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(
                ['municipal_work_plan_id', 'plan_revision'],
                'municipal_admissibility_plan_revision_unique',
            );
            $table->index(['municipality_id', 'conclusion']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('municipal_admissibility_reviews');
        Schema::dropIfExists('municipal_work_plan_stages');
        Schema::dropIfExists('municipal_work_plans');
    }
};
