<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('municipal_report_dispatches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('municipality_id')->constrained()->cascadeOnDelete();
            $table->foreignId('municipal_governance_report_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('responsible_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('retry_of_id')->nullable()->constrained('municipal_report_dispatches')->nullOnDelete();
            $table->uuid('reference')->unique();
            $table->string('recipient_type', 40);
            $table->string('recipient_name', 180);
            $table->string('recipient_unit', 180)->nullable();
            $table->string('recipient_email', 180)->nullable();
            $table->string('delivery_method', 40);
            $table->string('legal_basis', 500)->nullable();
            $table->date('due_at');
            $table->string('status', 30)->default('prepared');
            $table->string('official_document_number', 120)->nullable();
            $table->string('protocol_number', 160)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['municipality_id', 'status', 'due_at'], 'municipal_report_dispatch_status_due_index');
            $table->index(['municipal_governance_report_id', 'recipient_type'], 'municipal_report_dispatch_recipient_index');
        });

        Schema::create('municipal_report_dispatch_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('municipality_id')->constrained()->cascadeOnDelete();
            $table->foreignId('municipal_report_dispatch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('type', 40);
            $table->timestamp('occurred_at');
            $table->string('protocol_number', 160)->nullable();
            $table->text('message')->nullable();
            $table->string('evidence_original_name', 255)->nullable();
            $table->string('evidence_storage_path', 500)->nullable();
            $table->string('evidence_mime_type', 120)->nullable();
            $table->unsignedBigInteger('evidence_size_bytes')->nullable();
            $table->string('evidence_sha256', 64)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['municipal_report_dispatch_id', 'occurred_at'], 'municipal_report_dispatch_event_date_index');
        });

        Schema::create('municipal_report_dispatch_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('municipal_report_dispatch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 30);
            $table->string('cycle_key', 100);
            $table->timestamp('delivered_at');

            $table->unique(
                ['municipal_report_dispatch_id', 'user_id', 'channel', 'cycle_key'],
                'municipal_report_dispatch_delivery_cycle_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('municipal_report_dispatch_deliveries');
        Schema::dropIfExists('municipal_report_dispatch_events');
        Schema::dropIfExists('municipal_report_dispatches');
    }
};
