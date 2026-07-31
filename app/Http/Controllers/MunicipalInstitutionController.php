<?php

namespace App\Http\Controllers;

use App\Models\MunicipalInstitution;
use App\Services\AuditTrail;
use App\Services\CurrentMunicipality;
use App\Services\FormSubmission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class MunicipalInstitutionController extends Controller
{
    public function index(Request $request, CurrentMunicipality $currentMunicipality, FormSubmission $formSubmission): View
    {
        $municipality = $currentMunicipality->get($request);
        $type = (string) $request->query('type');
        $search = trim((string) $request->query('search'));
        $status = (string) $request->query('status', 'active');
        $canEdit = $request->user()->canEditMunicipality($municipality->id);
        $base = $municipality->institutions();

        $institutions = (clone $base)
            ->when(array_key_exists($type, MunicipalInstitution::types()), fn ($query) => $query->where('type', $type))
            ->when($status === 'inactive', fn ($query) => $query->where('is_active', false))
            ->when($status !== 'inactive', fn ($query) => $query->where('is_active', true))
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(fn ($nested) => $nested
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('legal_name', 'like', "%{$search}%")
                    ->orWhere('document', 'like', "%{$search}%")
                    ->orWhere('department', 'like', "%{$search}%"));
            })
            ->orderBy('type')
            ->orderBy('name')
            ->paginate(18)
            ->withQueryString();
        $all = (clone $base)->get(['type', 'is_active']);

        return view('municipal-institutions.index', [
            'municipality' => $municipality,
            'institutions' => $institutions,
            'types' => MunicipalInstitution::types(),
            'selectedType' => $type,
            'selectedStatus' => $status,
            'search' => $search,
            'canEdit' => $canEdit,
            'createToken' => $canEdit ? $formSubmission->issue($request, 'municipal-institution-create') : null,
            'updateTokens' => $canEdit
                ? $institutions->getCollection()->mapWithKeys(fn (MunicipalInstitution $institution) => [
                    $institution->id => $formSubmission->issue($request, "municipal-institution-update-{$institution->id}"),
                ])
                : collect(),
            'metrics' => [
                'total' => $all->count(),
                'active' => $all->where('is_active', true)->count(),
                'departments' => $all->where('type', MunicipalInstitution::TYPE_DEPARTMENT)->where('is_active', true)->count(),
                'councilors' => $all->where('type', MunicipalInstitution::TYPE_COUNCILOR)->where('is_active', true)->count(),
                'suppliers' => $all->where('type', MunicipalInstitution::TYPE_SUPPLIER)->where('is_active', true)->count(),
                'beneficiaries' => $all->where('type', MunicipalInstitution::TYPE_BENEFICIARY)->where('is_active', true)->count(),
            ],
        ]);
    }

    public function store(
        Request $request,
        CurrentMunicipality $currentMunicipality,
        FormSubmission $formSubmission,
        AuditTrail $auditTrail,
    ): RedirectResponse {
        $municipality = $currentMunicipality->get($request);
        if (! $formSubmission->has($request, 'municipal-institution-create')) {
            return back()->with('warning', 'Este cadastro institucional ja foi processado.');
        }
        $validated = $this->validateInstitution($request, $municipality->id);
        $formSubmission->consume($request, 'municipal-institution-create');

        $institution = $municipality->institutions()->create([
            ...$this->attributes($validated),
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);
        $auditTrail->recordMunicipalityOperation($request, $municipality, 'municipal_institution_created', [
            'institution_id' => $institution->id,
            'type' => $institution->type,
            'name' => $institution->name,
        ]);

        return redirect()
            ->route('municipal-institutions.index', ['type' => $institution->type])
            ->with('status', 'Cadastro institucional criado.');
    }

    public function update(
        Request $request,
        int $institution,
        CurrentMunicipality $currentMunicipality,
        FormSubmission $formSubmission,
        AuditTrail $auditTrail,
    ): RedirectResponse {
        $municipality = $currentMunicipality->get($request);
        $institution = $municipality->institutions()->findOrFail($institution);
        if (! $formSubmission->has($request, "municipal-institution-update-{$institution->id}")) {
            return back()->with('warning', 'Esta alteracao ja foi processada.');
        }
        $validated = $this->validateInstitution($request, $municipality->id, $institution->id);
        $formSubmission->consume($request, "municipal-institution-update-{$institution->id}");
        $old = $institution->only(['type', 'name', 'document', 'department', 'is_active']);
        $institution->update([
            ...$this->attributes($validated),
            'is_active' => $request->boolean('is_active'),
            'updated_by' => $request->user()->id,
        ]);
        $auditTrail->recordMunicipalityOperation($request, $municipality, 'municipal_institution_updated', [
            'institution_id' => $institution->id,
            'type' => $institution->type,
            'name' => $institution->name,
            'is_active' => $institution->is_active,
        ], $old);

        return back()->with('status', 'Cadastro institucional atualizado.');
    }

    /** @return array<string, mixed> */
    private function validateInstitution(Request $request, int $municipalityId, ?int $ignoreId = null): array
    {
        return $request->validate([
            'type' => ['required', Rule::in(array_keys(MunicipalInstitution::types()))],
            'name' => [
                'required',
                'string',
                'min:3',
                'max:255',
                Rule::unique('municipal_institutions', 'name')
                    ->where('municipality_id', $municipalityId)
                    ->where('type', (string) $request->input('type'))
                    ->ignore($ignoreId),
            ],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'document' => ['nullable', 'string', 'max:20'],
            'party' => ['nullable', 'string', 'max:30'],
            'department' => ['nullable', 'string', 'max:255'],
            'role_title' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:120'],
            'state' => ['nullable', 'string', 'size:2'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['nullable', 'boolean'],
        ], [
            'name.unique' => 'Ja existe um cadastro com este nome neste tipo.',
            'state.size' => 'Informe a UF com duas letras.',
        ]);
    }

    /** @param array<string, mixed> $validated @return array<string, mixed> */
    private function attributes(array $validated): array
    {
        $document = preg_replace('/\D/', '', (string) ($validated['document'] ?? ''));

        return [
            'type' => $validated['type'],
            'name' => trim((string) $validated['name']),
            'legal_name' => filled($validated['legal_name'] ?? null) ? trim((string) $validated['legal_name']) : null,
            'document' => $document !== '' ? $document : null,
            'party' => filled($validated['party'] ?? null) ? mb_strtoupper(trim((string) $validated['party'])) : null,
            'department' => filled($validated['department'] ?? null) ? trim((string) $validated['department']) : null,
            'role_title' => filled($validated['role_title'] ?? null) ? trim((string) $validated['role_title']) : null,
            'email' => filled($validated['email'] ?? null) ? mb_strtolower(trim((string) $validated['email'])) : null,
            'phone' => filled($validated['phone'] ?? null) ? trim((string) $validated['phone']) : null,
            'address' => filled($validated['address'] ?? null) ? trim((string) $validated['address']) : null,
            'city' => filled($validated['city'] ?? null) ? trim((string) $validated['city']) : null,
            'state' => filled($validated['state'] ?? null) ? mb_strtoupper(trim((string) $validated['state'])) : null,
            'notes' => filled($validated['notes'] ?? null) ? trim((string) $validated['notes']) : null,
        ];
    }
}
