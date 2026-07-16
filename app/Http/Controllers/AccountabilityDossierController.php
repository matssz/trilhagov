<?php

namespace App\Http\Controllers;

use App\Models\ParliamentaryAmendment;
use App\Services\AccountabilityService;
use App\Services\CurrentMunicipality;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use ZipArchive;

class AccountabilityDossierController extends Controller
{
    public function pdf(Request $request, int $emenda, CurrentMunicipality $currentMunicipality, AccountabilityService $accountabilityService): Response
    {
        $amendment = $this->amendment($request, $emenda, $currentMunicipality);
        $pdf = $this->makePdf($amendment, $accountabilityService);

        return $pdf->download($this->baseFilename($amendment).'.pdf');
    }

    public function package(Request $request, int $emenda, CurrentMunicipality $currentMunicipality, AccountabilityService $accountabilityService): BinaryFileResponse
    {
        $amendment = $this->amendment($request, $emenda, $currentMunicipality);
        $pdf = $this->makePdf($amendment, $accountabilityService);
        $temporaryPath = tempnam(sys_get_temp_dir(), 'trilhagov-dossier-');

        abort_if($temporaryPath === false, 500, 'Não foi possível preparar o pacote do dossiê.');

        $zip = new ZipArchive;
        $opened = $zip->open($temporaryPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        abort_unless($opened === true, 500, 'Não foi possível criar o pacote do dossiê.');

        $zip->addFromString('dossie/'.$this->baseFilename($amendment).'.pdf', $pdf->output());
        $manifest = [
            'TrilhaGov - Pacote de prestação de contas',
            'Emenda: '.$amendment->reference,
            'Município: '.$amendment->municipality->name.'/'.$amendment->municipality->state,
            'Gerado em: '.now()->format('d/m/Y H:i:s'),
            'Documentos incluídos: '.$amendment->documents->count(),
        ];
        $zip->addFromString('MANIFESTO.txt', implode(PHP_EOL, $manifest).PHP_EOL);

        foreach ($amendment->documents as $document) {
            if (! Storage::disk('local')->exists($document->storage_path)) {
                continue;
            }

            $typeDirectory = Str::slug($document->documentType->name) ?: 'documentos';
            $originalName = Str::of(basename($document->original_name))
                ->replaceMatches('/[^A-Za-z0-9._-]+/', '-')
                ->trim('-')
                ->toString();
            $archiveName = sprintf(
                'documentos/%s/%d-v%d-%s',
                $typeDirectory,
                $document->id,
                $document->version,
                $originalName !== '' ? $originalName : 'arquivo',
            );
            $zip->addFile(Storage::disk('local')->path($document->storage_path), $archiveName);
        }

        $zip->close();

        return response()
            ->download($temporaryPath, $this->baseFilename($amendment).'.zip', ['Content-Type' => 'application/zip'])
            ->deleteFileAfterSend(true);
    }

    private function amendment(Request $request, int $emenda, CurrentMunicipality $currentMunicipality): ParliamentaryAmendment
    {
        return $currentMunicipality->get($request)
            ->amendments()
            ->with([
                'municipality',
                'responsibleUser',
                'executionStages.responsibleUser',
                'financialCommitments.executionStage',
                'financialCommitments.payments.creator',
                'documents.documentType',
                'documents.executionStage',
                'accountabilityProcess.responsibleUser',
                'accountabilityProcess.requirements.document.documentType',
                'accountabilityProcess.requirements.completedBy',
                'accountabilityProcess.diligences.assignedUser',
                'auditLogs',
            ])
            ->findOrFail($emenda);
    }

    private function makePdf(ParliamentaryAmendment $amendment, AccountabilityService $accountabilityService): \Barryvdh\DomPDF\PDF
    {
        $process = $amendment->accountabilityProcess;
        abort_if($process === null, 404);

        return Pdf::loadView('amendments.accountability-dossier', [
            'amendment' => $amendment,
            'process' => $process,
            'readiness' => $accountabilityService->readiness($amendment, $process),
            'generatedAt' => now(),
        ])->setPaper('a4');
    }

    private function baseFilename(ParliamentaryAmendment $amendment): string
    {
        return 'dossie-prestacao-'.(Str::slug($amendment->reference) ?: $amendment->id);
    }
}
