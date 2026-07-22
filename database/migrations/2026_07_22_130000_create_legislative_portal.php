<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('municipality_user', function (Blueprint $table) {
            $table->string('legislative_name')->nullable();
            $table->string('legislative_party', 30)->nullable();
            $table->date('legislative_term_start')->nullable();
            $table->date('legislative_term_end')->nullable();
        });

        Schema::table('municipality_invitations', function (Blueprint $table) {
            $table->string('legislative_name')->nullable();
            $table->string('legislative_party', 30)->nullable();
            $table->date('legislative_term_start')->nullable();
            $table->date('legislative_term_end')->nullable();
        });

        Schema::table('municipal_regulatory_profiles', function (Blueprint $table) {
            $table->unsignedSmallInteger('councilor_seats')->nullable()->after('individual_limit_percentage');
        });

        Schema::table('parliamentary_amendments', function (Blueprint $table) {
            $table->boolean('indicated_for_health')->nullable()->after('expense_destination');
        });

        Schema::create('legislative_proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('municipality_id')->constrained()->cascadeOnDelete();
            $table->foreignId('municipal_regulatory_profile_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('submitted_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('parliamentary_amendment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reference', 100);
            $table->unsignedSmallInteger('fiscal_year');
            $table->string('author_name');
            $table->string('author_party', 30);
            $table->text('object');
            $table->text('justification');
            $table->string('priority', 30);
            $table->string('beneficiary_type', 40);
            $table->string('beneficiary_name');
            $table->string('beneficiary_cnpj', 20)->nullable();
            $table->string('beneficiary_location');
            $table->string('expense_destination', 30);
            $table->string('transfer_type', 40);
            $table->boolean('health_related')->default(false);
            $table->string('responsible_department');
            $table->string('program_reference')->nullable();
            $table->string('action_reference')->nullable();
            $table->text('public_need');
            $table->string('target_population')->nullable();
            $table->string('estimated_quantity')->nullable();
            $table->decimal('estimated_amount', 15, 2);
            $table->string('estimate_source');
            $table->date('desired_contract_at')->nullable();
            $table->boolean('third_sector_conflict_declaration')->default(false);
            $table->string('status', 30)->default('draft');
            $table->boolean('review_ppa')->nullable();
            $table->boolean('review_ldo')->nullable();
            $table->boolean('review_loa')->nullable();
            $table->boolean('review_sector_plan')->nullable();
            $table->boolean('review_budget_limit')->nullable();
            $table->boolean('review_health_reserve')->nullable();
            $table->boolean('review_object')->nullable();
            $table->boolean('review_beneficiary')->nullable();
            $table->boolean('review_viability')->nullable();
            $table->text('review_notes')->nullable();
            $table->string('protocol_number')->nullable();
            $table->string('executive_process_number')->nullable();
            $table->string('budget_reservation_number')->nullable();
            $table->decimal('budget_reserved_amount', 15, 2)->nullable();
            $table->date('budget_reserved_at')->nullable();
            $table->text('executive_notes')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->json('protocol_snapshot')->nullable();
            $table->string('protocol_sha256', 64)->nullable();
            $table->timestamps();

            $table->unique(['municipality_id', 'fiscal_year', 'reference'], 'legislative_proposal_reference_unique');
            $table->index(['municipality_id', 'fiscal_year', 'status'], 'legislative_proposal_status_index');
            $table->index(['municipal_regulatory_profile_id', 'author_name'], 'legislative_proposal_author_index');
            $table->index(['submitted_by', 'status'], 'legislative_proposal_submitter_index');
        });

        Schema::create('legislative_proposal_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('municipality_id')->constrained()->cascadeOnDelete();
            $table->foreignId('legislative_proposal_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type', 40);
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30)->nullable();
            $table->text('notes')->nullable();
            $table->json('snapshot')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['legislative_proposal_id', 'created_at'], 'legislative_event_timeline_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legislative_proposal_events');
        Schema::dropIfExists('legislative_proposals');

        Schema::table('parliamentary_amendments', function (Blueprint $table) {
            $table->dropColumn('indicated_for_health');
        });
        Schema::table('municipal_regulatory_profiles', function (Blueprint $table) {
            $table->dropColumn('councilor_seats');
        });
        Schema::table('municipality_invitations', function (Blueprint $table) {
            $table->dropColumn(['legislative_name', 'legislative_party', 'legislative_term_start', 'legislative_term_end']);
        });
        Schema::table('municipality_user', function (Blueprint $table) {
            $table->dropColumn(['legislative_name', 'legislative_party', 'legislative_term_start', 'legislative_term_end']);
        });
    }
};
