<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audesp_registration_import_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('municipality_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('original_name', 255);
            $table->string('file_hash', 64);
            $table->string('status', 20)->default('previewed');
            $table->unsignedSmallInteger('total_rows')->default(0);
            $table->unsignedSmallInteger('valid_rows')->default(0);
            $table->unsignedSmallInteger('duplicate_rows')->default(0);
            $table->unsignedSmallInteger('invalid_rows')->default(0);
            $table->unsignedSmallInteger('imported_rows')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['municipality_id', 'created_at']);
            $table->index(['municipality_id', 'status']);
        });

        Schema::create('audesp_registration_import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('municipality_id')->constrained()->cascadeOnDelete();
            $table->foreignId('audesp_registration_import_batch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parliamentary_amendment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('audesp_amendment_registration_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('row_number');
            $table->string('status', 20);
            $table->json('raw_data');
            $table->json('normalized_data')->nullable();
            $table->json('errors')->nullable();
            $table->timestamps();

            $table->unique(['audesp_registration_import_batch_id', 'row_number'], 'audesp_reg_import_rows_number_unique');
            $table->index(['audesp_registration_import_batch_id', 'status'], 'audesp_reg_import_rows_status_index');
            $table->index(['municipality_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audesp_registration_import_rows');
        Schema::dropIfExists('audesp_registration_import_batches');
    }
};
