<?php

namespace App\Services;

use App\Models\Municipality;
use Illuminate\Http\Request;

class CurrentMunicipality
{
    public function get(Request $request): Municipality
    {
        $municipalityId = (int) $request->session()->get('active_municipality_id');

        return $request->user()
            ->municipalities()
            ->complete()
            ->findOrFail($municipalityId);
    }

    public function activate(Request $request, Municipality $municipality): void
    {
        abort_unless($municipality->hasCompleteProfile(), 403);
        abort_unless(
            $request->user()->municipalities()->whereKey($municipality->id)->exists(),
            403,
        );

        $request->session()->put('active_municipality_id', $municipality->id);
    }
}
