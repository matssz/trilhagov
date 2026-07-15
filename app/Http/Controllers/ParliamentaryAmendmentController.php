<?php

namespace App\Http\Controllers;

use App\Http\Requests\ParliamentaryAmendmentRequest;
use App\Models\Municipality;
use App\Models\ParliamentaryAmendment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ParliamentaryAmendmentController extends Controller
{
    public function index(Request $request): View
    {
        $municipality = $this->municipalityForUser($request);
        $search = trim((string) $request->query('search'));
        $status = (string) $request->query('status');
        $year = (string) $request->query('year');

        $amendments = $municipality->amendments()
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
            'statuses' => ParliamentaryAmendment::statuses(),
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(Request $request): View
    {
        return view('amendments.create', $this->formOptions($request));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(ParliamentaryAmendmentRequest $request): RedirectResponse
    {
        $municipality = $this->municipalityForUser($request);
        $amendment = $municipality->amendments()->create([
            ...$request->validated(),
            'created_by' => $request->user()->id,
        ]);

        return redirect()
            ->route('emendas.show', $amendment)
            ->with('status', 'Emenda cadastrada com sucesso.');
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, int $emenda): View
    {
        return view('amendments.show', [
            'amendment' => $this->amendmentForUser($request, $emenda)->load(['municipality', 'creator']),
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
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(ParliamentaryAmendmentRequest $request, int $emenda): RedirectResponse
    {
        $amendment = $this->amendmentForUser($request, $emenda);
        $amendment->update($request->validated());

        return redirect()
            ->route('emendas.show', $amendment)
            ->with('status', 'Emenda atualizada com sucesso.');
    }

    /**
     * Remove the specified resource from storage.
     */
    private function municipalityForUser(Request $request): Municipality
    {
        return $request->user()->municipalities()->firstOrFail();
    }

    private function amendmentForUser(Request $request, int $amendmentId): ParliamentaryAmendment
    {
        return ParliamentaryAmendment::query()
            ->forUser($request->user())
            ->findOrFail($amendmentId);
    }

    /** @return array<string, mixed> */
    private function formOptions(Request $request): array
    {
        return [
            'municipality' => $this->municipalityForUser($request),
            'statuses' => ParliamentaryAmendment::statuses(),
            'governmentSpheres' => ParliamentaryAmendment::governmentSpheres(),
            'authorshipTypes' => ParliamentaryAmendment::authorshipTypes(),
            'transferTypes' => ParliamentaryAmendment::transferTypes(),
        ];
    }
}
