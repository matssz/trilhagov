<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audesp_homologation_batches', function (Blueprint $table) {
            $table->string('source_document_type', 40)
                ->default('amendment_registry')
                ->after('schema_version');
            $table->index(
                ['municipality_id', 'source_document_type', 'fiscal_year', 'reference_month'],
                'audesp_batch_document_period_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('audesp_homologation_batches', function (Blueprint $table) {
            $table->dropIndex('audesp_batch_document_period_index');
            $table->dropColumn('source_document_type');
        });
    }
};
