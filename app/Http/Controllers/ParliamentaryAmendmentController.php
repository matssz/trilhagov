<?php

namespace App\Http\Controllers;

use App\Http\Requests\ParliamentaryAmendmentRequest;
use App\Models\Municipality;
use App\Models\ParliamentaryAmendment;
use App\Services\AuditTrail;
use App\Services\CurrentMunicipality;
use App\Services\FormSubmission;
use App\Services\IntegrityAlertService;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class ParliamentaryAmendmentController extends Controller
{
    public function __construct(
        private readonly CurrentMunicipality $currentMunicipality,
        private readonly FormSubmission $formSubmission,
        private readonly AuditTrail $auditTrail,
        private readonly IntegrityAlertService $integrityAlertService,
    ) {}

    public function index(Request $request): View
    {
        $municipality = $this->municipalityForUser($request);
        $search = trim((string) $request->query('search'));
        $status = (string) $request->query('status');
        $year = (string) $request->query('year');
        $risk = (string) $request->query('risk');
        $this->integrityAlertService->sync($municipality);

        $amendments = $municipality->amendments()
            ->with('responsibleUser')
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($query) use ($search) {
                    $query->where('reference', 'like', "%{$search}%")
                        ->orWhere('object', 'like', "%{$search}%")
                        ->orWhere('author_name', 'like', "%{$search}%")
                        ->orWhere('responsible_department', 'like', "%{$search}%");
                });
            })
            ->when(array_key_exists($status, ParliamentaryAmendment::statuses()), fn ($query) => $query->where('status', $status))
            ->when(ctype_digit($year), fn ($query) => $query->where('fiscal_year', (int) $year))
            ->when(in_array($risk, ['low', 'moderate', 'high', 'critical'], true), fn ($query) => $query->where('risk_level', $risk))
            ->latest('fiscal_year')
            ->latest('id')
            ->paginate(12)
            ->withQueryString();

        return view('amendments.index', [
            'municipality' => $municipality,
            'amendments' => $amendments,
            'search' => $search,
            'selectedStatus' => $status,
            'selectedYear' => $year,
            'selectedRisk' => $risk,
            'statuses' => ParliamentaryAmendment::statuses(),
            'canEdit' => $request->user()->canEditMunicipality($municipality->id),
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(Request $request): View
    {
        return view('amendments.create', [
            ...$this->formOptions($request),
            'submissionToken' => $this->formSubmission->issue($request, 'amendment-create'),
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(ParliamentaryAmendmentRequest $request): RedirectResponse
    {
        if (! $this->formSubmission->consume($request, 'amendment-create')) {
            return redirect()
                ->route('emendas.index')
                ->with('warning', 'Esta solicitação já foi processada.');
        }

        $municipality = $this->municipalityForUser($request);

        try {
            $amendment = DB::transaction(function () use ($request, $municipality): ParliamentaryAmendment {
                $amendment = $municipality->amendments()->create([
                    ...$request->safe()->except('_submission_token'),
                    'created_by' => $request->user()->id,
                ]);
                $this->auditTrail->recordCreation($request, $amendment);

                return $amendment;
            });
        } catch (QueryException $exception) {
            if ((string) $exception->getCode() !== '23000') {
                throw $exception;
            }

            return back()
                ->withInput()
                ->withErrors(['reference' => 'Esta emenda já foi cadastrada para o município, esfera e exercício.']);
        }

        $this->integrityAlertService->sync($municipality->fresh());

        return redirect()
            ->route('emendas.show', $amendment)
            ->with('status', 'Emenda cadastrada com sucesso.');
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, int $emenda): View
    {
        $amendment = $this->amendmentForUser($request, $emenda)
            ->load(['municipality', 'creator', 'responsibleUser', 'auditLogs', 'documents.documentType']);
        $documentTypes = $amendment->municipality->documentTypes()
            ->active()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
        $latestDocumentsByType = $amendment->documents
            ->groupBy('document_type_id')
            ->map(fn ($documents) => $documents->sortByDesc('version')->first());
        $completedTypes = $documentTypes
            ->filter(fn ($type) => $latestDocumentsByType->has($type->id));
        $requiredPending = $documentTypes
            ->where('is_required', true)
            ->reject(fn ($type) => $latestDocumentsByType->has($type->id))
            ->count();
        $canEdit = $request->user()->canEditMunicipality($amendment->municipality_id);

        return view('amendments.show', [
            'amendment' => $amendment,
            'canEdit' => $canEdit,
            'canManageChecklist' => $request->user()->roleForMunicipality($amendment->municipality_id) === 'manager',
            'documentTypes' => $documentTypes,
            'documents' => $amendment->documents,
            'latestDocumentsByType' => $latestDocumentsByType,
            'checklistCompleted' => $completedTypes->count(),
            'checklistTotal' => $documentTypes->count(),
            'requiredPending' => $requiredPending,
            'documentSubmissionToken' => $canEdit
                ? $this->formSubmission->issue($request, "amendment-document-upload-{$amendment->id}")
                : null,
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Request $request, int $emenda): View
    {
        return view('amendments.edit', [
            ...$this->formOptions($request),
            'amendment' => $this->amendmentForUser($request, $emenda),
            'submissionToken' => $this->formSubmission->issue($request, "amendment-update-{$emenda}"),
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(ParliamentaryAmendmentRequest $request, int $emenda): RedirectResponse
    {
        if (! $this->formSubmission->consume($request, "amendment-update-{$emenda}")) {
            return redirect()
                ->route('emendas.show', $emenda)
                ->with('warning', 'Esta solicitação já foi processada.');
        }

        $amendment = $this->amendmentForUser($request, $emenda);
        DB::transaction(function () use ($request, $amendment): void {
            $amendment->fill($request->safe()->except('_submission_token'));
            $oldValues = $amendment->getOriginal();
            $amendment->save();
            $this->auditTrail->recordUpdate(
                $request,
                $amendment,
                $oldValues,
                $amendment->getChanges(),
            );
        });
        $this->integrityAlertService->sync($amendment->municipality()->firstOrFail());

        return redirect()
            ->route('emendas.show', $amendment)
            ->with('status', 'Emenda atualizada com sucesso.');
    }

    /**
     * Remove the specified resource from storage.
     */
    private function municipalityForUser(Request $request): Municipality
    {
        return $this->currentMunicipality->get($request);
    }

    private function amendmentForUser(Request $request, int $amendmentId): ParliamentaryAmendment
    {
        return $this->municipalityForUser($request)
            ->amendments()
            ->findOrFail($amendmentId);
    }

    /** @return array<string, mixed> */
    private function formOptions(Request $request): array
    {
        $municipality = $this->municipalityForUser($request);

        return [
            'municipality' => $municipality,
            'responsibleUsers' => $municipality->users()
                ->wherePivotIn('role', ['manager', 'editor'])
                ->orderBy('name')
                ->get(),
            'statuses' => ParliamentaryAmendment::statuses(),
            'governmentSpheres' => ParliamentaryAmendment::governmentSpheres(),
            'authorshipTypes' => ParliamentaryAmendment::authorshipTypes(),
            'transferTypes' => ParliamentaryAmendment::transferTypes(),
        ];
    }
}
