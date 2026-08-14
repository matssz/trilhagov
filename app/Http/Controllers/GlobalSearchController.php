<?php

namespace App\Http\Controllers;

use App\Models\Municipality;
use App\Services\GlobalSearchService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class GlobalSearchController extends Controller
{
    public function __invoke(Request $request, GlobalSearchService $search): View
    {
        /** @var Municipality $municipality */
        $municipality = $request->attributes->get('active_municipality');

        return view('search.index', [
            'municipality' => $municipality,
            'search' => $search->search($municipality, $request->user(), $request->query('search')),
        ]);
    }
}
