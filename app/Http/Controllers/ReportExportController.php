<?php

namespace App\Http\Controllers;

use App\Services\AmendmentAnalyticsService;
use App\Services\AuditTrail;
use App\Services\CurrentMunicipality;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportExportController extends Controller
{
    public function __invoke(
        Request $request,
        CurrentMunicipality $currentMunicipality,
        AmendmentAnalyticsService $analyticsService,
        AuditTrail $auditTrail,
    ): StreamedResponse {
        $municipality = $currentMunicipality->get($request);
        $filters = $request->only(['year', 'sphere', 'status', 'department']);
        $amendments = $analyticsService->amendments($municipality, $filters);

        $auditTrail->recordMunicipalityOperation($request, $municipality, 'report_exported', [
            'export_format' => 'CSV',
            'report_filters' => array_filter($filters),
            'records' => $amendments->count(),
        ]);

        return response()->streamDownload(function () use ($amendments, $analyticsService): void {
            $output = fopen('php://output', 'wb');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, [
                'Referência', 'Exercício', 'Esfera', 'Autor', 'Partido', 'Objeto',
                'Órgão responsável', 'Responsável operacional', 'Situação', 'Risco',
                'Valor previsto', 'Valor recebido', 'Valor empenhado', 'Valor pago',
                'Execução física', 'Prazo de execução', 'Prazo de prestação',
            ], ';');

            foreach ($amendments as $amendment) {
                fputcsv($output, [
                    $this->safe($amendment->reference),
                    $amendment->fiscal_year,
                    $amendment->governmentSphereLabel(),
                    $this->safe($amendment->author_name),
                    $this->safe($amendment->author_party),
                    $this->safe($amendment->object),
                    $this->safe($amendment->responsible_department),
                    $this->safe($amendment->responsibleUser?->name),
                    $amendment->statusLabel(),
                    $amendment->riskLabel(),
                    number_format((float) $amendment->expected_amount, 2, ',', ''),
                    number_format((float) $amendment->received_amount, 2, ',', ''),
                    number_format($analyticsService->committedAmount($amendment), 2, ',', ''),
                    number_format($analyticsService->paidAmount($amendment), 2, ',', ''),
                    $amendment->physicalExecutionPercentage().'%',
                    $amendment->execution_deadline?->format('d/m/Y'),
                    $amendment->accountability_deadline?->format('d/m/Y'),
                ], ';');
            }

            fclose($output);
        }, 'trilhagov-emendas-'.now()->format('Y-m-d-His').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function safe(?string $value): string
    {
        $value = trim((string) $value);

        return preg_match('/^[=+\-@]/', $value) === 1 ? "'{$value}" : $value;
    }
}
