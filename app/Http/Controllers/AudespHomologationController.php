<?php

namespace App\Http\Controllers;

use App\Models\AudespAmendmentRegistration;
use App\Models\AudespHomologationBatch;
use App\Models\Municipality;
use App\Services\AudespHomologationService;
use App\Services\AuditTrail;
use App\Services\CurrentMunicipality;
use App\Services\FormSubmission;
use App\Services\IntegrityAlertService;
use App\Services\MunicipalWorkItemService;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class AudespHomologationController extends Controller
{
    public function index(
        Request $request,
        CurrentMunicipality $currentMunicipality,
        FormSubmission $formSubmission,
    ): View {
        $municipality = $currentMunicipality->get($request);
        $this->ensureAudespScope($municipality);
        $canEdit = $request->user()->canEditMunicipality($municipality->id);
        $batches = $municipality->audespHomologationBatches()
            ->with(['creator:id,name', 'retryOf:id,reference'])
            ->latest('created_at')
            ->paginate(12);
        $counts = $municipality->audespHomologationBatches()
            ->selectRaw('status, COUNT(*) AS aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return view('audesp-homologations.index', [
            'municipality' => $municipality,
            'batches' => $batches,
            'counts' => $counts,
            'canEdit' => $canEdit,
            'uploadToken' => $canEdit ? $formSubmission->issue($request, 'audesp-homologation-upload') : null,
            'rejectedBatches' => $canEdit
                ? $municipality->audespHomologationBatches()->where('status', AudespHomologationBatch::STATUS_REJECTED)->latest()->limit(20)->get()
                : collect(),
        ]);
    }

    public function store(
        Request $request,
        CurrentMunicipality $currentMunicipality,
        FormSubmission $formSubmission,
        AudespHomologationService $homologation,
        AuditTrail $auditTrail,
        MunicipalWorkItemService $workItems,
        IntegrityAlertService $alerts,
    ): RedirectResponse {
        $municipality = $currentMunicipality->get($request);
        $this->ensureAudespScope($municipality);
        $validated = $request->validate([
            '_submission_token' => ['required', 'string'],
            'fiscal_year' => ['required', 'integer', Rule::in([2026])],
            'reference_month' => ['required', 'integer', 'between:1,14'],
            'source_system' => ['required', 'string', 'min:2', 'max:120'],
            'source_version' => ['nullable', 'string', 'max:80'],
            'retry_of_id' => [
                'nullable',
                'integer',
                Rule::exists('audesp_homologation_batches', 'id')->where(fn ($query) => $query
                    ->where('municipality_id', $municipality->id)
                    ->where('status', AudespHomologationBatch::STATUS_REJECTED)),
            ],
            'source_file' => ['required', File::types(['xml'])->max('5mb')],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [
            'fiscal_year.in' => 'Este módulo está homologado somente para o XSD 2026_A. Um novo exercício exigirá a publicação e a validação do schema correspondente.',
            'source_file.required' => 'Selecione o XML produzido pelo Siafic.',
            'source_file.mimes' => 'O arquivo do lote deve estar no formato XML.',
        ]);

        if (! $formSubmission->consume($request, 'audesp-homologation-upload')) {
            return back()->with('warning', 'Este arquivo já foi processado. Atualize a página para iniciar outro lote.');
        }

        $file = $request->file('source_file');
        $contents = $file->get();
        $sha256 = hash('sha256', $contents);
        if ($municipality->audespHomologationBatches()->where('source_sha256', $sha256)->exists()) {
            throw ValidationException::withMessages([
                'source_file' => 'Este mesmo arquivo já foi registrado neste município. Abra o lote existente para consultar o resultado.',
            ]);
        }

        $inspection = $homologation->inspect($contents, $municipality);
        $directory = "audesp-homologations/{$municipality->id}/sources";
        $storagePath = Storage::putFileAs($directory, $file, Str::uuid().'.xml');
        if (! $storagePath) {
            throw ValidationException::withMessages(['source_file' => 'Não foi possível armazenar o XML com segurança. Tente novamente.']);
        }

        try {
            $batch = DB::transaction(function () use ($request, $validated, $municipality, $file, $storagePath, $sha256, $inspection, $auditTrail): AudespHomologationBatch {
                $stats = $inspection['stats'];
                $batch = $municipality->audespHomologationBatches()->create([
                    'created_by' => $request->user()->id,
                    'retry_of_id' => $validated['retry_of_id'] ?? null,
                    'reference' => (string) Str::uuid(),
                    'fiscal_year' => $validated['fiscal_year'],
                    'reference_month' => $validated['reference_month'],
                    'source_system' => trim($validated['source_system']),
                    'source_version' => filled($validated['source_version'] ?? null) ? trim($validated['source_version']) : null,
                    'schema_version' => AudespAmendmentRegistration::SCHEMA_VERSION,
                    'status' => $stats['total'] > 0 && $stats['matched'] === $stats['total']
                        ? AudespHomologationBatch::STATUS_READY
                        : AudespHomologationBatch::STATUS_UNDER_REVIEW,
                    'source_original_name' => $this->cleanName($file->getClientOriginalName()),
                    'source_storage_path' => $storagePath,
                    'source_mime_type' => $file->getMimeType() ?: 'application/xml',
                    'source_size_bytes' => $file->getSize(),
                    'source_sha256' => $sha256,
                    'item_count' => $stats['total'],
                    'matched_count' => $stats['matched'],
                    'divergent_count' => $stats['divergent'],
                    'unmatched_count' => $stats['unmatched'],
                    'notes' => filled($validated['notes'] ?? null) ? trim($validated['notes']) : null,
                ]);
                $batch->items()->createMany($inspection['items']);
                $batch->events()->create([
                    'municipality_id' => $municipality->id,
                    'created_by' => $request->user()->id,
                    'type' => 'source_imported',
                    'occurred_at' => now(),
                    'message' => 'XML do Siafic registrado e comparado com os cadastros do TrilhaGov.',
                    'metadata' => $stats,
                ]);
                $auditTrail->recordMunicipalityOperation($request, $municipality, 'audesp_homologation_created', [
                    'batch_reference' => $batch->reference,
                    'source_sha256' => $sha256,
                    'items' => $stats['total'],
                    'status' => $batch->status,
                ]);

                return $batch;
            });
        } catch (Throwable $exception) {
            Storage::delete($storagePath);
            if ($exception instanceof QueryException && in_array((string) $exception->getCode(), ['23000', '23505'], true)) {
                throw ValidationException::withMessages(['source_file' => 'Este arquivo já foi registrado para o município.']);
            }

            throw $exception;
        }

        $workItems->synchronize($municipality->fresh());
        $alerts->sync($municipality->fresh());

        return redirect()->route('audesp-homologations.show', $batch)
            ->with('status', $batch->status === AudespHomologationBatch::STATUS_READY
                ? 'Lote conferido sem divergências e pronto para o Coletor Audesp.'
                : 'Lote criado. Revise as divergências antes da transmissão.');
    }

    public function show(
        Request $request,
        int $batch,
        CurrentMunicipality $currentMunicipality,
        FormSubmission $formSubmission,
    ): View {
        $municipality = $currentMunicipality->get($request);
        $this->ensureAudespScope($municipality);
        $batch = $this->batch($municipality->id, $batch)->load([
            'creator:id,name',
            'retryOf:id,reference,status',
            'retries:id,retry_of_id,reference,status,created_at',
            'items.amendment:id,reference,object',
            'events.creator:id,name',
        ]);
        $canEdit = $request->user()->canEditMunicipality($municipality->id);

        return view('audesp-homologations.show', [
            'batch' => $batch,
            'canEdit' => $canEdit,
            'externalStatuses' => AudespHomologationBatch::externalStatuses(),
            'availableExternalStatuses' => $this->availableExternalStatuses($batch),
            'recheckToken' => $canEdit && $batch->isEditable() ? $formSubmission->issue($request, "audesp-homologation-recheck-{$batch->id}") : null,
            'submissionToken' => $canEdit && $batch->status === AudespHomologationBatch::STATUS_READY ? $formSubmission->issue($request, "audesp-homologation-submit-{$batch->id}") : null,
            'returnToken' => $canEdit && in_array($batch->status, [
                AudespHomologationBatch::STATUS_SUBMITTED,
                AudespHomologationBatch::STATUS_RECEIVED,
                AudespHomologationBatch::STATUS_VALIDATED,
            ], true) ? $formSubmission->issue($request, "audesp-homologation-return-{$batch->id}") : null,
        ]);
    }

    public function recheck(
        Request $request,
        int $batch,
        CurrentMunicipality $currentMunicipality,
        FormSubmission $formSubmission,
        AudespHomologationService $homologation,
        AuditTrail $auditTrail,
        MunicipalWorkItemService $workItems,
        IntegrityAlertService $alerts,
    ): RedirectResponse {
        $municipality = $currentMunicipality->get($request);
        $this->ensureAudespScope($municipality);
        $batch = $this->batch($municipality->id, $batch);
        $request->validate(['_submission_token' => ['required', 'string']]);
        if (! $formSubmission->consume($request, "audesp-homologation-recheck-{$batch->id}")) {
            return back()->with('warning', 'Esta conferência já foi executada.');
        }
        abort_unless($batch->isEditable(), 409);
        abort_unless(Storage::exists($batch->source_storage_path), 404);

        $inspection = $homologation->inspect((string) Storage::get($batch->source_storage_path), $municipality);
        DB::transaction(function () use ($request, $batch, $inspection, $municipality, $auditTrail): void {
            $locked = AudespHomologationBatch::query()->lockForUpdate()->findOrFail($batch->id);
            abort_unless($locked->isEditable(), 409);
            $locked->items()->delete();
            $locked->items()->createMany($inspection['items']);
            $stats = $inspection['stats'];
            $locked->update([
                'status' => $stats['total'] > 0 && $stats['matched'] === $stats['total']
                    ? AudespHomologationBatch::STATUS_READY
                    : AudespHomologationBatch::STATUS_UNDER_REVIEW,
                'item_count' => $stats['total'],
                'matched_count' => $stats['matched'],
                'divergent_count' => $stats['divergent'],
                'unmatched_count' => $stats['unmatched'],
            ]);
            $locked->events()->create([
                'municipality_id' => $municipality->id,
                'created_by' => $request->user()->id,
                'type' => 'source_rechecked',
                'occurred_at' => now(),
                'message' => 'O XML original foi comparado novamente com os dados atuais do TrilhaGov.',
                'metadata' => $stats,
            ]);
            $auditTrail->recordMunicipalityOperation($request, $municipality, 'audesp_homologation_rechecked', [
                'batch_reference' => $locked->reference,
                'status' => $locked->status,
                ...$stats,
            ]);
        });
        $workItems->synchronize($municipality->fresh());
        $alerts->sync($municipality->fresh());

        return back()->with('status', 'Conferência atualizada com os dados mais recentes do TrilhaGov.');
    }

    public function recordSubmission(
        Request $request,
        int $batch,
        CurrentMunicipality $currentMunicipality,
        FormSubmission $formSubmission,
        AuditTrail $auditTrail,
    ): RedirectResponse {
        $municipality = $currentMunicipality->get($request);
        $this->ensureAudespScope($municipality);
        $batch = $this->batch($municipality->id, $batch);
        $validated = $request->validate([
            '_submission_token' => ['required', 'string'],
            'external_protocol' => ['required', 'string', 'min:3', 'max:160'],
            'submitted_at' => ['required', 'date', 'before_or_equal:now'],
            'message' => ['nullable', 'string', 'max:2000'],
            'evidence' => ['nullable', File::types(['pdf', 'xml', 'txt', 'csv', 'jpg', 'jpeg', 'png'])->max('10mb')],
        ]);
        if (! $formSubmission->consume($request, "audesp-homologation-submit-{$batch->id}")) {
            return back()->with('warning', 'Esta transmissão já foi registrada.');
        }
        abort_unless($batch->status === AudespHomologationBatch::STATUS_READY, 409);
        $evidence = $this->storeEvidence($request->file('evidence'), $municipality->id, $batch->id);

        try {
            DB::transaction(function () use ($request, $validated, $batch, $municipality, $evidence, $auditTrail): void {
                $locked = AudespHomologationBatch::query()->lockForUpdate()->findOrFail($batch->id);
                abort_unless($locked->status === AudespHomologationBatch::STATUS_READY, 409);
                $locked->update([
                    'status' => AudespHomologationBatch::STATUS_SUBMITTED,
                    'submitted_at' => $validated['submitted_at'],
                    'external_protocol' => trim($validated['external_protocol']),
                ]);
                $locked->events()->create([
                    'municipality_id' => $municipality->id,
                    'created_by' => $request->user()->id,
                    'type' => 'submission_recorded',
                    'protocol' => trim($validated['external_protocol']),
                    'occurred_at' => $validated['submitted_at'],
                    'message' => filled($validated['message'] ?? null) ? trim($validated['message']) : 'Transmissão externa registrada pelo operador municipal.',
                    ...$evidence,
                ]);
                $auditTrail->recordMunicipalityOperation($request, $municipality, 'audesp_submission_recorded', [
                    'batch_reference' => $locked->reference,
                    'protocol' => $locked->external_protocol,
                    'submitted_at' => $locked->submitted_at,
                ]);
            });
        } catch (Throwable $exception) {
            $this->deleteEvidence($evidence);
            throw $exception;
        }

        return back()->with('status', 'Transmissão pelo Coletor registrada. Agora acompanhe e registre o retorno do Audesp.');
    }

    public function recordReturn(
        Request $request,
        int $batch,
        CurrentMunicipality $currentMunicipality,
        FormSubmission $formSubmission,
        AuditTrail $auditTrail,
        MunicipalWorkItemService $workItems,
        IntegrityAlertService $alerts,
    ): RedirectResponse {
        $municipality = $currentMunicipality->get($request);
        $this->ensureAudespScope($municipality);
        $batch = $this->batch($municipality->id, $batch);
        $validated = $request->validate([
            '_submission_token' => ['required', 'string'],
            'external_status' => ['required', Rule::in(array_keys(AudespHomologationBatch::externalStatuses()))],
            'occurred_at' => ['required', 'date', 'before_or_equal:now'],
            'protocol' => ['nullable', 'string', 'max:160'],
            'issue_code' => ['nullable', 'string', 'max:100'],
            'issue_field' => ['nullable', 'string', 'max:160'],
            'message' => ['nullable', 'required_if:external_status,rejected', 'string', 'max:5000'],
            'evidence' => ['required', File::types(['pdf', 'xml', 'txt', 'csv', 'jpg', 'jpeg', 'png'])->max('10mb')],
        ], [
            'evidence.required' => 'Anexe o recibo, a consulta de status ou o arquivo de retorno do Audesp.',
            'message.required_if' => 'Descreva a rejeição para orientar a correção e o reenvio.',
        ]);
        if (! $formSubmission->consume($request, "audesp-homologation-return-{$batch->id}")) {
            return back()->with('warning', 'Este retorno já foi registrado.');
        }
        abort_unless(in_array($batch->status, [
            AudespHomologationBatch::STATUS_SUBMITTED,
            AudespHomologationBatch::STATUS_RECEIVED,
            AudespHomologationBatch::STATUS_VALIDATED,
        ], true), 409);
        $allowed = match ($batch->status) {
            AudespHomologationBatch::STATUS_SUBMITTED => array_keys(AudespHomologationBatch::externalStatuses()),
            AudespHomologationBatch::STATUS_RECEIVED => [AudespHomologationBatch::STATUS_VALIDATED, AudespHomologationBatch::STATUS_REJECTED, AudespHomologationBatch::STATUS_STORED],
            AudespHomologationBatch::STATUS_VALIDATED => [AudespHomologationBatch::STATUS_REJECTED, AudespHomologationBatch::STATUS_STORED],
            default => [],
        };
        if (! in_array($validated['external_status'], $allowed, true)) {
            throw ValidationException::withMessages(['external_status' => 'Este retorno não é compatível com o estágio atual do lote.']);
        }
        $evidence = $this->storeEvidence($request->file('evidence'), $municipality->id, $batch->id);

        try {
            DB::transaction(function () use ($request, $validated, $batch, $municipality, $evidence, $auditTrail): void {
                $locked = AudespHomologationBatch::query()->lockForUpdate()->findOrFail($batch->id);
                $locked->update([
                    'status' => $validated['external_status'],
                    'external_protocol' => filled($validated['protocol'] ?? null) ? trim($validated['protocol']) : $locked->external_protocol,
                    'last_return_at' => $validated['occurred_at'],
                ]);
                $locked->events()->create([
                    'municipality_id' => $municipality->id,
                    'created_by' => $request->user()->id,
                    'type' => $validated['external_status'] === AudespHomologationBatch::STATUS_REJECTED ? 'rejection_recorded' : 'external_return_recorded',
                    'external_status' => $validated['external_status'],
                    'protocol' => filled($validated['protocol'] ?? null) ? trim($validated['protocol']) : $locked->external_protocol,
                    'occurred_at' => $validated['occurred_at'],
                    'issue_code' => filled($validated['issue_code'] ?? null) ? trim($validated['issue_code']) : null,
                    'issue_field' => filled($validated['issue_field'] ?? null) ? trim($validated['issue_field']) : null,
                    'message' => filled($validated['message'] ?? null) ? trim($validated['message']) : AudespHomologationBatch::externalStatuses()[$validated['external_status']],
                    ...$evidence,
                ]);
                $auditTrail->recordMunicipalityOperation($request, $municipality, 'audesp_return_recorded', [
                    'batch_reference' => $locked->reference,
                    'external_status' => $locked->status,
                    'protocol' => $locked->external_protocol,
                ]);
            });
        } catch (Throwable $exception) {
            $this->deleteEvidence($evidence);
            throw $exception;
        }

        $workItems->synchronize($municipality->fresh());
        $alerts->sync($municipality->fresh());

        return back()->with('status', $validated['external_status'] === AudespHomologationBatch::STATUS_REJECTED
            ? 'Rejeição registrada. O lote permanece preservado e pode originar uma nova tentativa.'
            : 'Retorno do Audesp registrado com a respectiva evidência.');
    }

    public function source(Request $request, int $batch, CurrentMunicipality $currentMunicipality): StreamedResponse
    {
        $municipality = $currentMunicipality->get($request);
        $this->ensureAudespScope($municipality);
        $batch = $this->batch($municipality->id, $batch);
        abort_unless(Storage::exists($batch->source_storage_path), 404);

        return Storage::download($batch->source_storage_path, $batch->source_original_name, [
            'Content-Type' => $batch->source_mime_type,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function evidence(Request $request, int $batch, int $event, CurrentMunicipality $currentMunicipality): StreamedResponse
    {
        $municipality = $currentMunicipality->get($request);
        $this->ensureAudespScope($municipality);
        $batch = $this->batch($municipality->id, $batch);
        $event = $batch->events()->findOrFail($event);
        abort_unless($event->evidence_storage_path && Storage::exists($event->evidence_storage_path), 404);

        return Storage::download($event->evidence_storage_path, $event->evidence_original_name, [
            'Content-Type' => $event->evidence_mime_type,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function report(Request $request, int $batch, CurrentMunicipality $currentMunicipality): StreamedResponse
    {
        $municipality = $currentMunicipality->get($request);
        $this->ensureAudespScope($municipality);
        $batch = $this->batch($municipality->id, $batch)->load('items.amendment:id,reference');

        return response()->streamDownload(function () use ($batch): void {
            $output = fopen('php://output', 'wb');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, ['Emenda XML', 'Ano', 'Emenda TrilhaGov', 'Resultado', 'Campo', 'Valor Siafic', 'Valor TrilhaGov'], ';');
            foreach ($batch->items as $item) {
                if (! $item->differences) {
                    fputcsv($output, [$this->safeCsv($item->source_amendment_number ?? ''), $item->source_amendment_year, $item->amendment?->reference, $item->status, '', '', ''], ';');

                    continue;
                }
                foreach ($item->differences as $difference) {
                    fputcsv($output, [
                        $this->safeCsv($item->source_amendment_number ?? ''),
                        $item->source_amendment_year,
                        $this->safeCsv($item->amendment?->reference ?? ''),
                        $item->status,
                        $difference['label'],
                        $this->safeCsv($this->printable($difference['source'] ?? '')),
                        $this->safeCsv($this->printable($difference['local'] ?? '')),
                    ], ';');
                }
            }
            fclose($output);
        }, "conferencia-audesp-{$batch->reference}.csv", [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function batch(int $municipalityId, int $batch): AudespHomologationBatch
    {
        return AudespHomologationBatch::query()
            ->where('municipality_id', $municipalityId)
            ->findOrFail($batch);
    }

    private function ensureAudespScope(Municipality $municipality): void
    {
        abort_unless($municipality->supportsTcespAudesp(), 404);
    }

    /** @return array<string, string> */
    private function availableExternalStatuses(AudespHomologationBatch $batch): array
    {
        $allowed = match ($batch->status) {
            AudespHomologationBatch::STATUS_SUBMITTED => array_keys(AudespHomologationBatch::externalStatuses()),
            AudespHomologationBatch::STATUS_RECEIVED => [AudespHomologationBatch::STATUS_VALIDATED, AudespHomologationBatch::STATUS_REJECTED, AudespHomologationBatch::STATUS_STORED],
            AudespHomologationBatch::STATUS_VALIDATED => [AudespHomologationBatch::STATUS_REJECTED, AudespHomologationBatch::STATUS_STORED],
            default => [],
        };

        return array_intersect_key(AudespHomologationBatch::externalStatuses(), array_flip($allowed));
    }

    /** @return array<string, mixed> */
    private function storeEvidence(?UploadedFile $file, int $municipalityId, int $batchId): array
    {
        if ($file === null) {
            return [];
        }

        $extension = $file->extension() ?: strtolower($file->getClientOriginalExtension());
        $path = Storage::putFileAs("audesp-homologations/{$municipalityId}/{$batchId}/evidence", $file, Str::uuid().'.'.$extension);
        if (! $path) {
            throw ValidationException::withMessages(['evidence' => 'Não foi possível armazenar a evidência. Tente novamente.']);
        }

        return [
            'evidence_original_name' => $this->cleanName($file->getClientOriginalName()),
            'evidence_storage_path' => $path,
            'evidence_mime_type' => $file->getMimeType() ?: 'application/octet-stream',
            'evidence_size_bytes' => $file->getSize(),
            'evidence_sha256' => hash_file('sha256', $file->getRealPath()),
        ];
    }

    /** @param array<string, mixed> $evidence */
    private function deleteEvidence(array $evidence): void
    {
        if (isset($evidence['evidence_storage_path'])) {
            Storage::delete($evidence['evidence_storage_path']);
        }
    }

    private function cleanName(string $name): string
    {
        return Str::of(basename($name))->replaceMatches('/[\x00-\x1F\x7F]/u', '')->limit(255, '')->toString();
    }

    private function printable(mixed $value): string
    {
        return is_array($value) ? implode(', ', $value) : (string) $value;
    }

    private function safeCsv(string $value): string
    {
        return preg_match('/^[=+\-@]/', ltrim($value)) === 1 ? "'".$value : $value;
    }
}
