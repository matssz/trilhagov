<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('municipal_normative_instruments', function (Blueprint $table) {
            $table->foreignId('uploaded_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            $table->string('original_name')->nullable()->after('notes');
            $table->string('storage_path')->nullable()->after('original_name');
            $table->string('mime_type', 150)->nullable()->after('storage_path');
            $table->unsignedBigInteger('size_bytes')->nullable()->after('mime_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('municipal_normative_instruments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('uploaded_by');
            $table->dropColumn(['original_name', 'storage_path', 'mime_type', 'size_bytes']);
        });
    }
};
