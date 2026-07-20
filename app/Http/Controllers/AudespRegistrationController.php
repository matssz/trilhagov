<?php

namespace App\Http\Controllers;

use App\Models\AudespAmendmentRegistration;
use App\Services\AudespTraceabilityService;
use App\Services\AuditTrail;
use App\Services\CurrentMunicipality;
use App\Services\FormSubmission;
use App\Services\IntegrityAlertService;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AudespRegistrationController extends Controller
{
    public function index(
        Request $request,
        int $emenda,
        CurrentMunicipality $currentMunicipality,
        FormSubmission $formSubmission,
        AudespTraceabilityService $traceability,
    ): View {
        $municipality = $currentMunicipality->get($request);
        $amendment = $municipality->amendments()->with([
            'municipality',
            'audespRegistration.creator',
            'financialPayments',
            'financialCommitments.executionStage',
            'financialCommitments.liquidations.creator',
            'financialCommitments.liquidations.payments',
            'financialCommitments.payments.creator',
        ])->findOrFail($emenda);
        abort_unless($amendment->supportsTcespCompliance(), 404);

        $canEdit = $request->user()->canEditMunicipality($municipality->id);
        $readiness = $traceability->evaluate($amendment);
        $registration = $amendment->audespRegistration;

        return view('amendments.audesp', [
            'amendment' => $amendment,
            'registration' => $registration,
            'readiness' => $readiness,
            'canEdit' => $canEdit,
            'amendmentTypes' => AudespAmendmentRegistration::amendmentTypes(),
            'legalBases' => AudespAmendmentRegistration::legalBases(),
            'destinations' => AudespAmendmentRegistration::destinations(),
            'governmentFunctions' => AudespTraceabilityService::governmentFunctions(),
            'registrationToken' => $canEdit ? $formSubmission->issue($request, "audesp-registration-{$amendment->id}") : null,
            'previewToken' => $canEdit && $readiness['ready'] ? $formSubmission->issue($request, "audesp-preview-{$amendment->id}") : null,
            'liquidationTokens' => $canEdit ? $amendment->financialCommitments->mapWithKeys(fn ($commitment) => [
                $commitment->id => $formSubmission->issue($request, "financial-liquidation-create-{$commitment->id}"),
            ]) : collect(),
            'liquidatedAmount' => (float) $amendment->financialCommitments->sum(fn ($commitment) => $commitment->liquidations->sum('amount')),
            'unlinkedPayments' => $amendment->financialPayments->whereNull('financial_liquidation_id')->count(),
        ]);
    }

    public function update(
        Request $request,
        int $emenda,
        CurrentMunicipality $currentMunicipality,
        FormSubmission $formSubmission,
        AuditTrail $auditTrail,
        AudespTraceabilityService $traceability,
        IntegrityAlertService $integrityAlertService,
    ): RedirectResponse {
        $municipality = $currentMunicipality->get($request);
        $amendment = $municipality->amendments()->with(['municipality', 'audespRegistration', 'financialPayments'])->findOrFail($emenda);
        abort_unless($amendment->supportsTcespCompliance(), 404);
        $validated = $request->validate([
            '_submission_token' => ['required', 'string'],
            'amendment_type' => ['required', 'integer', Rule::in(array_keys(AudespAmendmentRegistration::amendmentTypes()))],
            'legal_basis' => ['required', Rule::in(array_keys(AudespAmendmentRegistration::legalBases()))],
            'proponent_name' => ['required', 'string', 'min:10', 'max:100'],
            'amendment_number' => ['required', 'string', 'min:3', 'max:30'],
            'amendment_year' => ['required', 'integer', 'between:2000,2099'],
            'object' => ['required', 'string', 'min:10', 'max:1000'],
            'purpose' => ['required', 'string', 'min:10', 'max:1000'],
            'government_function' => ['required', Rule::in(array_keys(AudespTraceabilityService::governmentFunctions()))],
            'government_subfunctions' => [
                'required',
                'string',
                'regex:/^\d{3}(?:\s*,\s*\d{3})*$/',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $codes = collect(explode(',', (string) $value))->map(fn ($code) => trim($code))->unique()->all();
                    $invalid = array_diff($codes, AudespTraceabilityService::governmentSubfunctionCodes());
                    if ($invalid !== []) {
                        $fail('Código(s) não previsto(s) na tabela auxiliar Audesp 2026_A: '.implode(', ', $invalid).'.');
                    }
                },
            ],
            'destination' => ['required', Rule::in(array_keys(AudespAmendmentRegistration::destinations()))],
            'bank_account_opened' => ['required', 'boolean'],
            'application_code' => ['required', 'string', 'regex:/^(800|801|802|803|804|900|901|902|903)[0-9]{1,4}$/'],
            'prior_balance_reclassified' => ['nullable', 'boolean'],
            'reclassification_reference' => ['nullable', 'string', 'max:120'],
            'reclassified_at' => ['nullable', 'date'],
        ], [
            'government_subfunctions.regex' => 'Informe códigos de três dígitos separados por vírgula, por exemplo: 301, 302.',
            'application_code.regex' => 'Use o código combinado do XSD: prefixo 800 a 804 ou 900 a 903, seguido de 1 a 4 dígitos.',
        ]);

        if (! $formSubmission->consume($request, "audesp-registration-{$amendment->id}")) {
            return back()->with('warning', 'Este cadastro Audesp já foi processado.');
        }

        $subfunctions = collect(explode(',', $validated['government_subfunctions']))
            ->map(fn ($code) => trim($code))
            ->unique()
            ->values()
            ->all();
        try {
            DB::transaction(function () use ($request, $validated, $subfunctions, $municipality, $amendment, $auditTrail, $traceability): void {
                $registration = $amendment->audespRegistration()->firstOrNew();
                $oldValues = $registration->exists ? $registration->getAttributes() : null;
                $registration->fill([
                    ...$validated,
                    'municipality_id' => $municipality->id,
                    'created_by' => $registration->created_by ?? $request->user()->id,
                    'scope' => 'M',
                    'proponent_name' => trim($validated['proponent_name']),
                    'amendment_number' => trim($validated['amendment_number']),
                    'object' => trim($validated['object']),
                    'purpose' => trim($validated['purpose']),
                    'government_subfunctions' => $subfunctions,
                    'bank_account_opened' => (bool) $validated['bank_account_opened'],
                    'prior_balance_reclassified' => (bool) ($validated['prior_balance_reclassified'] ?? false),
                    'reclassification_reference' => filled($validated['reclassification_reference'] ?? null) ? trim($validated['reclassification_reference']) : null,
                ]);
                $registration->save();
                $amendment->setRelation('audespRegistration', $registration);
                $assessment = $traceability->evaluate($amendment);
                $registration->forceFill(['prepared_at' => $assessment['ready'] ? ($registration->prepared_at ?? now()) : null])->saveQuietly();

                $auditTrail->recordOperation($request, $amendment, $oldValues ? 'audesp_registration_updated' : 'audesp_registration_created', [
                    'audesp_schema' => AudespAmendmentRegistration::SCHEMA_VERSION,
                    'audesp_number' => $registration->amendment_number,
                    'audesp_year' => $registration->amendment_year,
                    'audesp_application_code' => $registration->application_code,
                    'audesp_ready' => $assessment['ready'],
                ], $oldValues);
            });
        } catch (QueryException $exception) {
            if (! in_array((string) $exception->getCode(), ['23000', '23505'], true)) {
                throw $exception;
            }

            throw ValidationException::withMessages([
                'amendment_number' => 'Já existe um cadastro Audesp com este número e exercício no município.',
            ]);
        }

        $integrityAlertService->sync($municipality->fresh());

        return back()->with('status', 'Cadastro Audesp salvo e diagnóstico recalculado.');
    }

    public function diagnostic(
        Request $request,
        int $emenda,
        CurrentMunicipality $currentMunicipality,
        AuditTrail $auditTrail,
        AudespTraceabilityService $traceability,
    ): StreamedResponse {
        $municipality = $currentMunicipality->get($request);
        $amendment = $municipality->amendments()->with(['municipality', 'audespRegistration', 'financialPayments'])->findOrFail($emenda);
        abort_unless($amendment->supportsTcespCompliance(), 404);
        $assessment = $traceability->evaluate($amendment);
        $auditTrail->recordOperation($request, $amendment, 'audesp_diagnostic_exported', [
            'audesp_schema' => AudespAmendmentRegistration::SCHEMA_VERSION,
            'audesp_ready' => $assessment['ready'],
            'audesp_blockers' => count($assessment['blockers']),
        ]);
        $filename = 'diagnostico-audesp-'.preg_replace('/[^A-Za-z0-9_-]/', '-', $amendment->reference).'.csv';

        return response()->streamDownload(function () use ($assessment): void {
            $output = fopen('php://output', 'wb');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, ['Nível', 'Resultado'], ';');
            fputcsv($output, ['Resumo', $assessment['ready'] ? 'Apta para prévia interna' : 'Possui bloqueios'], ';');
            foreach ($assessment['blockers'] as $blocker) {
                fputcsv($output, ['Bloqueio', $this->safeCsv($blocker)], ';');
            }
            foreach ($assessment['warnings'] as $warning) {
                fputcsv($output, ['Aviso', $this->safeCsv($warning)], ';');
            }
            fclose($output);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function preview(
        Request $request,
        int $emenda,
        CurrentMunicipality $currentMunicipality,
        FormSubmission $formSubmission,
        AuditTrail $auditTrail,
        AudespTraceabilityService $traceability,
    ): Response {
        $municipality = $currentMunicipality->get($request);
        $amendment = $municipality->amendments()->with(['municipality', 'audespRegistration', 'financialPayments'])->findOrFail($emenda);
        abort_unless($amendment->supportsTcespCompliance(), 404);
        $request->validate(['_submission_token' => ['required', 'string']]);

        if (! $formSubmission->consume($request, "audesp-preview-{$amendment->id}")) {
            abort(409, 'Esta prévia já foi gerada. Atualize a página para gerar outra.');
        }

        $assessment = $traceability->evaluate($amendment);
        if (! $assessment['ready']) {
            throw ValidationException::withMessages(['audesp' => 'Corrija os bloqueios do diagnóstico antes de gerar a prévia.']);
        }

        DB::transaction(function () use ($request, $amendment, $auditTrail): void {
            $registration = AudespAmendmentRegistration::query()
                ->where('parliamentary_amendment_id', $amendment->id)
                ->lockForUpdate()
                ->firstOrFail();
            $registration->forceFill([
                'last_previewed_at' => now(),
                'preview_count' => $registration->preview_count + 1,
            ])->save();
            $amendment->setRelation('audespRegistration', $registration);
            $auditTrail->recordOperation($request, $amendment, 'audesp_preview_exported', [
                'audesp_schema' => AudespAmendmentRegistration::SCHEMA_VERSION,
                'preview_count' => $registration->preview_count,
            ]);
        });

        $filename = 'previa-audesp-'.preg_replace('/[^A-Za-z0-9_-]/', '-', $amendment->reference).'.xml';

        return response($traceability->buildInternalPreview($amendment), 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function safeCsv(string $value): string
    {
        return preg_match('/^[=+\-@]/', ltrim($value)) === 1 ? "'".$value : $value;
    }
}
