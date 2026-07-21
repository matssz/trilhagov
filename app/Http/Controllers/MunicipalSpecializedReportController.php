<?php

namespace App\Http\Controllers;

use App\Models\Municipality;
use App\Models\MunicipalSpecializedReport;
use App\Services\AuditTrail;
use App\Services\CurrentMunicipality;
use App\Services\FormSubmission;
use App\Services\MunicipalSpecializedReportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MunicipalSpecializedReportController extends Controller
{
    public function index(Request $request, CurrentMunicipality $currentMunicipality, FormSubmission $formSubmission): View
    {
        $municipality = $currentMunicipality->get($request);
        $this->ensureScope($municipality);
        $canEdit = $request->user()->canEditMunicipality($municipality->id);

        return view('specialized-reports.index', [
            'municipality' => $municipality,
            'reports' => $municipality->specializedReports()
                ->with(['creator:id,name', 'issuer:id,name'])
                ->latest('fiscal_year')->latest('reference_month')->latest('version')
                ->paginate(18)->withQueryString(),
            'types' => MunicipalSpecializedReport::types(),
            'descriptions' => MunicipalSpecializedReport::typeDescriptions(),
            'canEdit' => $canEdit,
            'createToken' => $canEdit ? $formSubmission->issue($request, 'specialized-report-create') : null,
        ]);
    }

    public function store(
        Request $request,
        CurrentMunicipality $currentMunicipality,
        FormSubmission $formSubmission,
        MunicipalSpecializedReportService $service,
        AuditTrail $auditTrail,
    ): RedirectResponse {
        $municipality = $currentMunicipality->get($request);
        $this->ensureScope($municipality);
        $validated = $request->validate([
            '_submission_token' => ['required', 'string'],
            'report_type' => ['required', Rule::in(array_keys(MunicipalSpecializedReport::types()))],
            'fiscal_year' => ['required', 'integer', Rule::in([2026])],
            'reference_month' => ['required', 'integer', 'between:1,12'],
            'difference_threshold' => ['nullable', 'integer', 'between:5,100'],
        ], [
            'report_type.in' => 'Selecione um modelo de relatório disponível.',
            'fiscal_year.in' => 'A metodologia atual foi validada para o exercício de 2026.',
            'reference_month.between' => 'Informe uma competência entre 01 e 12.',
            'difference_threshold.between' => 'A tolerância deve ficar entre 5 e 100 pontos percentuais.',
        ]);
        if (! $formSubmission->consume($request, 'specialized-report-create')) {
            return back()->with('warning', 'Esta solicitação já foi processada. Atualize a página para preparar outro relatório.');
        }

        $existingDraft = $municipality->specializedReports()
            ->where('report_type', $validated['report_type'])
            ->where('fiscal_year', $validated['fiscal_year'])
            ->where('reference_month', $validated['reference_month'])
            ->where('status', MunicipalSpecializedReport::STATUS_DRAFT)
            ->first();
        if ($existingDraft) {
            return redirect()->route('specialized-reports.show', $existingDraft)
                ->with('warning', 'Já existe uma versão em preparação deste relatório para a competência.');
        }

        $parameters = $validated['report_type'] === MunicipalSpecializedReport::TYPE_DIVERGENCES
            ? ['difference_threshold' => (int) ($validated['difference_threshold'] ?? 20)]
            : [];
        $snapshot = $service->build($municipality, $validated['report_type'], (int) $validated['fiscal_year'], (int) $validated['reference_month'], $parameters);

        try {
            $report = DB::transaction(function () use ($request, $municipality, $validated, $parameters, $snapshot, $service, $auditTrail): MunicipalSpecializedReport {
                $latest = $municipality->specializedReports()
                    ->where('report_type', $validated['report_type'])
                    ->where('fiscal_year', $validated['fiscal_year'])
                    ->where('reference_month', $validated['reference_month'])
                    ->latest('version')->lockForUpdate()->first(['version']);
                $report = $municipality->specializedReports()->create([
                    'created_by' => $request->user()->id,
                    'updated_by' => $request->user()->id,
                    'reference' => (string) Str::uuid(),
                    'report_type' => $validated['report_type'],
                    'fiscal_year' => $validated['fiscal_year'],
                    'reference_month' => $validated['reference_month'],
                    'version' => ((int) ($latest?->version ?? 0)) + 1,
                    'status' => MunicipalSpecializedReport::STATUS_DRAFT,
                    'parameters' => $parameters,
                    'snapshot' => $snapshot,
                    'snapshot_sha256' => $service->hash($snapshot),
                    'data_generated_at' => now(),
                ]);
                $auditTrail->recordMunicipalityOperation($request, $municipality, 'specialized_report_created', [
                    'report' => $report->code(), 'type' => $report->report_type, 'snapshot_sha256' => $report->snapshot_sha256,
                ]);

                return $report;
            });
        } catch (QueryException $exception) {
            if (! in_array((string) $exception->getCode(), ['23000', '23505'], true)) {
                throw $exception;
            }
            $report = $municipality->specializedReports()
                ->where('report_type', $validated['report_type'])
                ->where('fiscal_year', $validated['fiscal_year'])
                ->where('reference_month', $validated['reference_month'])
                ->latest('version')->firstOrFail();

            return redirect()->route('specialized-reports.show', $report)
                ->with('warning', 'O relatório foi preparado por outro integrante enquanto a solicitação era processada.');
        }

        return redirect()->route('specialized-reports.show', $report)
            ->with('status', 'Relatório especializado preparado com os dados atuais do Município.');
    }

    public function show(Request $request, int $report, CurrentMunicipality $currentMunicipality, FormSubmission $formSubmission): View
    {
        $municipality = $currentMunicipality->get($request);
        $this->ensureScope($municipality);
        $report = $this->report($municipality, $report);
        $canEdit = $request->user()->canEditMunicipality($municipality->id) && $report->isDraft();
        $canIssue = $request->user()->roleForMunicipality($municipality->id) === 'manager' && $report->isDraft();

        return view('specialized-reports.show', [
            'municipality' => $municipality,
            'report' => $report,
            'snapshot' => $report->snapshot,
            'canEdit' => $canEdit,
            'canIssue' => $canIssue,
            'refreshToken' => $canEdit ? $formSubmission->issue($request, "specialized-report-refresh-{$report->id}") : null,
            'issueToken' => $canIssue ? $formSubmission->issue($request, "specialized-report-issue-{$report->id}") : null,
        ]);
    }

    public function refresh(
        Request $request,
        int $report,
        CurrentMunicipality $currentMunicipality,
        FormSubmission $formSubmission,
        MunicipalSpecializedReportService $service,
        AuditTrail $auditTrail,
    ): RedirectResponse {
        $municipality = $currentMunicipality->get($request);
        $this->ensureScope($municipality);
        $report = $this->report($municipality, $report);
        abort_unless($report->isDraft(), 409, 'Este relatório já foi emitido e não pode ser atualizado.');
        $validated = $request->validate([
            '_submission_token' => ['required', 'string'],
            'management_notes' => ['nullable', 'string', 'max:4000'],
        ]);
        if (! $formSubmission->consume($request, "specialized-report-refresh-{$report->id}")) {
            return back()->with('warning', 'A atualização já foi processada.');
        }

        $snapshot = $service->build($municipality, $report->report_type, $report->fiscal_year, $report->reference_month, $report->parameters ?? []);
        $oldHash = $report->snapshot_sha256;
        $report->update([
            'updated_by' => $request->user()->id,
            'snapshot' => $snapshot,
            'snapshot_sha256' => $service->hash($snapshot),
            'management_notes' => filled($validated['management_notes'] ?? null) ? trim($validated['management_notes']) : null,
            'data_generated_at' => now(),
        ]);
        $auditTrail->recordMunicipalityOperation($request, $municipality, 'specialized_report_refreshed', [
            'report' => $report->code(), 'snapshot_sha256' => $report->snapshot_sha256,
        ], ['snapshot_sha256' => $oldHash]);

        return back()->with('status', 'Dados recalculados. Revise os apontamentos antes da emissão.');
    }

    public function issue(Request $request, int $report, CurrentMunicipality $currentMunicipality, FormSubmission $formSubmission, AuditTrail $auditTrail): RedirectResponse
    {
        $municipality = $currentMunicipality->get($request);
        $this->ensureScope($municipality);
        $report = $this->report($municipality, $report);
        abort_unless($report->isDraft(), 409, 'Este relatório já foi emitido.');
        $request->validate([
            '_submission_token' => ['required', 'string'],
            'confirm_snapshot' => ['accepted'],
        ], ['confirm_snapshot.accepted' => 'Confirme que os dados e ressalvas foram revisados.']);
        if (! $formSubmission->consume($request, "specialized-report-issue-{$report->id}")) {
            return back()->with('warning', 'A emissão já foi processada.');
        }
        $report->update(['status' => MunicipalSpecializedReport::STATUS_ISSUED, 'issued_by' => $request->user()->id, 'issued_at' => now()]);
        $auditTrail->recordMunicipalityOperation($request, $municipality, 'specialized_report_issued', [
            'report' => $report->code(), 'reference' => $report->reference, 'snapshot_sha256' => $report->snapshot_sha256,
        ]);

        return back()->with('status', 'Relatório emitido e preservado para auditoria.');
    }

    public function pdf(Request $request, int $report, CurrentMunicipality $currentMunicipality, AuditTrail $auditTrail): Response
    {
        $municipality = $currentMunicipality->get($request);
        $this->ensureScope($municipality);
        $report = $this->report($municipality, $report);
        $auditTrail->recordMunicipalityOperation($request, $municipality, 'specialized_report_downloaded', [
            'report' => $report->code(), 'format' => 'PDF', 'snapshot_sha256' => $report->snapshot_sha256,
        ]);

        return Pdf::loadView('specialized-reports.pdf', ['report' => $report, 'snapshot' => $report->snapshot])
            ->setPaper('a4', 'landscape')->download(Str::lower($report->code()).'.pdf');
    }

    public function csv(Request $request, int $report, CurrentMunicipality $currentMunicipality, AuditTrail $auditTrail): StreamedResponse
    {
        $municipality = $currentMunicipality->get($request);
        $this->ensureScope($municipality);
        $report = $this->report($municipality, $report);
        $auditTrail->recordMunicipalityOperation($request, $municipality, 'specialized_report_downloaded', [
            'report' => $report->code(), 'format' => 'CSV', 'snapshot_sha256' => $report->snapshot_sha256,
        ]);

        return response()->streamDownload(function () use ($report): void {
            $output = fopen('php://output', 'wb');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, ['Relatório', $report->code(), 'Tipo', $report->typeLabel(), 'Hash SHA-256', $report->snapshot_sha256], ';');
            fputcsv($output, ['Referência', 'Autor', 'Objeto', 'Órgão', 'Previsto', 'Recebido', 'Empenhado', 'Liquidado', 'Pago', 'Execução física', 'Situação / apontamentos'], ';');
            foreach ($report->snapshot['rows'] ?? [] as $row) {
                $notes = collect($row['divergences'] ?? [])->pluck('label')->implode(' | ');
                fputcsv($output, [
                    $this->safe($row['reference'] ?? ''), $this->safe($row['author'] ?? ''), $this->safe($row['object'] ?? ''),
                    $this->safe($row['department'] ?? ''), $this->money($row['expected'] ?? 0), $this->money($row['received'] ?? 0),
                    $this->money($row['committed'] ?? 0), $this->money($row['liquidated'] ?? 0), $this->money($row['paid'] ?? 0),
                    ($row['physical_execution'] ?? 0).'%', $notes ?: ($row['status_label'] ?? ''),
                ], ';');
            }
            fclose($output);
        }, Str::lower($report->code()).'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function report(Municipality $municipality, int $id): MunicipalSpecializedReport
    {
        return $municipality->specializedReports()->with(['creator:id,name', 'updater:id,name', 'issuer:id,name'])->findOrFail($id);
    }

    private function ensureScope(Municipality $municipality): void
    {
        abort_unless($municipality->supportsTcespAudesp(), 404);
    }

    private function safe(?string $value): string
    {
        $value = trim((string) $value);

        return preg_match('/^[=+\-@]/', $value) === 1 ? "'{$value}" : $value;
    }

    private function money(float|int $value): string
    {
        return number_format((float) $value, 2, ',', '');
    }
}
