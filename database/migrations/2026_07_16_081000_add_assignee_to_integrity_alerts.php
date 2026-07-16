<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integrity_alerts', function (Blueprint $table) {
            $table->foreignId('assigned_user_id')
                ->nullable()
                ->after('parliamentary_amendment_id')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('integrity_alerts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('assigned_user_id');
        });
    }
};
