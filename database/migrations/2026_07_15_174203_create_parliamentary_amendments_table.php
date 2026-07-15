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
        Schema::create('parliamentary_amendments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('municipality_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('reference');
            $table->unsignedSmallInteger('fiscal_year');
            $table->string('government_sphere');
            $table->string('authorship_type');
            $table->string('transfer_type');
            $table->string('author_name');
            $table->string('author_party', 20)->nullable();
            $table->text('object');
            $table->string('responsible_department');
            $table->string('transferegov_code')->nullable();
            $table->decimal('expected_amount', 15, 2);
            $table->decimal('received_amount', 15, 2)->nullable();
            $table->string('status')->default('identified');
            $table->date('indicated_at')->nullable();
            $table->date('received_at')->nullable();
            $table->date('communication_deadline')->nullable();
            $table->date('communication_completed_at')->nullable();
            $table->date('execution_deadline')->nullable();
            $table->date('execution_completed_at')->nullable();
            $table->date('accountability_deadline')->nullable();
            $table->date('accountability_completed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(
                ['municipality_id', 'government_sphere', 'fiscal_year', 'reference'],
                'amendments_reference_unique',
            );
            $table->index(['municipality_id', 'status']);
            $table->index(['municipality_id', 'fiscal_year']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('parliamentary_amendments');
    }
};
