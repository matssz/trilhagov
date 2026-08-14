<?php

namespace App\Http\Controllers;

use App\Models\Municipality;
use App\Models\SupportOccurrence;
use App\Services\OccurrenceCenterService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class OccurrenceCenterController extends Controller
{
    public function index(Request $request, OccurrenceCenterService $service): View
    {
        /** @var Municipality $municipality */
        $municipality = $request->attributes->get('active_municipality');

        return view('occurrences.index', [
            'municipality' => $municipality,
            'panel' => $service->dashboard($municipality, $request->only(['status', 'source', 'level'])),
            'statuses' => SupportOccurrence::statuses(),
        ]);
    }

    public function update(Request $request, SupportOccurrence $occurrence): RedirectResponse
    {
        /** @var Municipality $municipality */
        $municipality = $request->attributes->get('active_municipality');
        abort_if($occurrence->municipality_id !== null && $occurrence->municipality_id !== $municipality->id, 404);

        $validated = $request->validate([
            'status' => ['required', Rule::in(array_keys(SupportOccurrence::statuses()))],
            'resolution_notes' => ['nullable', 'string', 'max:4000'],
        ]);

        $resolved = $validated['status'] === SupportOccurrence::STATUS_RESOLVED;

        $occurrence->forceFill([
            'status' => $validated['status'],
            'resolution_notes' => $validated['resolution_notes'] ?? null,
            'resolved_by' => $resolved ? $request->user()->id : null,
            'resolved_at' => $resolved ? now() : null,
        ])->save();

        return back()->with('status', $resolved ? 'Ocorrência marcada como resolvida.' : 'Ocorrência atualizada para monitoramento.');
    }
}
