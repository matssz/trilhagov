<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('mfa_enabled')->default(false)->after('password');
            $table->string('mfa_code_hash')->nullable()->after('mfa_enabled');
            $table->timestamp('mfa_code_expires_at')->nullable()->after('mfa_code_hash');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['mfa_enabled', 'mfa_code_hash', 'mfa_code_expires_at']);
        });
    }
};
