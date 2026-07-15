<?php

namespace App\Http\Controllers;

use App\Models\AmendmentDocument;
use App\Models\DocumentType;
use App\Services\AuditTrail;
use App\Services\CurrentMunicipality;
use App\Services\FormSubmission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class AmendmentDocumentController extends Controller
{
    public function store(
        Request $request,
        int $emenda,
        CurrentMunicipality $currentMunicipality,
        FormSubmission $formSubmission,
        AuditTrail $auditTrail,
    ): RedirectResponse {
        $municipality = $currentMunicipality->get($request);
        $amendment = $municipality->amendments()->findOrFail($emenda);
        $validated = $request->validate([
            '_submission_token' => ['required', 'string'],
            'document_type_id' => [
                'required',
                'integer',
                Rule::exists('document_types', 'id')->where(fn ($query) => $query
                    ->where('municipality_id', $municipality->id)
                    ->where('is_active', true)),
            ],
            'document' => [
                'required',
                File::types(['pdf', 'jpg', 'jpeg', 'png', 'xls', 'xlsx', 'csv', 'doc', 'docx'])
                    ->max('10mb'),
            ],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        if (! $formSubmission->consume($request, "amendment-document-upload-{$amendment->id}")) {
            return back()->with('warning', 'Este documento já foi enviado.');
        }

        $file = $request->file('document');
        $extension = $file->extension() ?: strtolower($file->getClientOriginalExtension());
        $directory = "documents/{$municipality->id}/{$amendment->id}";
        $filename = Str::uuid().'.'.$extension;
        $storagePath = Storage::disk('local')->putFileAs($directory, $file, $filename);

        if (! $storagePath) {
            throw ValidationException::withMessages([
                'document' => 'Não foi possível armazenar o arquivo. Tente novamente.',
            ]);
        }

        $originalName = Str::of(basename($file->getClientOriginalName()))
            ->replaceMatches('/[\x00-\x1F\x7F]/u', '')
            ->limit(255, '')
            ->toString();

        try {
            $document = DB::transaction(function () use ($request, $validated, $municipality, $amendment, $file, $storagePath, $originalName, $auditTrail): AmendmentDocument {
                DocumentType::query()
                    ->where('municipality_id', $municipality->id)
                    ->lockForUpdate()
                    ->findOrFail($validated['document_type_id']);
                $latestVersion = AmendmentDocument::query()
                    ->where('parliamentary_amendment_id', $amendment->id)
                    ->where('document_type_id', $validated['document_type_id'])
                    ->lockForUpdate()
                    ->max('version');
                $document = $amendment->documents()->create([
                    'municipality_id' => $municipality->id,
                    'document_type_id' => $validated['document_type_id'],
                    'uploaded_by' => $request->user()->id,
                    'uploader_name' => $request->user()->name,
                    'original_name' => $originalName,
                    'storage_path' => $storagePath,
                    'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
                    'size_bytes' => $file->getSize(),
                    'version' => ((int) $latestVersion) + 1,
                    'notes' => filled($validated['notes'] ?? null) ? trim($validated['notes']) : null,
                ]);
                $auditTrail->recordDocumentUpload($request, $amendment, $document->load('documentType'));

                return $document;
            });
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($storagePath);

            throw $exception;
        }

        return back()->with('status', "Documento anexado como versão {$document->version}.");
    }

    public function download(
        Request $request,
        int $emenda,
        int $documento,
        CurrentMunicipality $currentMunicipality,
    ): StreamedResponse {
        $municipality = $currentMunicipality->get($request);
        $amendment = $municipality->amendments()->findOrFail($emenda);
        $document = $amendment->documents()
            ->where('municipality_id', $municipality->id)
            ->findOrFail($documento);

        abort_unless(Storage::disk('local')->exists($document->storage_path), 404);

        return Storage::disk('local')->download(
            $document->storage_path,
            $document->original_name,
            ['Content-Type' => $document->mime_type],
        );
    }
}
