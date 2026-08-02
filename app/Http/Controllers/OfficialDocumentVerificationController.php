<?php

namespace App\Http\Controllers;

use App\Models\MunicipalOfficialDocument;
use Illuminate\View\View;

class OfficialDocumentVerificationController extends Controller
{
    public function __invoke(string $hash): View
    {
        $document = MunicipalOfficialDocument::query()
            ->with(['municipality', 'issuer', 'events'])
            ->where('snapshot_sha256', mb_strtolower($hash))
            ->firstOrFail();

        abort_if($document->isDraft(), 404);

        return view('official-documents.verify', [
            'document' => $document,
            'lastEvent' => $document->events->sortByDesc('occurred_at')->first(),
        ]);
    }
}
