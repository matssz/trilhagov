<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('municipality_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('description', 500)->nullable();
            $table->boolean('is_required')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['municipality_id', 'name']);
            $table->index(['municipality_id', 'is_active', 'sort_order']);
        });

        Schema::create('amendment_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('municipality_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parliamentary_amendment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_type_id')->constrained()->restrictOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('uploader_name');
            $table->string('original_name');
            $table->string('storage_path')->unique();
            $table->string('mime_type', 150);
            $table->unsignedBigInteger('size_bytes');
            $table->unsignedInteger('version');
            $table->string('notes', 500)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(
                ['parliamentary_amendment_id', 'document_type_id', 'version'],
                'amendment_document_version_unique',
            );
            $table->index(['municipality_id', 'parliamentary_amendment_id']);
        });

        $defaults = [
            ['name' => 'Plano de trabalho', 'description' => 'Planejamento, metas e aplicação prevista para o recurso.'],
            ['name' => 'Comprovante de recebimento', 'description' => 'Extrato ou documento que evidencia o ingresso do recurso.'],
            ['name' => 'Documento de contratação', 'description' => 'Processo, contrato ou instrumento relacionado à contratação.'],
            ['name' => 'Evidência de execução', 'description' => 'Comprovação da entrega física ou da execução do objeto.'],
            ['name' => 'Relatório de prestação de contas', 'description' => 'Relatório ou documento consolidado da prestação de contas.'],
        ];
        $now = now();

        foreach (DB::table('municipalities')->pluck('id') as $municipalityId) {
            foreach ($defaults as $position => $default) {
                DB::table('document_types')->insert([
                    'municipality_id' => $municipalityId,
                    'created_by' => null,
                    'name' => $default['name'],
                    'description' => $default['description'],
                    'is_required' => false,
                    'is_active' => true,
                    'sort_order' => ($position + 1) * 10,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('amendment_documents');
        Schema::dropIfExists('document_types');
    }
};
