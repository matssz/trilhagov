<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('municipalities', function (Blueprint $table): void {
            $table->boolean('federal_amendments_enabled')->default(false)->after('ibge_code');
            $table->boolean('state_amendments_enabled')->default(false)->after('federal_amendments_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('municipalities', function (Blueprint $table): void {
            $table->dropColumn(['federal_amendments_enabled', 'state_amendments_enabled']);
        });
    }
};
