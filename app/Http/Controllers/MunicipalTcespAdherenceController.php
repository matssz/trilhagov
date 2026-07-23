<?php

namespace App\Http\Controllers;

use App\Services\CurrentMunicipality;
use App\Services\MunicipalTcespAdherenceService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MunicipalTcespAdherenceController extends Controller
{
    public function __invoke(
        Request $request,
        CurrentMunicipality $currentMunicipality,
        MunicipalTcespAdherenceService $adherence,
    ): View {
        $municipality = $currentMunicipality->get($request);
        abort_unless($municipality->supportsTcespAudesp(), 404);

        $years = $adherence->availableYears($municipality);
        $year = $request->integer('ano');
        if (! $years->contains($year)) {
            $year = (int) ($years->first() ?? now()->year);
        }

        $canEdit = $request->user()->canEditMunicipality($municipality->id);

        return view('municipal-tcesp-adherence.index', [
            'municipality' => $municipality,
            'years' => $years,
            'canEdit' => $canEdit,
            ...$adherence->evaluate($municipality, $year, $canEdit),
        ]);
    }
}
