<?php

namespace App\Http\Controllers;

use App\Models\AudespRegistrationImportBatch;
use App\Models\Municipality;
use App\Services\AudespRegistrationImportService;
use App\Services\AuditTrail;
use App\Services\CurrentMunicipality;
use App\Services\FormSubmission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class AudespRegistrationImportController extends Controller
{
    public function template(Request $request, CurrentMunicipality $currentMunicipality, AudespRegistrationImportService $importService): Response
    {
        $this->ensureAudespScope($currentMunicipality->get($request));

        return response($importService->templateContents(), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="modelo-cadastros-audesp.csv"',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    public function preview(
        Request $request,
        CurrentMunicipality $currentMunicipality,
        FormSubmission $formSubmission,
        AudespRegistrationImportService $importService,
        AuditTrail $auditTrail,
    ): RedirectResponse {
        $municipality = $currentMunicipality->get($request);
        $this->ensureAudespScope($municipality);
        $validated = $request->validate([
            '_submission_token' => ['required', 'string'],
            'spreadsheet' => ['required', 'file', 'mimes:csv,txt', 'max:2048'],
        ], [
            'spreadsheet.required' => 'Selecione a planilha de cadastros Audesp.',
            'spreadsheet.file' => 'O arquivo enviado não pôde ser lido.',
            'spreadsheet.mimes' => 'Envie um arquivo CSV. Baixe o modelo em caso de dúvida.',
            'spreadsheet.max' => 'A planilha deve ter no máximo 2 MB.',
        ]);

        if (! $formSubmission->consume($request, "audesp-registration-import-preview-{$municipality->id}")) {
            return redirect()->route('audesp-homologations.index')
                ->with('warning', 'Esta planilha já foi recebida para conferência.');
        }

        $batch = $importService->createPreview($municipality, $request->user(), $validated['spreadsheet']);
        $auditTrail->recordMunicipalityOperation($request, $municipality, 'audesp_registrations_spreadsheet_previewed', [
            'import_batch' => $batch->id,
            'source_file' => $batch->original_name,
            'total_rows' => $batch->total_rows,
            'valid_rows' => $batch->valid_rows,
            'duplicate_rows' => $batch->duplicate_rows,
            'invalid_rows' => $batch->invalid_rows,
        ]);

        return redirect()->route('audesp-registration-imports.show', $batch)
            ->with('status', 'Planilha conferida. Revise o resultado antes de importar.');
    }

    public function show(
        Request $request,
        int $batch,
        CurrentMunicipality $currentMunicipality,
        FormSubmission $formSubmission,
    ): View {
        $municipality = $currentMunicipality->get($request);
        $this->ensureAudespScope($municipality);
        $importBatch = $this->batch($municipality->id, $batch)->load('user');

        return view('audesp-registration-imports.show', [
            'municipality' => $municipality,
            'batch' => $importBatch,
            'rows' => $importBatch->rows()->with('amendment')->orderBy('row_number')->paginate(100),
            'confirmationToken' => $importBatch->status === AudespRegistrationImportBatch::STATUS_PREVIEWED
                ? $formSubmission->issue($request, "audesp-registration-import-confirm-{$importBatch->id}")
                : null,
        ]);
    }

    public function confirm(
        Request $request,
        int $batch,
        CurrentMunicipality $currentMunicipality,
        FormSubmission $formSubmission,
        AudespRegistrationImportService $importService,
    ): RedirectResponse {
        $municipality = $currentMunicipality->get($request);
        $this->ensureAudespScope($municipality);
        $importBatch = $this->batch($municipality->id, $batch);
        $request->validate(['_submission_token' => ['required', 'string']]);

        if (! $formSubmission->consume($request, "audesp-registration-import-confirm-{$importBatch->id}")) {
            return redirect()->route('audesp-registration-imports.show', $importBatch)
                ->with('warning', 'A confirmação deste lote já foi processada.');
        }

        $stats = $importService->confirm($importBatch, $request);

        return redirect()->route('audesp-registration-imports.show', $importBatch)
            ->with('status', sprintf(
                '%d cadastro(s) Audesp importado(s). %d duplicado(s) e %d inválido(s) permaneceram fora do cadastro.',
                $stats['imported'],
                $stats['duplicates'],
                $stats['invalid'],
            ));
    }

    private function batch(int $municipalityId, int $batchId): AudespRegistrationImportBatch
    {
        return AudespRegistrationImportBatch::query()
            ->where('municipality_id', $municipalityId)
            ->findOrFail($batchId);
    }

    private function ensureAudespScope(Municipality $municipality): void
    {
        abort_unless($municipality->supportsTcespAudesp(), 404);
    }
}
