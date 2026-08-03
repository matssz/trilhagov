<?php

namespace App\Http\Controllers;

use App\Models\AmendmentDocument;
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

        abort_if($temporaryPath === false, 500, 'Nao foi possivel preparar o pacote do dossie.');

        $zip = new ZipArchive;
        $opened = $zip->open($temporaryPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        abort_unless($opened === true, 500, 'Nao foi possivel criar o pacote do dossie.');

        $zip->addFromString('dossie/'.$this->baseFilename($amendment).'.pdf', $pdf->output());
        $includedDocuments = [];
        $skippedDocuments = [];

        foreach ($amendment->documents as $document) {
            if (! Storage::disk('local')->exists($document->storage_path)) {
                $skippedDocuments[] = $this->manifestDocumentLine($document, 'arquivo nao encontrado no armazenamento');

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
            $contents = Storage::disk('local')->get($document->storage_path);

            if ($contents === null || $contents === false) {
                $skippedDocuments[] = $this->manifestDocumentLine($document, 'arquivo nao pode ser lido');

                continue;
            }

            $zip->addFromString($archiveName, $contents);
            $includedDocuments[] = $this->manifestDocumentLine($document, $archiveName);
        }

        $zip->addFromString('MANIFESTO.txt', $this->manifest($amendment, $includedDocuments, $skippedDocuments));
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

    /** @param array<int, string> $includedDocuments @param array<int, string> $skippedDocuments */
    private function manifest(ParliamentaryAmendment $amendment, array $includedDocuments, array $skippedDocuments): string
    {
        $lines = [
            'TrilhaGov - Pacote de prestacao de contas',
            'Emenda: '.$amendment->reference,
            'Municipio: '.$amendment->municipality->name.'/'.$amendment->municipality->state,
            'Gerado em: '.now()->format('d/m/Y H:i:s'),
            'Documentos catalogados: '.$amendment->documents->count(),
            'Documentos incluidos no pacote: '.count($includedDocuments),
            'Documentos nao incluidos: '.count($skippedDocuments),
            '',
            'Arquivos incluidos:',
            ...($includedDocuments === [] ? ['- nenhum anexo incluido'] : array_map(fn (string $line) => '- '.$line, $includedDocuments)),
        ];

        if ($skippedDocuments !== []) {
            $lines = [
                ...$lines,
                '',
                'Atencao: documentos catalogados que nao entraram no pacote:',
                ...array_map(fn (string $line) => '- '.$line, $skippedDocuments),
            ];
        }

        return implode(PHP_EOL, $lines).PHP_EOL;
    }

    private function manifestDocumentLine(AmendmentDocument $document, string $detail): string
    {
        return sprintf(
            '%s | tipo: %s | v%d | %s',
            $document->original_name,
            $document->documentType->name,
            $document->version,
            $detail,
        );
    }
}
