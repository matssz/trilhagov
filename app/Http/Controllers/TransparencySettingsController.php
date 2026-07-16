<?php

namespace App\Http\Controllers;

use App\Models\Municipality;
use App\Services\AuditTrail;
use App\Services\CurrentMunicipality;
use App\Services\FormSubmission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TransparencySettingsController extends Controller
{
    public function __invoke(
        Request $request,
        CurrentMunicipality $currentMunicipality,
        FormSubmission $formSubmission,
        AuditTrail $auditTrail,
    ): RedirectResponse {
        $municipality = $currentMunicipality->get($request);
        $validated = $request->validate([
            '_submission_token' => ['required', 'string'],
            'transparency_enabled' => ['nullable', 'boolean'],
        ]);

        if (! $formSubmission->consume($request, "transparency-settings-{$municipality->id}")) {
            return back()->with('warning', 'Esta configuração já foi processada.');
        }

        $enabled = (bool) ($validated['transparency_enabled'] ?? false);
        $oldEnabled = $municipality->transparency_enabled;
        $slug = $municipality->transparency_slug ?? $this->uniqueSlug($municipality);

        $municipality->update([
            'transparency_enabled' => $enabled,
            'transparency_slug' => $slug,
            'transparency_updated_at' => now(),
        ]);

        $auditTrail->recordMunicipalityOperation($request, $municipality, 'transparency_updated', [
            'transparency_enabled' => $enabled,
        ], [
            'transparency_enabled' => $oldEnabled,
        ]);

        return back()->with('status', $enabled
            ? 'Portal de transparência publicado com sucesso.'
            : 'Portal de transparência retirado do ar.');
    }

    private function uniqueSlug(Municipality $municipality): string
    {
        do {
            $slug = Str::slug("{$municipality->name}-{$municipality->state}").'-'.Str::lower(Str::random(8));
        } while (Municipality::query()->where('transparency_slug', $slug)->exists());

        return $slug;
    }
}
