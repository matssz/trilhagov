<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parliamentary_amendments', function (Blueprint $table) {
            $table->string('expense_destination', 30)->nullable()->after('object');
            $table->string('beneficiary_location')->nullable()->after('responsible_department');
            $table->string('legal_instrument')->nullable()->after('transferegov_code');
            $table->string('administrative_process')->nullable()->after('legal_instrument');
            $table->string('bank_tracking_type', 40)->nullable()->after('administrative_process');
            $table->string('bank_account_number', 100)->nullable()->after('bank_tracking_type');
            $table->string('funding_source_code', 100)->nullable()->after('bank_account_number');
            $table->string('application_code_fixed', 100)->nullable()->after('funding_source_code');
            $table->string('application_code_variable', 100)->nullable()->after('application_code_fixed');
            $table->date('application_deadline')->nullable()->after('execution_deadline');
            $table->text('cancellation_reason')->nullable()->after('status');
            $table->timestamp('cancelled_at')->nullable()->after('cancellation_reason');
        });

        Schema::create('amendment_transparency_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('municipality_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parliamentary_amendment_id')->constrained()->cascadeOnDelete();
            $table->string('event_type', 40);
            $table->string('title');
            $table->text('description');
            $table->json('changes')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['parliamentary_amendment_id', 'occurred_at'], 'transparency_events_amendment_date');
            $table->index(['municipality_id', 'occurred_at'], 'transparency_events_municipality_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('amendment_transparency_events');

        Schema::table('parliamentary_amendments', function (Blueprint $table) {
            $table->dropColumn([
                'expense_destination',
                'beneficiary_location',
                'legal_instrument',
                'administrative_process',
                'bank_tracking_type',
                'bank_account_number',
                'funding_source_code',
                'application_code_fixed',
                'application_code_variable',
                'application_deadline',
                'cancellation_reason',
                'cancelled_at',
            ]);
        });
    }
};
