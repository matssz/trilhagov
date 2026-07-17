<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->json('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

        Schema::table('municipality_user', function (Blueprint $table) {
            $table->boolean('notify_in_app')->default(true);
            $table->boolean('notify_email')->default(false);
            $table->boolean('notify_deadlines')->default(true);
            $table->boolean('notify_integrity')->default(true);
        });

        Schema::create('municipality_alert_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('municipality_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('deadline_warning_days')->default(30);
            $table->unsignedSmallInteger('deadline_critical_days')->default(7);
            $table->unsignedSmallInteger('overdue_repeat_days')->default(7);
            $table->timestamps();
        });

        Schema::create('integrity_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('municipality_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parliamentary_amendment_id')->constrained()->cascadeOnDelete();
            $table->string('alert_key');
            $table->string('category', 40);
            $table->string('severity', 20);
            $table->string('title');
            $table->text('message');
            $table->date('due_at')->nullable();
            $table->string('status', 20)->default('open');
            $table->timestamp('detected_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['parliamentary_amendment_id', 'alert_key'],
                'integrity_alert_amendment_key_unique',
            );
            $table->index(['municipality_id', 'status', 'severity']);
        });

        Schema::create('alert_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('integrity_alert_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 30);
            $table->string('cycle_key', 80);
            $table->timestamp('delivered_at');

            $table->unique(
                ['integrity_alert_id', 'user_id', 'channel', 'cycle_key'],
                'alert_delivery_cycle_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_deliveries');
        Schema::dropIfExists('integrity_alerts');
        Schema::dropIfExists('municipality_alert_settings');

        Schema::table('municipality_user', function (Blueprint $table) {
            $table->dropColumn([
                'notify_in_app',
                'notify_email',
                'notify_deadlines',
                'notify_integrity',
            ]);
        });

        Schema::dropIfExists('notifications');
    }
};
