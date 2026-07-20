<?php

namespace App\Http\Controllers;

use App\Models\MunicipalGovernanceReport;
use App\Models\Municipality;
use App\Services\AuditTrail;
use App\Services\CurrentMunicipality;
use App\Services\FormSubmission;
use App\Services\MunicipalGovernanceReportService;
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

class MunicipalGovernanceReportController extends Controller
{
    public function index(
        Request $request,
        CurrentMunicipality $currentMunicipality,
        FormSubmission $formSubmission,
    ): View {
        $municipality = $currentMunicipality->get($request);
        $this->ensureScope($municipality);
        $canEdit = $request->user()->canEditMunicipality($municipality->id);

        return view('governance-reports.index', [
            'municipality' => $municipality,
            'reports' => $municipality->governanceReports()
                ->with(['creator:id,name', 'issuer:id,name'])
                ->latest('fiscal_year')
                ->latest('reference_month')
                ->latest('version')
                ->paginate(18),
            'canEdit' => $canEdit,
            'createToken' => $canEdit ? $formSubmission->issue($request, 'governance-report-create') : null,
        ]);
    }

    public function store(
        Request $request,
        CurrentMunicipality $currentMunicipality,
        FormSubmission $formSubmission,
        MunicipalGovernanceReportService $service,
        AuditTrail $auditTrail,
    ): RedirectResponse {
        $municipality = $currentMunicipality->get($request);
        $this->ensureScope($municipality);
        $validated = $request->validate([
            '_submission_token' => ['required', 'string'],
            'fiscal_year' => ['required', 'integer', Rule::in([2026])],
            'reference_month' => ['required', 'integer', 'between:1,12'],
        ], [
            'fiscal_year.in' => 'A metodologia atual foi validada para o exercício de 2026. A abertura de outro exercício exige revisão da base normativa.',
            'reference_month.between' => 'Informe uma competência mensal entre 01 e 12.',
        ]);

        if (! $formSubmission->consume($request, 'governance-report-create')) {
            return back()->with('warning', 'Esta solicitação já foi processada. Atualize a página para preparar outro relatório.');
        }

        $existingDraft = $municipality->governanceReports()
            ->where('fiscal_year', $validated['fiscal_year'])
            ->where('reference_month', $validated['reference_month'])
            ->where('status', MunicipalGovernanceReport::STATUS_DRAFT)
            ->first();
        if ($existingDraft) {
            return redirect()->route('governance-reports.show', $existingDraft)
                ->with('warning', 'Já existe uma versão em preparação para esta competência.');
        }

        $snapshot = $service->build($municipality, $validated['fiscal_year'], $validated['reference_month']);
        try {
            $report = DB::transaction(function () use ($request, $municipality, $validated, $snapshot, $service, $auditTrail): MunicipalGovernanceReport {
                $latestVersion = $municipality->governanceReports()
                    ->where('fiscal_year', $validated['fiscal_year'])
                    ->where('reference_month', $validated['reference_month'])
                    ->latest('version')
                    ->lockForUpdate()
                    ->first(['version']);
                $version = ((int) ($latestVersion?->version ?? 0)) + 1;
                $report = $municipality->governanceReports()->create([
                    'created_by' => $request->user()->id,
                    'updated_by' => $request->user()->id,
                    'reference' => (string) Str::uuid(),
                    'fiscal_year' => $validated['fiscal_year'],
                    'reference_month' => $validated['reference_month'],
                    'version' => $version,
                    'status' => MunicipalGovernanceReport::STATUS_DRAFT,
                    'snapshot' => $snapshot,
                    'snapshot_sha256' => $service->hash($snapshot),
                    'data_generated_at' => now(),
                ]);
                $auditTrail->recordMunicipalityOperation($request, $municipality, 'governance_report_created', [
                    'report' => $report->code(),
                    'reference' => $report->reference,
                    'snapshot_sha256' => $report->snapshot_sha256,
                ]);

                return $report;
            });
        } catch (QueryException $exception) {
            if (! in_array((string) $exception->getCode(), ['23000', '23505'], true)) {
                throw $exception;
            }

            $report = $municipality->governanceReports()
                ->where('fiscal_year', $validated['fiscal_year'])
                ->where('reference_month', $validated['reference_month'])
                ->latest('version')
                ->first();

            if (! $report) {
                throw $exception;
            }

            return redirect()->route('governance-reports.show', $report)
                ->with('warning', 'A competência foi preparada por outro integrante da equipe enquanto esta solicitação era processada.');
        }

        return redirect()->route('governance-reports.show', $report)
            ->with('status', 'Relatório mensal preparado com os dados atuais do Município.');
    }

    public function show(
        Request $request,
        int $report,
        CurrentMunicipality $currentMunicipality,
        FormSubmission $formSubmission,
    ): View {
        $municipality = $currentMunicipality->get($request);
        $this->ensureScope($municipality);
        $report = $this->report($municipality, $report);
        $canEdit = $request->user()->canEditMunicipality($municipality->id) && $report->isDraft();
        $canIssue = $request->user()->roleForMunicipality($municipality->id) === 'manager' && $report->isDraft();

        return view('governance-reports.show', [
            'municipality' => $municipality,
            'report' => $report,
            'snapshot' => $report->snapshot,
            'canEdit' => $canEdit,
            'canIssue' => $canIssue,
            'refreshToken' => $canEdit ? $formSubmission->issue($request, "governance-report-refresh-{$report->id}") : null,
            'issueToken' => $canIssue ? $formSubmission->issue($request, "governance-report-issue-{$report->id}") : null,
            'dispatches' => $report->status === MunicipalGovernanceReport::STATUS_ISSUED
                ? $report->dispatches()->with('responsibleUser:id,name')->latest()->limit(5)->get()
                : collect(),
        ]);
    }

    public function refresh(
        Request $request,
        int $report,
        CurrentMunicipality $currentMunicipality,
        FormSubmission $formSubmission,
        MunicipalGovernanceReportService $service,
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
        if (! $formSubmission->consume($request, "governance-report-refresh-{$report->id}")) {
            return back()->with('warning', 'A atualização já foi processada. Recarregue a página para conferir os dados.');
        }

        $snapshot = $service->build($municipality, $report->fiscal_year, $report->reference_month);
        $oldHash = $report->snapshot_sha256;
        $report->update([
            'updated_by' => $request->user()->id,
            'snapshot' => $snapshot,
            'snapshot_sha256' => $service->hash($snapshot),
            'management_notes' => filled($validated['management_notes'] ?? null) ? trim($validated['management_notes']) : null,
            'data_generated_at' => now(),
        ]);
        $auditTrail->recordMunicipalityOperation($request, $municipality, 'governance_report_refreshed', [
            'report' => $report->code(),
            'snapshot_sha256' => $report->snapshot_sha256,
        ], ['snapshot_sha256' => $oldHash]);

        return back()->with('status', 'Fotografia mensal atualizada. Revise as pendências antes da emissão.');
    }

    public function issue(
        Request $request,
        int $report,
        CurrentMunicipality $currentMunicipality,
        FormSubmission $formSubmission,
        AuditTrail $auditTrail,
    ): RedirectResponse {
        $municipality = $currentMunicipality->get($request);
        $this->ensureScope($municipality);
        $report = $this->report($municipality, $report);
        abort_unless($report->isDraft(), 409, 'Este relatório já foi emitido.');
        $request->validate([
            '_submission_token' => ['required', 'string'],
            'confirm_snapshot' => ['accepted'],
        ], ['confirm_snapshot.accepted' => 'Confirme que a fotografia mensal foi revisada antes da emissão.']);
        if (! $formSubmission->consume($request, "governance-report-issue-{$report->id}")) {
            return back()->with('warning', 'A emissão já foi processada.');
        }

        $report->update([
            'status' => MunicipalGovernanceReport::STATUS_ISSUED,
            'issued_by' => $request->user()->id,
            'issued_at' => now(),
        ]);
        $auditTrail->recordMunicipalityOperation($request, $municipality, 'governance_report_issued', [
            'report' => $report->code(),
            'reference' => $report->reference,
            'snapshot_sha256' => $report->snapshot_sha256,
        ]);

        return back()->with('status', 'Relatório emitido. A versão foi fechada e preservada para auditoria.');
    }

    public function pdf(
        Request $request,
        int $report,
        CurrentMunicipality $currentMunicipality,
        AuditTrail $auditTrail,
    ): Response {
        $municipality = $currentMunicipality->get($request);
        $this->ensureScope($municipality);
        $report = $this->report($municipality, $report);
        $auditTrail->recordMunicipalityOperation($request, $municipality, 'governance_report_downloaded', [
            'report' => $report->code(), 'format' => 'PDF', 'snapshot_sha256' => $report->snapshot_sha256,
        ]);

        return Pdf::loadView('governance-reports.pdf', [
            'report' => $report,
            'snapshot' => $report->snapshot,
        ])->setPaper('a4', 'landscape')->download(Str::lower($report->code()).'.pdf');
    }

    public function csv(
        Request $request,
        int $report,
        CurrentMunicipality $currentMunicipality,
        AuditTrail $auditTrail,
    ): StreamedResponse {
        $municipality = $currentMunicipality->get($request);
        $this->ensureScope($municipality);
        $report = $this->report($municipality, $report);
        $auditTrail->recordMunicipalityOperation($request, $municipality, 'governance_report_downloaded', [
            'report' => $report->code(), 'format' => 'CSV', 'snapshot_sha256' => $report->snapshot_sha256,
        ]);

        return response()->streamDownload(function () use ($report): void {
            $output = fopen('php://output', 'wb');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, ['Relatório', $report->code(), 'Hash SHA-256', $report->snapshot_sha256], ';');
            fputcsv($output, ['Referência', 'Autor', 'Objeto', 'Órgão', 'Situação', 'Previsto', 'Recebido', 'Empenhado', 'Liquidado', 'Pago', 'Saldo', 'Execução física', 'Plano', 'Conformidade', 'Impedimentos', 'Alertas', 'Audesp'], ';');
            foreach ($report->snapshot['amendments'] as $row) {
                fputcsv($output, [
                    $this->safe($row['reference']), $this->safe($row['author']), $this->safe($row['object']),
                    $this->safe($row['department']), $row['status_label'], $this->money($row['expected']),
                    $this->money($row['received']), $this->money($row['committed']), $this->money($row['liquidated']),
                    $this->money($row['paid']), $this->money($row['balance']), $row['physical_execution'].'%',
                    $row['work_plan_label'], $row['compliance_percentage'].'%', $row['open_impediments'],
                    $row['open_alerts'], $row['audesp_homologation_label'],
                ], ';');
            }
            fclose($output);
        }, Str::lower($report->code()).'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function report(Municipality $municipality, int $id): MunicipalGovernanceReport
    {
        return $municipality->governanceReports()->with(['creator:id,name', 'updater:id,name', 'issuer:id,name'])->findOrFail($id);
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
