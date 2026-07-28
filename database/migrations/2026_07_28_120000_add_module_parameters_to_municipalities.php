<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach ([
            'health_asps_module_enabled',
            'contracts_module_enabled',
            'audit_module_enabled',
            'specialized_reports_enabled',
            'spreadsheet_import_enabled',
            'document_checklist_enabled',
        ] as $column) {
            if (! Schema::hasColumn('municipalities', $column)) {
                Schema::table('municipalities', function (Blueprint $table) use ($column): void {
                    $table->boolean($column)->default(false);
                });
            }
        }
    }

    public function down(): void
    {
        foreach ([
            'health_asps_module_enabled',
            'contracts_module_enabled',
            'audit_module_enabled',
            'specialized_reports_enabled',
            'spreadsheet_import_enabled',
            'document_checklist_enabled',
        ] as $column) {
            if (Schema::hasColumn('municipalities', $column)) {
                Schema::table('municipalities', function (Blueprint $table) use ($column): void {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
