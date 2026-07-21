<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('municipal_audit_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('municipality_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('fiscal_year');
            $table->unsignedSmallInteger('version');
            $table->string('status', 20)->default('draft');
            $table->string('title', 220);
            $table->text('objective');
            $table->text('methodology');
            $table->text('risk_criteria');
            $table->text('normative_basis');
            $table->string('coordination_unit', 180);
            $table->date('planned_start_at');
            $table->date('planned_end_at');
            $table->text('management_notes')->nullable();
            $table->json('snapshot')->nullable();
            $table->char('snapshot_sha256', 64)->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamps();

            $table->unique(['municipality_id', 'fiscal_year', 'version'], 'municipal_audit_plan_version_unique');
            $table->index(['municipality_id', 'fiscal_year', 'status']);
        });

        Schema::create('municipal_audit_plan_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('municipality_id')->constrained()->cascadeOnDelete();
            $table->foreignId('municipal_audit_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parliamentary_amendment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assigned_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('phase', 30);
            $table->string('priority', 20);
            $table->string('frequency', 30);
            $table->string('status', 30)->default('planned');
            $table->date('planned_at');
            $table->text('scope_notes');
            $table->text('status_notes')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['municipal_audit_plan_id', 'parliamentary_amendment_id', 'phase'], 'municipal_audit_plan_item_unique');
            $table->index(['municipality_id', 'status', 'planned_at'], 'municipal_audit_plan_item_schedule_index');
        });

        Schema::create('municipal_audit_plan_item_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('municipality_id')->constrained()->cascadeOnDelete();
            $table->foreignId('municipal_audit_plan_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('actor_name');
            $table->string('event_type', 30);
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->text('description');
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['municipal_audit_plan_item_id', 'created_at'], 'municipal_audit_plan_item_event_time_index');
        });

        Schema::table('municipal_internal_control_reviews', function (Blueprint $table) {
            $table->foreignId('municipal_audit_plan_item_id')
                ->nullable()
                ->after('municipal_governance_report_id')
                ->constrained()
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('municipal_internal_control_reviews', function (Blueprint $table) {
            $table->dropConstrainedForeignId('municipal_audit_plan_item_id');
        });
        Schema::dropIfExists('municipal_audit_plan_item_events');
        Schema::dropIfExists('municipal_audit_plan_items');
        Schema::dropIfExists('municipal_audit_plans');
    }
};
