<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_financial_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('municipality_id')->constrained()->cascadeOnDelete();
            $table->foreignId('external_amendment_candidate_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parliamentary_amendment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('initiated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source', 50);
            $table->string('status', 30);
            $table->decimal('official_committed_amount', 15, 2)->nullable();
            $table->decimal('official_ordered_amount', 15, 2)->nullable();
            $table->decimal('official_account_balance', 15, 2)->nullable();
            $table->decimal('local_expected_amount', 15, 2)->nullable();
            $table->decimal('local_received_amount', 15, 2)->nullable();
            $table->decimal('local_committed_amount', 15, 2)->nullable();
            $table->decimal('local_paid_amount', 15, 2)->nullable();
            $table->decimal('local_estimated_balance', 15, 2)->nullable();
            $table->json('differences')->nullable();
            $table->json('official_commitments')->nullable();
            $table->json('official_payment_orders')->nullable();
            $table->json('official_account_data')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('source_updated_at')->nullable();
            $table->timestamp('reconciled_at');
            $table->timestamps();

            $table->index(
                ['external_amendment_candidate_id', 'reconciled_at'],
                'external_financial_candidate_index',
            );
            $table->index(['municipality_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_financial_reconciliations');
    }
};
