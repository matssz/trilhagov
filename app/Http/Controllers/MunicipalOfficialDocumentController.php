<?php

namespace App\Http\Controllers;

use App\Models\MunicipalInternalControlReview;
use App\Models\Municipality;
use App\Models\MunicipalOfficialDocument;
use App\Models\TechnicalDiligence;
use App\Models\TechnicalImpediment;
use App\Services\AuditTrail;
use App\Services\CurrentMunicipality;
use App\Services\FormSubmission;
use App\Services\MunicipalOfficialDocumentService;
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

class MunicipalOfficialDocumentController extends Controller
{
    public function index(
        Request $request,
        CurrentMunicipality $currentMunicipality,
        FormSubmission $formSubmission,
        MunicipalOfficialDocumentService $service,
    ): View {
        $municipality = $currentMunicipality->get($request);
        $role = $request->user()->roleForMunicipality($municipality->id);
        $canDraft = in_array($role, ['manager', 'editor', 'auditor'], true);
        $canManage = $role === 'manager';
        $templates = $municipality->documentTemplates()->with('creator:id,name')->latest('version')->get();
        $activeTemplates = $templates->where('is_active', true)->sortBy('document_type')->values();
        $stats = $municipality->officialDocuments()
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) as drafts")
            ->selectRaw("SUM(CASE WHEN status IN ('sent', 'acknowledged') THEN 1 ELSE 0 END) as dispatched")
            ->selectRaw("SUM(CASE WHEN status = 'acknowledged' THEN 1 ELSE 0 END) as acknowledged")
            ->first();
        $documents = $municipality->officialDocuments()
            ->with(['template:id,name,version', 'amendment:id,reference', 'creator:id,name', 'issuer:id,name'])
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('type'), fn ($query) => $query->where('document_type', $request->string('type')))
            ->when($request->filled('q'), function ($query) use ($request): void {
                $search = '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim($request->string('q'))).'%';
                $query->where(fn ($nested) => $nested
                    ->where('official_number', 'like', $search)
                    ->orWhere('subject', 'like', $search)
                    ->orWhere('recipient_name', 'like', $search)
                    ->orWhere('recipient_entity', 'like', $search));
            })
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return view('official-documents.index', [
            'municipality' => $municipality,
            'documents' => $documents,
            'stats' => $stats,
            'templates' => $templates,
            'activeTemplates' => $activeTemplates,
            'canDraft' => $canDraft,
            'canManage' => $canManage,
            'installToken' => $canManage ? $formSubmission->issue($request, 'official-template-install') : null,
            'createToken' => $canDraft && $activeTemplates->isNotEmpty() ? $formSubmission->issue($request, 'official-document-create') : null,
            'templateTokens' => $canManage ? $activeTemplates->mapWithKeys(fn ($template) => [
                $template->id => $formSubmission->issue($request, "official-template-revise-{$template->id}"),
            ]) : collect(),
            'amendments' => $canDraft ? $municipality->amendments()->latest('fiscal_year')->latest('id')
                ->limit(200)->get(['id', 'reference', 'fiscal_year', 'object']) : collect(),
            'impediments' => $canDraft ? $municipality->technicalImpediments()->latest('identified_at')->limit(100)
                ->get(['id', 'parliamentary_amendment_id', 'title']) : collect(),
            'diligences' => $canDraft ? $municipality->technicalDiligences()->latest('due_at')->limit(100)
                ->get(['id', 'parliamentary_amendment_id', 'title']) : collect(),
            'reviews' => $canDraft ? $municipality->internalControlReviews()->latest('issued_at')->limit(100)
                ->get(['id', 'parliamentary_amendment_id', 'reference', 'summary']) : collect(),
            'placeholders' => $service->placeholders(),
        ]);
    }

    public function installDefaults(
        Request $request,
        CurrentMunicipality $currentMunicipality,
        FormSubmission $formSubmission,
        MunicipalOfficialDocumentService $service,
        AuditTrail $auditTrail,
    ): RedirectResponse {
        $municipality = $currentMunicipality->get($request);
        $request->validate(['_submission_token' => ['required', 'string']]);
        if (! $formSubmission->consume($request, 'official-template-install')) {
            return back()->with('warning', 'Os modelos iniciais já foram processados.');
        }
        $templates = $service->installDefaults($municipality, $request->user());
        $auditTrail->recordMunicipalityOperation($request, $municipality, 'official_templates_installed', [
            'templates' => $templates->pluck('document_type')->all(),
        ]);

        return back()->with('status', 'Modelos municipais instalados. Revise a redação antes de emitir documentos oficiais.');
    }

    public function reviseTemplate(
        Request $request,
        int $template,
        CurrentMunicipality $currentMunicipality,
        FormSubmission $formSubmission,
        MunicipalOfficialDocumentService $service,
        AuditTrail $auditTrail,
    ): RedirectResponse {
        $municipality = $currentMunicipality->get($request);
        $template = $municipality->documentTemplates()->findOrFail($template);
        $validated = $request->validate([
            '_submission_token' => ['required', 'string'],
            'name' => ['required', 'string', 'min:5', 'max:160'],
            'prefix' => ['required', 'string', 'min:2', 'max:12', 'regex:/^[A-Za-z0-9-]+$/'],
            'subject_template' => ['required', 'string', 'min:5', 'max:2000'],
            'body_template' => ['required', 'string', 'min:30', 'max:30000'],
        ], ['prefix.regex' => 'Use somente letras, números e hífen no prefixo.']);
        if (! $formSubmission->consume($request, "official-template-revise-{$template->id}")) {
            return back()->with('warning', 'Esta revisão de modelo já foi registrada.');
        }
        $revision = $service->revise($template, $request->user(), $validated);
        $auditTrail->recordMunicipalityOperation($request, $municipality, 'official_template_revised', [
            'document_type' => $revision->document_type, 'version' => $revision->version,
        ]);

        return back()->with('status', "Versão {$revision->version} do modelo ativada sem alterar documentos anteriores.");
    }

    public function store(
        Request $request,
        CurrentMunicipality $currentMunicipality,
        FormSubmission $formSubmission,
        MunicipalOfficialDocumentService $service,
        AuditTrail $auditTrail,
    ): RedirectResponse {
        $municipality = $currentMunicipality->get($request);
        $validated = $request->validate([
            '_submission_token' => ['required', 'string'],
            'municipal_document_template_id' => ['required', Rule::exists('municipal_document_templates', 'id')->where(fn ($q) => $q->where('municipality_id', $municipality->id)->where('is_active', true))],
            'parliamentary_amendment_id' => ['nullable', Rule::exists('parliamentary_amendments', 'id')->where('municipality_id', $municipality->id)],
            'technical_impediment_id' => ['nullable', Rule::exists('technical_impediments', 'id')->where('municipality_id', $municipality->id)],
            'technical_diligence_id' => ['nullable', Rule::exists('technical_diligences', 'id')->where('municipality_id', $municipality->id)],
            'municipal_internal_control_review_id' => ['nullable', Rule::exists('municipal_internal_control_reviews', 'id')->where('municipality_id', $municipality->id)],
            'fiscal_year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'recipient_name' => ['required', 'string', 'min:3', 'max:180'],
            'recipient_role' => ['nullable', 'string', 'max:180'],
            'recipient_entity' => ['required', 'string', 'min:3', 'max:180'],
            'recipient_email' => ['nullable', 'email:rfc', 'max:180'],
            'response_due_at' => ['nullable', 'date'],
            'context' => ['nullable', 'string', 'max:8000'],
            'legal_basis' => ['nullable', 'string', 'max:3000'],
        ], [
            'municipal_document_template_id.exists' => 'Escolha um modelo ativo deste Município.',
            'parliamentary_amendment_id.exists' => 'Escolha uma emenda deste Município.',
        ]);
        $sourceIds = collect(['technical_impediment_id', 'technical_diligence_id', 'municipal_internal_control_review_id'])
            ->filter(fn ($field) => filled($validated[$field] ?? null));
        if ($sourceIds->count() > 1) {
            throw ValidationException::withMessages(['technical_impediment_id' => 'Vincule somente uma origem: impedimento, diligência ou parecer.']);
        }

        $template = $municipality->documentTemplates()->where('is_active', true)->findOrFail($validated['municipal_document_template_id']);
        $amendment = filled($validated['parliamentary_amendment_id'] ?? null)
            ? $municipality->amendments()->findOrFail($validated['parliamentary_amendment_id']) : null;
        $impediment = $this->source($municipality, TechnicalImpediment::class, $validated['technical_impediment_id'] ?? null);
        $diligence = $this->source($municipality, TechnicalDiligence::class, $validated['technical_diligence_id'] ?? null);
        $review = $this->source($municipality, MunicipalInternalControlReview::class, $validated['municipal_internal_control_review_id'] ?? null);
        foreach ([$impediment, $diligence, $review] as $source) {
            if ($source && (! $amendment || $source->parliamentary_amendment_id !== $amendment->id)) {
                throw ValidationException::withMessages(['parliamentary_amendment_id' => 'A origem selecionada deve pertencer à emenda vinculada.']);
            }
        }
        $rendered = $service->render($template, $municipality, $amendment, $validated, $impediment, $diligence, $review, $validated['context'] ?? null, $validated['legal_basis'] ?? null);
        if (! $formSubmission->consume($request, 'official-document-create')) {
            return back()->with('warning', 'Esta minuta já foi gerada. Atualize a página para criar outra.');
        }

        $document = DB::transaction(function () use ($request, $municipality, $validated, $template, $amendment, $impediment, $diligence, $review, $rendered, $auditTrail): MunicipalOfficialDocument {
            $document = $municipality->officialDocuments()->create([
                'municipal_document_template_id' => $template->id,
                'parliamentary_amendment_id' => $amendment?->id,
                'technical_impediment_id' => $impediment?->id,
                'technical_diligence_id' => $diligence?->id,
                'municipal_internal_control_review_id' => $review?->id,
                'created_by' => $request->user()->id,
                'reference' => (string) Str::uuid(),
                'fiscal_year' => $validated['fiscal_year'],
                'version' => 1,
                'document_type' => $template->document_type,
                'status' => MunicipalOfficialDocument::STATUS_DRAFT,
                'recipient_name' => trim($validated['recipient_name']),
                'recipient_role' => filled($validated['recipient_role'] ?? null) ? trim($validated['recipient_role']) : null,
                'recipient_entity' => trim($validated['recipient_entity']),
                'recipient_email' => filled($validated['recipient_email'] ?? null) ? mb_strtolower(trim($validated['recipient_email'])) : null,
                'subject' => $rendered['subject'], 'body' => $rendered['body'],
                'response_due_at' => $validated['response_due_at'] ?? null,
            ]);
            $this->event($document, $request, 'drafted', 'Minuta gerada a partir do modelo municipal versionado.', [
                'template_version' => $template->version, 'variables' => $rendered['variables'],
            ]);
            $auditTrail->recordMunicipalityOperation($request, $municipality, 'official_document_drafted', [
                'reference' => $document->reference, 'document_type' => $document->document_type,
                'amendment' => $amendment?->reference,
            ]);

            return $document;
        });

        return redirect()->route('official-documents.show', $document)->with('status', 'Minuta gerada com os dados municipais. Revise o texto antes da emissão.');
    }

    public function show(Request $request, int $document, CurrentMunicipality $currentMunicipality, FormSubmission $formSubmission): View
    {
        $municipality = $currentMunicipality->get($request);
        $document = $this->document($municipality, $document);
        $role = $request->user()->roleForMunicipality($municipality->id);
        $canDraft = in_array($role, ['manager', 'editor', 'auditor'], true);
        $canManage = $role === 'manager';

        return view('official-documents.show', [
            'municipality' => $municipality, 'document' => $document,
            'canDraft' => $canDraft, 'canManage' => $canManage,
            'updateToken' => $canDraft && $document->isDraft() ? $formSubmission->issue($request, "official-document-update-{$document->id}") : null,
            'issueToken' => $canManage && $document->isDraft() ? $formSubmission->issue($request, "official-document-issue-{$document->id}") : null,
            'cancelToken' => $canManage && $document->isDraft() ? $formSubmission->issue($request, "official-document-cancel-{$document->id}") : null,
            'sendToken' => $canDraft && $document->status === MunicipalOfficialDocument::STATUS_ISSUED ? $formSubmission->issue($request, "official-document-send-{$document->id}") : null,
            'returnToken' => $canDraft && $document->status === MunicipalOfficialDocument::STATUS_SENT ? $formSubmission->issue($request, "official-document-return-{$document->id}") : null,
            'revisionToken' => $canDraft && ! $document->isDraft() ? $formSubmission->issue($request, "official-document-revise-{$document->id}") : null,
        ]);
    }

    public function update(Request $request, int $document, CurrentMunicipality $currentMunicipality, FormSubmission $formSubmission, AuditTrail $auditTrail): RedirectResponse
    {
        $municipality = $currentMunicipality->get($request);
        $document = $this->document($municipality, $document);
        abort_unless($document->isDraft(), 409, 'Somente minutas podem ser editadas.');
        $validated = $request->validate([
            '_submission_token' => ['required', 'string'], 'recipient_name' => ['required', 'string', 'min:3', 'max:180'],
            'recipient_role' => ['nullable', 'string', 'max:180'], 'recipient_entity' => ['required', 'string', 'min:3', 'max:180'],
            'recipient_email' => ['nullable', 'email:rfc', 'max:180'], 'response_due_at' => ['nullable', 'date'],
            'subject' => ['required', 'string', 'min:5', 'max:500'], 'body' => ['required', 'string', 'min:30', 'max:30000'],
        ]);
        if (! $formSubmission->consume($request, "official-document-update-{$document->id}")) {
            return back()->with('warning', 'Esta revisão da minuta já foi salva.');
        }
        DB::transaction(function () use ($request, $municipality, $document, $validated, $auditTrail): void {
            $document->update([
                'recipient_name' => trim($validated['recipient_name']),
                'recipient_role' => filled($validated['recipient_role'] ?? null) ? trim($validated['recipient_role']) : null,
                'recipient_entity' => trim($validated['recipient_entity']),
                'recipient_email' => filled($validated['recipient_email'] ?? null) ? mb_strtolower(trim($validated['recipient_email'])) : null,
                'response_due_at' => $validated['response_due_at'] ?? null,
                'subject' => trim($validated['subject']), 'body' => trim($validated['body']),
            ]);
            $this->event($document, $request, 'updated', 'Conteúdo e destinatário da minuta revisados.');
            $auditTrail->recordMunicipalityOperation($request, $municipality, 'official_document_updated', ['reference' => $document->reference]);
        });

        return back()->with('status', 'Minuta revisada. O histórico da alteração foi preservado.');
    }

    public function issue(Request $request, int $document, CurrentMunicipality $currentMunicipality, FormSubmission $formSubmission, AuditTrail $auditTrail): RedirectResponse
    {
        $municipality = $currentMunicipality->get($request);
        $document = $this->document($municipality, $document);
        $validated = $request->validate(['_submission_token' => ['required', 'string'], 'confirm_content' => ['accepted']]);
        if (! $formSubmission->consume($request, "official-document-issue-{$document->id}")) {
            return back()->with('warning', 'A emissão deste documento já foi processada.');
        }
        DB::transaction(function () use ($request, $municipality, $document, $auditTrail): void {
            Municipality::query()->whereKey($municipality->id)->lockForUpdate()->firstOrFail();
            $locked = MunicipalOfficialDocument::query()->lockForUpdate()->findOrFail($document->id);
            abort_unless($locked->isDraft(), 409, 'O documento não está mais em minuta.');
            $sequence = (int) $municipality->officialDocuments()->where('fiscal_year', $locked->fiscal_year)
                ->where('document_type', $locked->document_type)->max('sequence') + 1;
            $number = $locked->template->prefix.'-'.str_pad((string) $sequence, 5, '0', STR_PAD_LEFT).'/'.$locked->fiscal_year;
            $snapshot = $this->snapshot($locked, $number, $sequence);
            $hash = hash('sha256', json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            $locked->update([
                'issued_by' => $request->user()->id, 'sequence' => $sequence, 'official_number' => $number,
                'status' => MunicipalOfficialDocument::STATUS_ISSUED, 'snapshot' => $snapshot,
                'snapshot_sha256' => $hash, 'issued_at' => now(),
            ]);
            $this->event($locked, $request, 'issued', 'Documento emitido com conteúdo imutável e hash de integridade.', ['snapshot_sha256' => $hash]);
            $auditTrail->recordMunicipalityOperation($request, $municipality, 'official_document_issued', ['official_number' => $number, 'snapshot_sha256' => $hash]);
        });

        return back()->with('status', 'Documento numerado e emitido. O conteúdo agora é imutável.');
    }

    public function send(Request $request, int $document, CurrentMunicipality $currentMunicipality, FormSubmission $formSubmission, AuditTrail $auditTrail): RedirectResponse
    {
        $municipality = $currentMunicipality->get($request);
        $document = $this->document($municipality, $document);
        abort_unless($document->status === MunicipalOfficialDocument::STATUS_ISSUED, 409, 'Somente documentos emitidos podem ser protocolados.');
        $validated = $request->validate([
            '_submission_token' => ['required', 'string'],
            'delivery_method' => ['required', Rule::in(array_keys(MunicipalOfficialDocument::deliveryMethods()))],
            'protocol_number' => ['required', 'string', 'min:2', 'max:160'],
            'sent_at' => ['required', 'date', 'after_or_equal:'.$document->issued_at->format('Y-m-d H:i:s'), 'before_or_equal:now'],
            'message' => ['nullable', 'string', 'max:3000'],
            'evidence' => ['required', File::types(['pdf', 'txt', 'csv', 'jpg', 'jpeg', 'png', 'eml', 'msg'])->max('10mb')],
        ], ['evidence.required' => 'Anexe o comprovante do protocolo ou envio institucional.']);
        if (! $formSubmission->consume($request, "official-document-send-{$document->id}")) {
            return back()->with('warning', 'Este protocolo já foi registrado.');
        }
        $evidence = $this->storeEvidence($request->file('evidence'), $municipality->id, $document->id);
        try {
            DB::transaction(function () use ($request, $municipality, $document, $validated, $evidence, $auditTrail): void {
                $locked = MunicipalOfficialDocument::query()->lockForUpdate()->findOrFail($document->id);
                abort_unless($locked->status === MunicipalOfficialDocument::STATUS_ISSUED, 409);
                $locked->update([
                    'status' => MunicipalOfficialDocument::STATUS_SENT, 'delivery_method' => $validated['delivery_method'],
                    'protocol_number' => trim($validated['protocol_number']), 'sent_at' => $validated['sent_at'],
                ]);
                $this->event($locked, $request, 'sent', filled($validated['message'] ?? null) ? trim($validated['message']) : 'Documento enviado pelo canal oficial informado.', [], $evidence, $locked->protocol_number, $validated['sent_at']);
                $auditTrail->recordMunicipalityOperation($request, $municipality, 'official_document_sent', ['official_number' => $locked->official_number, 'protocol_number' => $locked->protocol_number, 'evidence_sha256' => $evidence['evidence_sha256']]);
            });
        } catch (Throwable $exception) {
            Storage::delete($evidence['evidence_storage_path']);
            throw $exception;
        }

        return back()->with('status', 'Envio protocolado com comprovante e hash preservados.');
    }

    public function recordReturn(Request $request, int $document, CurrentMunicipality $currentMunicipality, FormSubmission $formSubmission, AuditTrail $auditTrail): RedirectResponse
    {
        $municipality = $currentMunicipality->get($request);
        $document = $this->document($municipality, $document);
        abort_unless($document->status === MunicipalOfficialDocument::STATUS_SENT, 409, 'O documento não está aguardando retorno.');
        $validated = $request->validate([
            '_submission_token' => ['required', 'string'],
            'result' => ['required', Rule::in([MunicipalOfficialDocument::STATUS_ACKNOWLEDGED, MunicipalOfficialDocument::STATUS_REJECTED])],
            'occurred_at' => ['required', 'date', 'after_or_equal:'.$document->sent_at->format('Y-m-d H:i:s'), 'before_or_equal:now'],
            'protocol_number' => ['nullable', 'string', 'max:160'],
            'message' => ['nullable', 'required_if:result,rejected', 'string', 'max:5000'],
            'evidence' => ['required', File::types(['pdf', 'txt', 'jpg', 'jpeg', 'png', 'eml', 'msg'])->max('10mb')],
        ], ['message.required_if' => 'Descreva o motivo da devolução.', 'evidence.required' => 'Anexe a confirmação ou o documento de devolução.']);
        if (! $formSubmission->consume($request, "official-document-return-{$document->id}")) {
            return back()->with('warning', 'Este retorno já foi registrado.');
        }
        $evidence = $this->storeEvidence($request->file('evidence'), $municipality->id, $document->id);
        try {
            DB::transaction(function () use ($request, $municipality, $document, $validated, $evidence, $auditTrail): void {
                $locked = MunicipalOfficialDocument::query()->lockForUpdate()->findOrFail($document->id);
                abort_unless($locked->status === MunicipalOfficialDocument::STATUS_SENT, 409);
                $acknowledged = $validated['result'] === MunicipalOfficialDocument::STATUS_ACKNOWLEDGED;
                $locked->update([
                    'status' => $validated['result'],
                    'protocol_number' => filled($validated['protocol_number'] ?? null) ? trim($validated['protocol_number']) : $locked->protocol_number,
                    'acknowledged_at' => $acknowledged ? $validated['occurred_at'] : null,
                    'rejected_at' => $acknowledged ? null : $validated['occurred_at'],
                ]);
                $this->event($locked, $request, $validated['result'], filled($validated['message'] ?? null) ? trim($validated['message']) : 'Recebimento institucional confirmado.', [], $evidence, $locked->protocol_number, $validated['occurred_at']);
                $auditTrail->recordMunicipalityOperation($request, $municipality, 'official_document_returned', ['official_number' => $locked->official_number, 'result' => $locked->status, 'evidence_sha256' => $evidence['evidence_sha256']]);
            });
        } catch (Throwable $exception) {
            Storage::delete($evidence['evidence_storage_path']);
            throw $exception;
        }

        return back()->with('status', $validated['result'] === MunicipalOfficialDocument::STATUS_ACKNOWLEDGED ? 'Recebimento confirmado e comprovado.' : 'Devolução preservada. Gere uma nova versão para corrigir o documento.');
    }

    public function revise(Request $request, int $document, CurrentMunicipality $currentMunicipality, FormSubmission $formSubmission, AuditTrail $auditTrail): RedirectResponse
    {
        $municipality = $currentMunicipality->get($request);
        $document = $this->document($municipality, $document);
        abort_if($document->isDraft(), 409, 'A minuta atual ainda pode ser editada.');
        $request->validate(['_submission_token' => ['required', 'string']]);
        if (! $formSubmission->consume($request, "official-document-revise-{$document->id}")) {
            return back()->with('warning', 'A nova versão já foi preparada.');
        }
        $revision = DB::transaction(function () use ($request, $municipality, $document, $auditTrail): MunicipalOfficialDocument {
            $latest = $municipality->officialDocuments()->where(fn ($q) => $q->whereKey($document->id)->orWhere('supersedes_id', $document->id))->max('version');
            $template = $municipality->documentTemplates()->where('document_type', $document->document_type)->where('is_active', true)->first() ?: $document->template;
            $revision = $municipality->officialDocuments()->create([
                'municipal_document_template_id' => $template->id,
                'parliamentary_amendment_id' => $document->parliamentary_amendment_id,
                'technical_impediment_id' => $document->technical_impediment_id,
                'technical_diligence_id' => $document->technical_diligence_id,
                'municipal_internal_control_review_id' => $document->municipal_internal_control_review_id,
                'created_by' => $request->user()->id, 'supersedes_id' => $document->id,
                'reference' => (string) Str::uuid(), 'fiscal_year' => $document->fiscal_year,
                'version' => (int) $latest + 1, 'document_type' => $document->document_type,
                'status' => MunicipalOfficialDocument::STATUS_DRAFT,
                'recipient_name' => $document->recipient_name, 'recipient_role' => $document->recipient_role,
                'recipient_entity' => $document->recipient_entity, 'recipient_email' => $document->recipient_email,
                'subject' => $document->subject, 'body' => $document->body, 'response_due_at' => $document->response_due_at,
            ]);
            $this->event($revision, $request, 'revision_created', 'Nova versão criada sem alterar o documento anterior.', ['supersedes' => $document->official_number]);
            $auditTrail->recordMunicipalityOperation($request, $municipality, 'official_document_revision_created', ['previous' => $document->official_number, 'revision_reference' => $revision->reference]);

            return $revision;
        });

        return redirect()->route('official-documents.show', $revision)->with('status', 'Nova versão em minuta. O documento anterior permanece preservado.');
    }

    public function cancel(Request $request, int $document, CurrentMunicipality $currentMunicipality, FormSubmission $formSubmission, AuditTrail $auditTrail): RedirectResponse
    {
        $municipality = $currentMunicipality->get($request);
        $document = $this->document($municipality, $document);
        $validated = $request->validate(['_submission_token' => ['required', 'string'], 'reason' => ['required', 'string', 'min:5', 'max:2000']]);
        if (! $formSubmission->consume($request, "official-document-cancel-{$document->id}")) {
            return back()->with('warning', 'O cancelamento já foi registrado.');
        }
        abort_unless($document->isDraft(), 409, 'Somente minutas podem ser canceladas.');
        DB::transaction(function () use ($request, $municipality, $document, $validated, $auditTrail): void {
            $document->update(['status' => MunicipalOfficialDocument::STATUS_CANCELLED, 'cancelled_at' => now()]);
            $this->event($document, $request, 'cancelled', trim($validated['reason']));
            $auditTrail->recordMunicipalityOperation($request, $municipality, 'official_document_cancelled', ['reference' => $document->reference, 'reason' => trim($validated['reason'])]);
        });

        return back()->with('status', 'Minuta cancelada com justificativa preservada.');
    }

    public function pdf(Request $request, int $document, CurrentMunicipality $currentMunicipality, AuditTrail $auditTrail): Response
    {
        $municipality = $currentMunicipality->get($request);
        $document = $this->document($municipality, $document);
        $auditTrail->recordMunicipalityOperation($request, $municipality, 'official_document_pdf_downloaded', ['official_number' => $document->official_number, 'reference' => $document->reference]);

        return Pdf::loadView('official-documents.pdf', compact('document', 'municipality'))->setPaper('a4')
            ->download(Str::slug($document->official_number ?: 'minuta-'.$document->reference).'.pdf');
    }

    public function evidence(Request $request, int $document, int $event, CurrentMunicipality $currentMunicipality, AuditTrail $auditTrail): StreamedResponse
    {
        $municipality = $currentMunicipality->get($request);
        $document = $this->document($municipality, $document);
        $event = $document->events()->findOrFail($event);
        abort_unless($event->evidence_storage_path && Storage::exists($event->evidence_storage_path), 404);
        $auditTrail->recordMunicipalityOperation($request, $municipality, 'official_document_evidence_downloaded', ['reference' => $document->reference, 'event_id' => $event->id, 'evidence_sha256' => $event->evidence_sha256]);

        return Storage::download($event->evidence_storage_path, $event->evidence_original_name, ['Content-Type' => $event->evidence_mime_type, 'X-Content-Type-Options' => 'nosniff']);
    }

    private function document(Municipality $municipality, int $id): MunicipalOfficialDocument
    {
        return $municipality->officialDocuments()->with([
            'template.creator:id,name', 'amendment:id,reference,object,author_name,administrative_process,responsible_department',
            'impediment:id,title', 'diligence:id,title', 'internalControlReview:id,reference',
            'creator:id,name', 'issuer:id,name', 'supersedes:id,official_number,reference',
            'revisions:id,supersedes_id,official_number,reference,status,version', 'events.creator:id,name',
        ])->findOrFail($id);
    }

    private function source(Municipality $municipality, string $class, mixed $id): mixed
    {
        return filled($id) ? $class::query()->where('municipality_id', $municipality->id)->findOrFail($id) : null;
    }

    private function event(MunicipalOfficialDocument $document, Request $request, string $type, string $message, array $metadata = [], array $evidence = [], ?string $protocol = null, mixed $occurredAt = null): void
    {
        $document->events()->create([
            'municipality_id' => $document->municipality_id, 'created_by' => $request->user()->id,
            'type' => $type, 'occurred_at' => $occurredAt ?: now(), 'protocol_number' => $protocol,
            'message' => $message, 'metadata' => $metadata ?: null, ...$evidence,
        ]);
    }

    private function snapshot(MunicipalOfficialDocument $document, string $number, int $sequence): array
    {
        return [
            'official_number' => $number, 'sequence' => $sequence, 'fiscal_year' => $document->fiscal_year,
            'version' => $document->version, 'document_type' => $document->document_type,
            'subject' => $document->subject, 'body' => $document->body,
            'recipient' => ['name' => $document->recipient_name, 'role' => $document->recipient_role, 'entity' => $document->recipient_entity, 'email' => $document->recipient_email],
            'response_due_at' => $document->response_due_at?->toDateString(),
            'municipality' => $document->municipality->only(['id', 'name', 'state', 'cnpj', 'ibge_code']),
            'template' => $document->template->only(['id', 'document_type', 'name', 'prefix', 'version']),
            'amendment' => $document->amendment?->only(['id', 'reference', 'fiscal_year', 'object', 'author_name', 'administrative_process', 'responsible_department']),
            'sources' => ['impediment_id' => $document->technical_impediment_id, 'diligence_id' => $document->technical_diligence_id, 'internal_control_review_id' => $document->municipal_internal_control_review_id],
            'issued_at' => now()->toIso8601String(), 'issued_by' => ['id' => auth()->id(), 'name' => auth()->user()->name],
        ];
    }

    private function storeEvidence(UploadedFile $file, int $municipalityId, int $documentId): array
    {
        $extension = $file->extension() ?: strtolower($file->getClientOriginalExtension());
        $path = Storage::putFileAs("official-documents/{$municipalityId}/{$documentId}/evidence", $file, Str::uuid().'.'.$extension);
        if (! $path) {
            throw ValidationException::withMessages(['evidence' => 'Não foi possível armazenar o comprovante com segurança.']);
        }

        return [
            'evidence_original_name' => Str::of(basename($file->getClientOriginalName()))->replaceMatches('/[\x00-\x1F\x7F]/u', '')->limit(255, '')->toString(),
            'evidence_storage_path' => $path, 'evidence_mime_type' => $file->getMimeType() ?: 'application/octet-stream',
            'evidence_size_bytes' => $file->getSize(), 'evidence_sha256' => hash_file('sha256', $file->getRealPath()),
        ];
    }
}
