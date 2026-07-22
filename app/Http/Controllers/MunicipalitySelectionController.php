<?php

namespace App\Http\Controllers;

use App\Services\CurrentMunicipality;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class MunicipalitySelectionController extends Controller
{
    public function index(Request $request, CurrentMunicipality $currentMunicipality): View|RedirectResponse
    {
        $municipalities = $request->user()->municipalities()->complete()->orderBy('name')->get();

        if ($municipalities->count() === 1) {
            $currentMunicipality->activate($request, $municipalities->first());

            return redirect()->intended(route($request->user()->landingRouteName($municipalities->first()->id)));
        }

        return view('municipalities.select', ['municipalities' => $municipalities]);
    }

    public function store(Request $request, CurrentMunicipality $currentMunicipality): RedirectResponse
    {
        $request->validate([
            'municipality_id' => [
                'required',
                'integer',
                Rule::exists('municipality_user', 'municipality_id')
                    ->where('user_id', $request->user()->id),
            ],
        ]);

        $municipality = $request->user()
            ->municipalities()
            ->complete()
            ->findOrFail($request->integer('municipality_id'));
        $currentMunicipality->activate($request, $municipality);

        return redirect()->intended(route($request->user()->landingRouteName($municipality->id)));
    }
}
