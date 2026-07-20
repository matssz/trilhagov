<?php

namespace App\Http\Controllers;

use App\Models\MunicipalGovernanceReport;
use App\Models\Municipality;
use App\Models\MunicipalReportDispatch;
use App\Services\AuditTrail;
use App\Services\CurrentMunicipality;
use App\Services\FormSubmission;
use Barryvdh\DomPDF\Facade\Pdf;
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
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class MunicipalReportDispatchController extends Controller
{
    public function index(
        Request $request,
        int $report,
        CurrentMunicipality $currentMunicipality,
        FormSubmission $formSubmission,
    ): View {
        $municipality = $currentMunicipality->get($request);
        $this->ensureScope($municipality);
        $report = $this->report($municipality, $report);
        abort_unless($report->status === MunicipalGovernanceReport::STATUS_ISSUED, 409, 'Emita o relatório antes de preparar a remessa institucional.');
        $canEdit = $request->user()->canEditMunicipality($municipality->id);
        $retryTemplate = $canEdit && $request->integer('retry_of') > 0
            ? $report->dispatches()->where('status', MunicipalReportDispatch::STATUS_REJECTED)->find($request->integer('retry_of'))
            : null;

        return view('report-dispatches.index', [
            'municipality' => $municipality,
            'report' => $report,
            'dispatches' => $report->dispatches()
                ->with(['responsibleUser:id,name', 'creator:id,name', 'retryOf:id,reference'])
                ->latest()
                ->get(),
            'canEdit' => $canEdit,
            'createToken' => $canEdit ? $formSubmission->issue($request, "report-dispatch-create-{$report->id}") : null,
            'operationalUsers' => $canEdit
                ? $municipality->users()->wherePivotIn('role', ['manager', 'editor'])->orderBy('name')->get(['users.id', 'users.name'])
                : collect(),
            'rejectedDispatches' => $canEdit
                ? $report->dispatches()->where('status', MunicipalReportDispatch::STATUS_REJECTED)->latest()->get()
                : collect(),
            'retryTemplate' => $retryTemplate,
        ]);
    }

    public function store(
        Request $request,
        int $report,
        CurrentMunicipality $currentMunicipality,
        FormSubmission $formSubmission,
        AuditTrail $auditTrail,
    ): RedirectResponse {
        $municipality = $currentMunicipality->get($request);
        $this->ensureScope($municipality);
        $report = $this->report($municipality, $report);
        abort_unless($report->status === MunicipalGovernanceReport::STATUS_ISSUED, 409, 'Somente relatórios emitidos podem ser remetidos.');
        $validated = $request->validate([
            '_submission_token' => ['required', 'string'],
            'recipient_type' => ['required', Rule::in(array_keys(MunicipalReportDispatch::recipientTypes()))],
            'recipient_name' => ['required', 'string', 'min:3', 'max:180'],
            'recipient_unit' => ['nullable', 'string', 'max:180'],
            'recipient_email' => ['nullable', 'required_if:delivery_method,official_email', 'email:rfc', 'max:180'],
            'delivery_method' => ['required', Rule::in(array_keys(MunicipalReportDispatch::deliveryMethods()))],
            'legal_basis' => ['nullable', 'string', 'max:500'],
            'due_at' => ['required', 'date'],
            'responsible_user_id' => [
                'nullable', 'integer',
                Rule::exists('municipality_user', 'user_id')->where(fn ($query) => $query
                    ->where('municipality_id', $municipality->id)
                    ->whereIn('role', ['manager', 'editor'])),
            ],
            'retry_of_id' => [
                'nullable', 'integer',
                Rule::exists('municipal_report_dispatches', 'id')->where(fn ($query) => $query
                    ->where('municipality_id', $municipality->id)
                    ->where('municipal_governance_report_id', $report->id)
                    ->where('status', MunicipalReportDispatch::STATUS_REJECTED)),
            ],
            'notes' => ['nullable', 'string', 'max:3000'],
        ], [
            'recipient_email.required_if' => 'Informe o e-mail institucional usado para a remessa.',
            'due_at.required' => 'Informe o prazo adotado pelo Município para esta remessa.',
            'responsible_user_id.exists' => 'Escolha um gestor ou editor deste Município.',
        ]);
        if (! $formSubmission->consume($request, "report-dispatch-create-{$report->id}")) {
            return back()->with('warning', 'Esta remessa já foi preparada. Atualize a página para registrar outra.');
        }

        $retry = filled($validated['retry_of_id'] ?? null)
            ? $report->dispatches()->findOrFail($validated['retry_of_id'])
            : null;
        if ($retry && ($retry->recipient_type !== $validated['recipient_type'] || mb_strtolower($retry->recipient_name) !== mb_strtolower(trim($validated['recipient_name'])))) {
            throw ValidationException::withMessages(['retry_of_id' => 'Uma nova tentativa deve manter o mesmo destinatário da remessa devolvida.']);
        }
        $activeDuplicate = $report->dispatches()
            ->where('recipient_type', $validated['recipient_type'])
            ->whereRaw('LOWER(recipient_name) = ?', [mb_strtolower(trim($validated['recipient_name']))])
            ->whereIn('status', [MunicipalReportDispatch::STATUS_PREPARED, MunicipalReportDispatch::STATUS_SENT, MunicipalReportDispatch::STATUS_ACKNOWLEDGED])
            ->exists();
        if ($activeDuplicate) {
            throw ValidationException::withMessages(['recipient_name' => 'Este destinatário já possui uma remessa ativa ou recebida para a mesma versão do relatório.']);
        }

        $dispatch = DB::transaction(function () use ($request, $validated, $municipality, $report, $auditTrail): MunicipalReportDispatch {
            $dispatch = $report->dispatches()->create([
                'municipality_id' => $municipality->id,
                'created_by' => $request->user()->id,
                'responsible_user_id' => $validated['responsible_user_id'] ?? null,
                'retry_of_id' => $validated['retry_of_id'] ?? null,
                'reference' => (string) Str::uuid(),
                'recipient_type' => $validated['recipient_type'],
                'recipient_name' => trim($validated['recipient_name']),
                'recipient_unit' => filled($validated['recipient_unit'] ?? null) ? trim($validated['recipient_unit']) : null,
                'recipient_email' => filled($validated['recipient_email'] ?? null) ? mb_strtolower(trim($validated['recipient_email'])) : null,
                'delivery_method' => $validated['delivery_method'],
                'legal_basis' => filled($validated['legal_basis'] ?? null) ? trim($validated['legal_basis']) : null,
                'due_at' => $validated['due_at'],
                'status' => MunicipalReportDispatch::STATUS_PREPARED,
                'notes' => filled($validated['notes'] ?? null) ? trim($validated['notes']) : null,
            ]);
            $dispatch->events()->create([
                'municipality_id' => $municipality->id,
                'created_by' => $request->user()->id,
                'type' => 'prepared',
                'occurred_at' => now(),
                'message' => 'Remessa institucional preparada para protocolo.',
                'metadata' => ['report_code' => $report->code(), 'report_sha256' => $report->snapshot_sha256],
            ]);
            $auditTrail->recordMunicipalityOperation($request, $municipality, 'report_dispatch_created', [
                'report' => $report->code(),
                'dispatch_reference' => $dispatch->reference,
                'recipient_type' => $dispatch->recipient_type,
                'recipient_name' => $dispatch->recipient_name,
                'due_at' => $dispatch->due_at,
            ]);

            return $dispatch;
        });

        return redirect()->route('report-dispatches.show', $dispatch)
            ->with('status', 'Remessa preparada. Registre o protocolo após o envio pelo canal oficial do Município.');
    }

    public function show(
        Request $request,
        int $dispatch,
        CurrentMunicipality $currentMunicipality,
        FormSubmission $formSubmission,
    ): View {
        $municipality = $currentMunicipality->get($request);
        $this->ensureScope($municipality);
        $dispatch = $this->dispatch($municipality, $dispatch);
        $canEdit = $request->user()->canEditMunicipality($municipality->id);
        $canCancel = $request->user()->roleForMunicipality($municipality->id) === 'manager'
            && $dispatch->status === MunicipalReportDispatch::STATUS_PREPARED;

        return view('report-dispatches.show', [
            'municipality' => $municipality,
            'dispatch' => $dispatch,
            'report' => $dispatch->report,
            'canEdit' => $canEdit,
            'canCancel' => $canCancel,
            'sendToken' => $canEdit && $dispatch->status === MunicipalReportDispatch::STATUS_PREPARED
                ? $formSubmission->issue($request, "report-dispatch-send-{$dispatch->id}") : null,
            'returnToken' => $canEdit && $dispatch->status === MunicipalReportDispatch::STATUS_SENT
                ? $formSubmission->issue($request, "report-dispatch-return-{$dispatch->id}") : null,
            'cancelToken' => $canCancel ? $formSubmission->issue($request, "report-dispatch-cancel-{$dispatch->id}") : null,
        ]);
    }

    public function send(
        Request $request,
        int $dispatch,
        CurrentMunicipality $currentMunicipality,
        FormSubmission $formSubmission,
        AuditTrail $auditTrail,
    ): RedirectResponse {
        $municipality = $currentMunicipality->get($request);
        $this->ensureScope($municipality);
        $dispatch = $this->dispatch($municipality, $dispatch);
        $validated = $request->validate([
            '_submission_token' => ['required', 'string'],
            'official_document_number' => ['required', 'string', 'min:2', 'max:120'],
            'protocol_number' => ['required', 'string', 'min:2', 'max:160'],
            'sent_at' => ['required', 'date', 'before_or_equal:now'],
            'message' => ['nullable', 'string', 'max:3000'],
            'evidence' => ['required', File::types(['pdf', 'txt', 'csv', 'jpg', 'jpeg', 'png', 'eml', 'msg'])->max('10mb')],
        ], ['evidence.required' => 'Anexe o comprovante do protocolo, do envio institucional ou do recebimento eletrônico.']);
        if (! $formSubmission->consume($request, "report-dispatch-send-{$dispatch->id}")) {
            return back()->with('warning', 'Este protocolo já foi registrado.');
        }
        abort_unless($dispatch->status === MunicipalReportDispatch::STATUS_PREPARED, 409, 'A remessa não está mais aguardando envio.');
        $evidence = $this->storeEvidence($request->file('evidence'), $municipality->id, $dispatch->id);

        try {
            DB::transaction(function () use ($request, $validated, $dispatch, $municipality, $evidence, $auditTrail): void {
                $locked = MunicipalReportDispatch::query()->lockForUpdate()->findOrFail($dispatch->id);
                abort_unless($locked->status === MunicipalReportDispatch::STATUS_PREPARED, 409);
                $locked->update([
                    'status' => MunicipalReportDispatch::STATUS_SENT,
                    'official_document_number' => trim($validated['official_document_number']),
                    'protocol_number' => trim($validated['protocol_number']),
                    'sent_at' => $validated['sent_at'],
                ]);
                $locked->events()->create([
                    'municipality_id' => $municipality->id,
                    'created_by' => $request->user()->id,
                    'type' => 'sent',
                    'occurred_at' => $validated['sent_at'],
                    'protocol_number' => trim($validated['protocol_number']),
                    'message' => filled($validated['message'] ?? null) ? trim($validated['message']) : 'Envio registrado pelo operador municipal.',
                    ...$evidence,
                ]);
                $auditTrail->recordMunicipalityOperation($request, $municipality, 'report_dispatch_sent', [
                    'dispatch_reference' => $locked->reference,
                    'official_document_number' => $locked->official_document_number,
                    'protocol_number' => $locked->protocol_number,
                    'sent_at' => $locked->sent_at,
                    'evidence_sha256' => $evidence['evidence_sha256'],
                ]);
            });
        } catch (Throwable $exception) {
            Storage::delete($evidence['evidence_storage_path']);
            throw $exception;
        }

        return back()->with('status', 'Envio protocolado. A remessa agora aguarda confirmação ou devolução do destinatário.');
    }

    public function recordReturn(
        Request $request,
        int $dispatch,
        CurrentMunicipality $currentMunicipality,
        FormSubmission $formSubmission,
        AuditTrail $auditTrail,
    ): RedirectResponse {
        $municipality = $currentMunicipality->get($request);
        $this->ensureScope($municipality);
        $dispatch = $this->dispatch($municipality, $dispatch);
        abort_unless($dispatch->status === MunicipalReportDispatch::STATUS_SENT, 409, 'A remessa não está aguardando retorno.');
        $validated = $request->validate([
            '_submission_token' => ['required', 'string'],
            'result' => ['required', Rule::in([MunicipalReportDispatch::STATUS_ACKNOWLEDGED, MunicipalReportDispatch::STATUS_REJECTED])],
            'occurred_at' => ['required', 'date', 'after_or_equal:'.$dispatch->sent_at->format('Y-m-d H:i:s'), 'before_or_equal:now'],
            'protocol_number' => ['nullable', 'string', 'max:160'],
            'message' => ['nullable', 'required_if:result,rejected', 'string', 'max:5000'],
            'evidence' => ['required', File::types(['pdf', 'txt', 'csv', 'jpg', 'jpeg', 'png', 'eml', 'msg'])->max('10mb')],
        ], [
            'message.required_if' => 'Descreva o motivo da devolução para orientar a nova tentativa.',
            'evidence.required' => 'Anexe a confirmação de recebimento ou o documento de devolução.',
        ]);
        if (! $formSubmission->consume($request, "report-dispatch-return-{$dispatch->id}")) {
            return back()->with('warning', 'Este retorno já foi registrado.');
        }
        $evidence = $this->storeEvidence($request->file('evidence'), $municipality->id, $dispatch->id);

        try {
            DB::transaction(function () use ($request, $validated, $dispatch, $municipality, $evidence, $auditTrail): void {
                $locked = MunicipalReportDispatch::query()->lockForUpdate()->findOrFail($dispatch->id);
                abort_unless($locked->status === MunicipalReportDispatch::STATUS_SENT, 409);
                $acknowledged = $validated['result'] === MunicipalReportDispatch::STATUS_ACKNOWLEDGED;
                $locked->update([
                    'status' => $validated['result'],
                    'protocol_number' => filled($validated['protocol_number'] ?? null) ? trim($validated['protocol_number']) : $locked->protocol_number,
                    'acknowledged_at' => $acknowledged ? $validated['occurred_at'] : null,
                    'rejected_at' => $acknowledged ? null : $validated['occurred_at'],
                ]);
                $locked->events()->create([
                    'municipality_id' => $municipality->id,
                    'created_by' => $request->user()->id,
                    'type' => $validated['result'],
                    'occurred_at' => $validated['occurred_at'],
                    'protocol_number' => filled($validated['protocol_number'] ?? null) ? trim($validated['protocol_number']) : $locked->protocol_number,
                    'message' => filled($validated['message'] ?? null) ? trim($validated['message']) : 'Recebimento institucional confirmado.',
                    ...$evidence,
                ]);
                $auditTrail->recordMunicipalityOperation($request, $municipality, 'report_dispatch_returned', [
                    'dispatch_reference' => $locked->reference,
                    'result' => $locked->status,
                    'protocol_number' => $locked->protocol_number,
                    'evidence_sha256' => $evidence['evidence_sha256'],
                ]);
            });
        } catch (Throwable $exception) {
            Storage::delete($evidence['evidence_storage_path']);
            throw $exception;
        }

        return back()->with('status', $validated['result'] === MunicipalReportDispatch::STATUS_ACKNOWLEDGED
            ? 'Recebimento confirmado e evidência preservada.'
            : 'Devolução registrada. Uma nova tentativa poderá ser preparada sem apagar esta remessa.');
    }

    public function cancel(
        Request $request,
        int $dispatch,
        CurrentMunicipality $currentMunicipality,
        FormSubmission $formSubmission,
        AuditTrail $auditTrail,
    ): RedirectResponse {
        $municipality = $currentMunicipality->get($request);
        $this->ensureScope($municipality);
        $dispatch = $this->dispatch($municipality, $dispatch);
        $validated = $request->validate([
            '_submission_token' => ['required', 'string'],
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
        ]);
        if (! $formSubmission->consume($request, "report-dispatch-cancel-{$dispatch->id}")) {
            return back()->with('warning', 'Este cancelamento já foi registrado.');
        }
        abort_unless($dispatch->status === MunicipalReportDispatch::STATUS_PREPARED, 409);

        DB::transaction(function () use ($request, $validated, $dispatch, $municipality, $auditTrail): void {
            $locked = MunicipalReportDispatch::query()->lockForUpdate()->findOrFail($dispatch->id);
            abort_unless($locked->status === MunicipalReportDispatch::STATUS_PREPARED, 409);
            $locked->update(['status' => MunicipalReportDispatch::STATUS_CANCELLED, 'cancelled_at' => now()]);
            $locked->events()->create([
                'municipality_id' => $municipality->id,
                'created_by' => $request->user()->id,
                'type' => 'cancelled',
                'occurred_at' => now(),
                'message' => trim($validated['reason']),
            ]);
            $auditTrail->recordMunicipalityOperation($request, $municipality, 'report_dispatch_cancelled', [
                'dispatch_reference' => $locked->reference,
                'reason' => trim($validated['reason']),
            ]);
        });

        return back()->with('status', 'Preparação cancelada com justificativa preservada.');
    }

    public function evidence(
        Request $request,
        int $dispatch,
        int $event,
        CurrentMunicipality $currentMunicipality,
        AuditTrail $auditTrail,
    ): StreamedResponse {
        $municipality = $currentMunicipality->get($request);
        $this->ensureScope($municipality);
        $dispatch = $this->dispatch($municipality, $dispatch);
        $event = $dispatch->events()->findOrFail($event);
        abort_unless($event->evidence_storage_path && Storage::exists($event->evidence_storage_path), 404);
        $auditTrail->recordMunicipalityOperation($request, $municipality, 'report_dispatch_evidence_downloaded', [
            'dispatch_reference' => $dispatch->reference,
            'event_id' => $event->id,
            'evidence_sha256' => $event->evidence_sha256,
        ]);

        return Storage::download($event->evidence_storage_path, $event->evidence_original_name, [
            'Content-Type' => $event->evidence_mime_type,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function receipt(
        Request $request,
        int $dispatch,
        CurrentMunicipality $currentMunicipality,
        AuditTrail $auditTrail,
    ): Response {
        $municipality = $currentMunicipality->get($request);
        $this->ensureScope($municipality);
        $dispatch = $this->dispatch($municipality, $dispatch);
        $auditTrail->recordMunicipalityOperation($request, $municipality, 'report_dispatch_receipt_downloaded', [
            'dispatch_reference' => $dispatch->reference,
            'report' => $dispatch->report->code(),
        ]);

        return Pdf::loadView('report-dispatches.receipt', ['dispatch' => $dispatch, 'report' => $dispatch->report])
            ->setPaper('a4')
            ->download(Str::lower($dispatch->code()).'.pdf');
    }

    private function report(Municipality $municipality, int $id): MunicipalGovernanceReport
    {
        return $municipality->governanceReports()->with(['issuer:id,name'])->findOrFail($id);
    }

    private function dispatch(Municipality $municipality, int $id): MunicipalReportDispatch
    {
        return $municipality->reportDispatches()->with([
            'report.issuer:id,name', 'creator:id,name', 'responsibleUser:id,name',
            'retryOf:id,reference', 'retries:id,retry_of_id,reference,status', 'events.creator:id,name',
        ])->findOrFail($id);
    }

    /** @return array<string, mixed> */
    private function storeEvidence(UploadedFile $file, int $municipalityId, int $dispatchId): array
    {
        $extension = $file->extension() ?: strtolower($file->getClientOriginalExtension());
        $path = Storage::putFileAs("report-dispatches/{$municipalityId}/{$dispatchId}/evidence", $file, Str::uuid().'.'.$extension);
        if (! $path) {
            throw ValidationException::withMessages(['evidence' => 'Não foi possível armazenar o comprovante com segurança. Tente novamente.']);
        }

        return [
            'evidence_original_name' => $this->cleanName($file->getClientOriginalName()),
            'evidence_storage_path' => $path,
            'evidence_mime_type' => $file->getMimeType() ?: 'application/octet-stream',
            'evidence_size_bytes' => $file->getSize(),
            'evidence_sha256' => hash_file('sha256', $file->getRealPath()),
        ];
    }

    private function cleanName(string $name): string
    {
        return Str::of(basename($name))->replaceMatches('/[\x00-\x1F\x7F]/u', '')->limit(255, '')->toString();
    }

    private function ensureScope(Municipality $municipality): void
    {
        abort_unless($municipality->supportsTcespAudesp(), 404);
    }
}
