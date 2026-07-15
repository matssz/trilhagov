<?php

namespace App\Http\Controllers;

use App\Services\AuditTrail;
use App\Services\CurrentMunicipality;
use App\Services\FormSubmission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class DocumentTypeController extends Controller
{
    public function index(
        Request $request,
        CurrentMunicipality $currentMunicipality,
        FormSubmission $formSubmission,
    ): View {
        $municipality = $currentMunicipality->get($request);

        return view('document-types.index', [
            'municipality' => $municipality,
            'documentTypes' => $municipality->documentTypes()
                ->withCount('documents')
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(),
            'submissionToken' => $formSubmission->issue($request, 'document-type-create'),
        ]);
    }

    public function store(
        Request $request,
        CurrentMunicipality $currentMunicipality,
        FormSubmission $formSubmission,
        AuditTrail $auditTrail,
    ): RedirectResponse {
        $municipality = $currentMunicipality->get($request);
        $request->merge([
            'name' => trim((string) $request->input('name')),
            'description' => trim((string) $request->input('description')),
        ]);
        $validated = $request->validate([
            '_submission_token' => ['required', 'string'],
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('document_types')->where('municipality_id', $municipality->id),
            ],
            'description' => ['nullable', 'string', 'max:500'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:10000'],
        ]);

        if (! $formSubmission->consume($request, 'document-type-create')) {
            return back()->with('warning', 'Este tipo de documento já foi processado.');
        }

        DB::transaction(function () use ($request, $municipality, $validated, $auditTrail): void {
            $documentType = $municipality->documentTypes()->create([
                'created_by' => $request->user()->id,
                'name' => $validated['name'],
                'description' => $validated['description'] ?: null,
                'is_required' => $request->boolean('is_required'),
                'is_active' => true,
                'sort_order' => $validated['sort_order'] ?? 0,
            ]);
            $auditTrail->recordDocumentTypeCreation($request, $municipality, $documentType);
        });

        return back()->with('status', 'Tipo de documento adicionado ao checklist.');
    }

    public function update(
        Request $request,
        int $documentType,
        CurrentMunicipality $currentMunicipality,
        AuditTrail $auditTrail,
    ): RedirectResponse {
        $municipality = $currentMunicipality->get($request);
        $type = $municipality->documentTypes()->findOrFail($documentType);
        $request->merge([
            'name' => trim((string) $request->input('name')),
            'description' => trim((string) $request->input('description')),
        ]);
        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('document_types')
                    ->where('municipality_id', $municipality->id)
                    ->ignore($type->id),
            ],
            'description' => ['nullable', 'string', 'max:500'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:10000'],
        ]);
        $newValues = [
            ...$validated,
            'description' => $validated['description'] ?: null,
            'is_required' => $request->boolean('is_required'),
            'is_active' => $request->boolean('is_active'),
        ];

        if ($type->only(array_keys($newValues)) === $newValues) {
            return back()->with('warning', 'Nenhuma configuração foi alterada.');
        }

        $oldValues = $type->only(array_keys($newValues));
        $changedValues = collect($newValues)
            ->filter(fn ($value, $field) => $oldValues[$field] !== $value)
            ->all();

        DB::transaction(function () use ($request, $municipality, $type, $oldValues, $changedValues, $auditTrail): void {
            $type->update($changedValues);
            $auditTrail->recordDocumentTypeUpdate(
                $request,
                $municipality,
                $type,
                array_intersect_key($oldValues, $changedValues),
                $changedValues,
            );
        });

        return back()->with('status', "Checklist atualizado: {$type->name}.");
    }
}
