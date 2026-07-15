<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parliamentary_amendments', function (Blueprint $table) {
            $table->foreignId('responsible_user_id')
                ->nullable()
                ->after('responsible_department')
                ->constrained('users')
                ->nullOnDelete();
            $table->unsignedTinyInteger('risk_score')->default(0)->after('notes');
            $table->string('risk_level', 20)->default('low')->after('risk_score');
            $table->json('risk_reasons')->nullable()->after('risk_level');
            $table->timestamp('risk_calculated_at')->nullable()->after('risk_reasons');

            $table->index(['municipality_id', 'risk_level']);
            $table->index(['municipality_id', 'responsible_user_id']);
        });

        Schema::table('integrity_alerts', function (Blueprint $table) {
            $table->unsignedTinyInteger('escalation_level')->default(0)->after('severity');
        });

        Schema::table('municipality_alert_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('escalation_level_one_days')->default(1);
            $table->unsignedSmallInteger('escalation_level_two_days')->default(7);
            $table->boolean('notify_managers_on_warning')->default(true);
            $table->boolean('notify_editors_on_level_two')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('municipality_alert_settings', function (Blueprint $table) {
            $table->dropColumn([
                'escalation_level_one_days',
                'escalation_level_two_days',
                'notify_managers_on_warning',
                'notify_editors_on_level_two',
            ]);
        });

        Schema::table('integrity_alerts', function (Blueprint $table) {
            $table->dropColumn('escalation_level');
        });

        Schema::table('parliamentary_amendments', function (Blueprint $table) {
            $table->dropIndex(['municipality_id', 'risk_level']);
            $table->dropIndex(['municipality_id', 'responsible_user_id']);
            $table->dropConstrainedForeignId('responsible_user_id');
            $table->dropColumn([
                'risk_score',
                'risk_level',
                'risk_reasons',
                'risk_calculated_at',
            ]);
        });
    }
};
