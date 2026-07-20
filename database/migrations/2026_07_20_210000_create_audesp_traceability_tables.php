<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audesp_amendment_registrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('municipality_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parliamentary_amendment_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('scope', 1)->default('M');
            $table->unsignedTinyInteger('amendment_type');
            $table->string('legal_basis', 20);
            $table->string('proponent_name', 100);
            $table->string('amendment_number', 30);
            $table->unsignedSmallInteger('amendment_year');
            $table->text('object');
            $table->text('purpose');
            $table->string('government_function', 2);
            $table->json('government_subfunctions');
            $table->string('destination', 1);
            $table->boolean('bank_account_opened');
            $table->string('application_code', 7);
            $table->boolean('prior_balance_reclassified')->default(false);
            $table->string('reclassification_reference', 120)->nullable();
            $table->date('reclassified_at')->nullable();
            $table->timestamp('prepared_at')->nullable();
            $table->timestamp('last_previewed_at')->nullable();
            $table->unsignedInteger('preview_count')->default(0);
            $table->timestamps();

            $table->index(['municipality_id', 'amendment_year']);
            $table->unique(
                ['municipality_id', 'scope', 'amendment_number', 'amendment_year'],
                'audesp_registration_identity_unique',
            );
        });

        Schema::create('financial_liquidations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('municipality_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parliamentary_amendment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('financial_commitment_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('liquidation_reference', 100);
            $table->decimal('amount', 15, 2);
            $table->date('liquidated_at');
            $table->string('supporting_document', 140);
            $table->string('acceptance_reference', 140);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(
                ['financial_commitment_id', 'liquidation_reference'],
                'liquidations_commitment_reference_unique',
            );
            $table->index(['municipality_id', 'liquidated_at']);
            $table->index(['parliamentary_amendment_id', 'liquidated_at']);
        });

        Schema::table('financial_payments', function (Blueprint $table) {
            $table->foreignId('financial_liquidation_id')
                ->nullable()
                ->after('financial_commitment_id')
                ->constrained()
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('financial_payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('financial_liquidation_id');
        });

        Schema::dropIfExists('financial_liquidations');
        Schema::dropIfExists('audesp_amendment_registrations');
    }
};
