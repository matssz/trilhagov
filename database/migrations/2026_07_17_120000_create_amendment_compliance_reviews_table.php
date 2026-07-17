<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('amendment_compliance_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('municipality_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parliamentary_amendment_id')->constrained()->cascadeOnDelete();
            $table->string('framework_version', 80);
            $table->string('rule_code', 80);
            $table->string('status')->default('pending');
            $table->text('evidence_notes')->nullable();
            $table->foreignId('amendment_document_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['parliamentary_amendment_id', 'framework_version', 'rule_code'],
                'amendment_compliance_review_unique',
            );
            $table->index(['municipality_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('amendment_compliance_reviews');
    }
};
