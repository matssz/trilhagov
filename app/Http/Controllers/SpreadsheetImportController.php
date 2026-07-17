<?php

namespace App\Http\Controllers;

use App\Models\AmendmentImportBatch;
use App\Services\AmendmentSpreadsheetImportService;
use App\Services\AuditTrail;
use App\Services\CurrentMunicipality;
use App\Services\FormSubmission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class SpreadsheetImportController extends Controller
{
    public function index(
        Request $request,
        CurrentMunicipality $currentMunicipality,
        FormSubmission $formSubmission,
    ): View {
        $municipality = $currentMunicipality->get($request);

        return view('spreadsheet-imports.index', [
            'municipality' => $municipality,
            'batches' => $municipality->amendmentImportBatches()
                ->with('user')
                ->latest()
                ->limit(10)
                ->get(),
            'submissionToken' => $formSubmission->issue($request, "spreadsheet-preview-{$municipality->id}"),
        ]);
    }

    public function template(AmendmentSpreadsheetImportService $importService): Response
    {
        return response($importService->templateContents(), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="modelo-importacao-emendas.csv"',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    public function preview(
        Request $request,
        CurrentMunicipality $currentMunicipality,
        FormSubmission $formSubmission,
        AmendmentSpreadsheetImportService $importService,
        AuditTrail $auditTrail,
    ): RedirectResponse {
        $municipality = $currentMunicipality->get($request);
        $validated = $request->validate([
            '_submission_token' => ['required', 'string'],
            'spreadsheet' => ['required', 'file', 'mimes:csv,txt', 'max:2048'],
        ], [
            'spreadsheet.required' => 'Selecione a planilha que será conferida.',
            'spreadsheet.file' => 'O arquivo enviado não pôde ser lido.',
            'spreadsheet.mimes' => 'Envie um arquivo CSV. Baixe o modelo em caso de dúvida.',
            'spreadsheet.max' => 'A planilha deve ter no máximo 2 MB.',
        ]);

        if (! $formSubmission->consume($request, "spreadsheet-preview-{$municipality->id}")) {
            return redirect()->route('spreadsheet-imports.index')
                ->with('warning', 'Esta planilha já foi recebida para conferência.');
        }

        $batch = $importService->createPreview($municipality, $request->user(), $validated['spreadsheet']);
        $auditTrail->recordMunicipalityOperation($request, $municipality, 'amendments_spreadsheet_previewed', [
            'import_batch' => $batch->id,
            'source_file' => $batch->original_name,
            'total_rows' => $batch->total_rows,
            'valid_rows' => $batch->valid_rows,
            'duplicate_rows' => $batch->duplicate_rows,
            'invalid_rows' => $batch->invalid_rows,
        ]);

        return redirect()->route('spreadsheet-imports.show', $batch)
            ->with('status', 'Planilha conferida. Revise o resultado antes de importar.');
    }

    public function show(
        Request $request,
        int $batch,
        CurrentMunicipality $currentMunicipality,
        FormSubmission $formSubmission,
    ): View {
        $municipality = $currentMunicipality->get($request);
        $importBatch = $this->batchForMunicipality($municipality->id, $batch)->load('user');

        return view('spreadsheet-imports.show', [
            'municipality' => $municipality,
            'batch' => $importBatch,
            'rows' => $importBatch->rows()->with('amendment')->orderBy('row_number')->paginate(100),
            'confirmationToken' => $importBatch->status === AmendmentImportBatch::STATUS_PREVIEWED
                ? $formSubmission->issue($request, "spreadsheet-confirm-{$importBatch->id}")
                : null,
        ]);
    }

    public function confirm(
        Request $request,
        int $batch,
        CurrentMunicipality $currentMunicipality,
        FormSubmission $formSubmission,
        AmendmentSpreadsheetImportService $importService,
    ): RedirectResponse {
        $municipality = $currentMunicipality->get($request);
        $importBatch = $this->batchForMunicipality($municipality->id, $batch);
        $request->validate(['_submission_token' => ['required', 'string']]);

        if (! $formSubmission->consume($request, "spreadsheet-confirm-{$importBatch->id}")) {
            return redirect()->route('spreadsheet-imports.show', $importBatch)
                ->with('warning', 'A confirmação deste lote já foi processada.');
        }

        $stats = $importService->confirm($importBatch, $request);

        return redirect()->route('spreadsheet-imports.show', $importBatch)
            ->with('status', sprintf(
                '%d emenda(s) importada(s). %d duplicada(s) e %d inválida(s) permaneceram fora do cadastro.',
                $stats['imported'],
                $stats['duplicates'],
                $stats['invalid'],
            ));
    }

    private function batchForMunicipality(int $municipalityId, int $batchId): AmendmentImportBatch
    {
        return AmendmentImportBatch::query()
            ->where('municipality_id', $municipalityId)
            ->findOrFail($batchId);
    }
}
