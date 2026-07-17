<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('amendment_import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('municipality_id')->constrained()->cascadeOnDelete();
            $table->foreignId('amendment_import_batch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parliamentary_amendment_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('row_number');
            $table->string('status', 20);
            $table->json('raw_data');
            $table->json('normalized_data')->nullable();
            $table->json('errors')->nullable();
            $table->timestamps();

            $table->unique(['amendment_import_batch_id', 'row_number'], 'amendment_import_rows_number_unique');
            $table->index(['amendment_import_batch_id', 'status'], 'amendment_import_rows_status_index');
            $table->index(['municipality_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('amendment_import_rows');
    }
};
