<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('municipalities', function (Blueprint $table) {
            $table->boolean('health_asps_module_enabled')->default(false)->after('state_amendments_enabled');
            $table->boolean('contracts_module_enabled')->default(false)->after('health_asps_module_enabled');
            $table->boolean('audit_module_enabled')->default(false)->after('contracts_module_enabled');
            $table->boolean('specialized_reports_enabled')->default(false)->after('audit_module_enabled');
            $table->boolean('spreadsheet_import_enabled')->default(false)->after('specialized_reports_enabled');
            $table->boolean('document_checklist_enabled')->default(false)->after('spreadsheet_import_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('municipalities', function (Blueprint $table) {
            $table->dropColumn([
                'health_asps_module_enabled',
                'contracts_module_enabled',
                'audit_module_enabled',
                'specialized_reports_enabled',
                'spreadsheet_import_enabled',
                'document_checklist_enabled',
            ]);
        });
    }
};
