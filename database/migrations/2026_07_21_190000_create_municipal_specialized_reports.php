<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('municipal_specialized_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('municipality_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('reference')->unique();
            $table->string('report_type', 30);
            $table->unsignedSmallInteger('fiscal_year');
            $table->unsignedTinyInteger('reference_month');
            $table->unsignedSmallInteger('version')->default(1);
            $table->string('status', 20)->default('draft');
            $table->json('parameters')->nullable();
            $table->json('snapshot');
            $table->char('snapshot_sha256', 64);
            $table->text('management_notes')->nullable();
            $table->timestamp('data_generated_at');
            $table->timestamp('issued_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['municipality_id', 'report_type', 'fiscal_year', 'reference_month', 'version'],
                'municipal_specialized_report_period_version_unique',
            );
            $table->index(['municipality_id', 'report_type', 'status'], 'municipal_specialized_report_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('municipal_specialized_reports');
    }
};
