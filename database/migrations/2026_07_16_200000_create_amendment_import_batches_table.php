<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('amendment_import_batches', function (Blueprint $table) {
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
    }

    public function down(): void
    {
        Schema::dropIfExists('amendment_import_batches');
    }
};
