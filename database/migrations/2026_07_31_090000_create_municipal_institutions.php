<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('municipal_institutions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('municipality_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 40);
            $table->string('name');
            $table->string('legal_name')->nullable();
            $table->string('document', 20)->nullable();
            $table->string('party', 30)->nullable();
            $table->string('department')->nullable();
            $table->string('role_title')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('address')->nullable();
            $table->string('city', 120)->nullable();
            $table->string('state', 2)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['municipality_id', 'type', 'name'], 'municipal_institution_unique_name');
            $table->index(['municipality_id', 'type', 'is_active'], 'municipal_institution_type_index');
            $table->index(['municipality_id', 'document'], 'municipal_institution_document_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('municipal_institutions');
    }
};
