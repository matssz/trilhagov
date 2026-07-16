<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('municipalities', function (Blueprint $table) {
            $table->boolean('transparency_enabled')->default(false)->after('ibge_code');
            $table->string('transparency_slug', 100)->nullable()->unique()->after('transparency_enabled');
            $table->timestamp('transparency_updated_at')->nullable()->after('transparency_slug');
        });
    }

    public function down(): void
    {
        Schema::table('municipalities', function (Blueprint $table) {
            $table->dropUnique(['transparency_slug']);
            $table->dropColumn([
                'transparency_enabled',
                'transparency_slug',
                'transparency_updated_at',
            ]);
        });
    }
};
