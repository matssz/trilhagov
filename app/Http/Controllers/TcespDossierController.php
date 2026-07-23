<?php

namespace App\Http\Controllers;

use App\Models\AmendmentComplianceReview;
use App\Models\IntegrityAlert;
use App\Models\ParliamentaryAmendment;
use App\Services\CurrentMunicipality;
use App\Services\TcespComplianceFramework;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use ZipArchive;

class TcespDossierController extends Controller
{
    public function pdf(
        Request $request,
        int $emenda,
        CurrentMunicipality $currentMunicipality,
        TcespComplianceFramework $framework,
    ): Response {
        $amendment = $this->amendment($request, $emenda, $currentMunicipality, $framework);
        $pdf = $this->makePdf($amendment, $framework);

        return $pdf->download($this->baseFilename($amendment).'.pdf');
    }

    public function package(
        Request $request,
        int $emenda,
        CurrentMunicipality $currentMunicipality,
        TcespComplianceFramework $framework,
    ): BinaryFileResponse {
        $amendment = $this->amendment($request, $emenda, $currentMunicipality, $framework);
        $pdf = $this->makePdf($amendment, $framework);
        $temporaryPath = tempnam(sys_get_temp_dir(), 'trilhagov-tcesp-');

        abort_if($temporaryPath === false, 500, 'Nao foi possivel preparar o pacote TCESP.');

        $zip = new ZipArchive;
        $opened = $zip->open($temporaryPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        abort_unless($opened === true, 500, 'Nao foi possivel criar o pacote TCESP.');

        $zip->addFromString('dossie/'.$this->baseFilename($amendment).'.pdf', $pdf->output());
        $zip->addFromString('MANIFESTO.txt', implode(PHP_EOL, [
            'TrilhaGov - Pacote TCESP',
            'Emenda: '.$amendment->reference,
            'Municipio: '.$amendment->municipality->name.'/'.$amendment->municipality->state,
            'Gerado em: '.now()->format('d/m/Y H:i:s'),
            'Matriz: '.TcespComplianceFramework::VERSION,
            'Documentos catalogados: '.$amendment->documents->count(),
        ]).PHP_EOL);
        $temporaryDocuments = [];

        foreach ($amendment->documents as $document) {
            if (! Storage::exists($document->storage_path)) {
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
            $source = Storage::readStream($document->storage_path);
            $temporaryDocument = tmpfile();

            if ($source === false || $temporaryDocument === false) {
                if (is_resource($source)) {
                    fclose($source);
                }

                continue;
            }

            stream_copy_to_stream($source, $temporaryDocument);
            fclose($source);
            $metadata = stream_get_meta_data($temporaryDocument);
            $temporaryDocuments[] = $temporaryDocument;
            $zip->addFile($metadata['uri'], $archiveName);
        }

        $zip->close();

        foreach ($temporaryDocuments as $temporaryDocument) {
            fclose($temporaryDocument);
        }

        return response()
            ->download($temporaryPath, $this->baseFilename($amendment).'.zip', ['Content-Type' => 'application/zip'])
            ->deleteFileAfterSend(true);
    }

    private function amendment(
        Request $request,
        int $emenda,
        CurrentMunicipality $currentMunicipality,
        TcespComplianceFramework $framework,
    ): ParliamentaryAmendment {
        $amendment = $currentMunicipality->get($request)
            ->amendments()
            ->with([
                'municipality',
                'responsibleUser',
                'documents.documentType',
                'documents.executionStage',
                'complianceReviews.document.documentType',
                'complianceReviews.reviewer',
                'integrityAlerts' => fn ($query) => $query->where('status', IntegrityAlert::STATUS_OPEN)->latest('severity')->latest('detected_at'),
                'municipalWorkPlan.stages',
                'technicalImpediments.diligences',
                'audespRegistration',
                'financialCommitments.payments',
                'financialCommitments.liquidations.payments',
                'financialPayments',
                'executionStages.responsibleUser',
                'internalControlReviews.actions',
                'accountabilityProcess.requirements',
            ])
            ->findOrFail($emenda);

        abort_unless($framework->appliesTo($amendment), 404);

        return $amendment;
    }

    private function makePdf(ParliamentaryAmendment $amendment, TcespComplianceFramework $framework): \Barryvdh\DomPDF\PDF
    {
        $matrix = $framework->matrix($amendment);

        return Pdf::loadView('amendments.tcesp-dossier', [
            'amendment' => $amendment,
            'matrix' => $matrix,
            'summary' => $framework->summary($matrix),
            'categories' => $framework->categories(),
            'statuses' => AmendmentComplianceReview::statuses(),
            'sourceLabel' => TcespComplianceFramework::SOURCE_LABEL,
            'frameworkVersion' => TcespComplianceFramework::VERSION,
            'generatedAt' => now(),
        ])->setPaper('a4');
    }

    private function baseFilename(ParliamentaryAmendment $amendment): string
    {
        return 'dossie-tcesp-'.(Str::slug($amendment->reference) ?: $amendment->id);
    }
}
