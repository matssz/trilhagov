<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parliamentary_amendments', function (Blueprint $table) {
            $table->foreignId('municipal_regulatory_profile_id')
                ->nullable()
                ->after('municipality_id')
                ->constrained()
                ->nullOnDelete();
        });

        Schema::table('technical_impediments', function (Blueprint $table) {
            $table->foreignId('municipal_regulatory_profile_id')
                ->nullable()
                ->after('parliamentary_amendment_id')
                ->constrained()
                ->nullOnDelete();
            $table->date('communication_due_at')->nullable()->after('identified_at');
            $table->date('communicated_at')->nullable()->after('communication_due_at');
            $table->string('communication_reference', 180)->nullable()->after('communicated_at');
        });

        DB::table('municipal_regulatory_profiles')
            ->where('status', 'active')
            ->orderBy('id')
            ->each(function (object $profile): void {
                DB::table('parliamentary_amendments')
                    ->where('municipality_id', $profile->municipality_id)
                    ->where('fiscal_year', $profile->fiscal_year)
                    ->where('government_sphere', 'municipal')
                    ->whereNull('municipal_regulatory_profile_id')
                    ->update(['municipal_regulatory_profile_id' => $profile->id]);
            });

        DB::table('parliamentary_amendments')
            ->whereNotNull('municipal_regulatory_profile_id')
            ->orderBy('id')
            ->each(function (object $amendment): void {
                DB::table('technical_impediments')
                    ->where('parliamentary_amendment_id', $amendment->id)
                    ->whereNull('municipal_regulatory_profile_id')
                    ->update(['municipal_regulatory_profile_id' => $amendment->municipal_regulatory_profile_id]);
            });
    }

    public function down(): void
    {
        Schema::table('technical_impediments', function (Blueprint $table) {
            $table->dropColumn(['communication_due_at', 'communicated_at', 'communication_reference']);
            $table->dropConstrainedForeignId('municipal_regulatory_profile_id');
        });
        Schema::table('parliamentary_amendments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('municipal_regulatory_profile_id');
        });
    }
};
