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

        abort_if($temporaryPath === false, 500, 'Não foi possível preparar o pacote do dossiê.');

        $zip = new ZipArchive;
        $opened = $zip->open($temporaryPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        abort_unless($opened === true, 500, 'Não foi possível criar o pacote do dossiê.');

        $readiness = $accountabilityService->readiness($amendment, $amendment->accountabilityProcess);
        $guide = $accountabilityService->guide($amendment, $amendment->accountabilityProcess, $readiness);

        $zip->addFromString('dossie/'.$this->baseFilename($amendment).'.pdf', $pdf->output());
        $zip->addFromString('RECIBO-PRESTACAO.txt', $this->receipt($amendment, $guide));
        $includedDocuments = [];
        $skippedDocuments = [];

        foreach ($amendment->documents as $document) {
            if (! Storage::disk('local')->exists($document->storage_path)) {
                $skippedDocuments[] = $this->manifestDocumentLine($document, 'arquivo não encontrado no armazenamento');

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
                $skippedDocuments[] = $this->manifestDocumentLine($document, 'arquivo não pode ser lido');

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

        $readiness = $accountabilityService->readiness($amendment, $process);

        return Pdf::loadView('amendments.accountability-dossier', [
            'amendment' => $amendment,
            'process' => $process,
            'readiness' => $readiness,
            'accountabilityGuide' => $accountabilityService->guide($amendment, $process, $readiness),
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
            'TrilhaGov - Pacote de prestação de contas',
            'Emenda: '.$amendment->reference,
            'Município: '.$amendment->municipality->name.'/'.$amendment->municipality->state,
            'Gerado em: '.now()->format('d/m/Y H:i:s'),
            'Documentos catalogados: '.$amendment->documents->count(),
            'Documentos incluídos no pacote: '.count($includedDocuments),
            'Documentos não incluídos: '.count($skippedDocuments),
            '',
            'Arquivos incluídos:',
            ...($includedDocuments === [] ? ['- nenhum anexo incluido'] : array_map(fn (string $line) => '- '.$line, $includedDocuments)),
        ];

        if ($skippedDocuments !== []) {
            $lines = [
                ...$lines,
                '',
                'Atenção: documentos catalogados que não entraram no pacote:',
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

    /** @param array<string, mixed> $guide */
    private function receipt(ParliamentaryAmendment $amendment, array $guide): string
    {
        $receipt = $guide['finalReceipt'];
        $timeline = $guide['finalTimeline'];
        $lines = [
            'TrilhaGov - Recibo da prestação de contas',
            'Emenda: '.$amendment->reference,
            'Objeto: '.$amendment->object,
            'Município: '.$amendment->municipality->name.'/'.$amendment->municipality->state,
            'Selo: '.$receipt['seal'],
            'Situação: '.$receipt['status'],
            'Protocolo: '.$receipt['protocol'],
            'Envio: '.$receipt['submitted_at'],
            'Prazo: '.$receipt['deadline'],
            'Responsável: '.$receipt['responsible'],
            'Prontidão: '.$receipt['readiness'],
            '',
            'Linha do tempo final:',
        ];

        foreach ($timeline as $item) {
            $lines[] = ($item['done'] ? '[ok] ' : '[pendente] ').$item['title'].' - '.$item['detail'];
        }

        return implode(PHP_EOL, $lines).PHP_EOL;
    }
}
