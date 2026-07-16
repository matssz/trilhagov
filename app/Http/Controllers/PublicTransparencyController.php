<?php

namespace App\Http\Controllers;

use App\Models\Municipality;
use App\Models\ParliamentaryAmendment;
use App\Services\AmendmentAnalyticsService;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PublicTransparencyController extends Controller
{
    public function show(
        Request $request,
        Municipality $municipality,
        AmendmentAnalyticsService $analyticsService,
    ): View {
        $this->ensurePublished($municipality);
        $filters = $request->only(['year', 'sphere', 'status', 'department']);
        $analytics = $analyticsService->dashboard($municipality, $filters);

        return view('transparency.show', [
            'municipality' => $municipality,
            'analytics' => $analytics,
            'paidAmounts' => $analytics['amendments']->mapWithKeys(fn ($amendment) => [
                $amendment->id => $analyticsService->paidAmount($amendment),
            ]),
            'filters' => $filters,
            'options' => $analyticsService->filterOptions($municipality),
            'statuses' => ParliamentaryAmendment::statuses(),
            'spheres' => ParliamentaryAmendment::governmentSpheres(),
        ]);
    }

    public function export(
        Request $request,
        Municipality $municipality,
        AmendmentAnalyticsService $analyticsService,
    ): StreamedResponse {
        $this->ensurePublished($municipality);
        $amendments = $analyticsService->amendments(
            $municipality,
            $request->only(['year', 'sphere', 'status', 'department']),
        );

        return response()->streamDownload(function () use ($amendments, $analyticsService): void {
            $output = fopen('php://output', 'wb');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, [
                'Referência', 'Exercício', 'Esfera', 'Autor', 'Objeto', 'Órgão responsável',
                'Situação', 'Valor previsto', 'Valor recebido', 'Valor pago', 'Execução física',
            ], ';');

            foreach ($amendments as $amendment) {
                fputcsv($output, [
                    $this->safe($amendment->reference),
                    $amendment->fiscal_year,
                    $amendment->governmentSphereLabel(),
                    $this->safe($amendment->author_name),
                    $this->safe($amendment->object),
                    $this->safe($amendment->responsible_department),
                    $amendment->statusLabel(),
                    number_format((float) $amendment->expected_amount, 2, ',', ''),
                    number_format((float) $amendment->received_amount, 2, ',', ''),
                    number_format($analyticsService->paidAmount($amendment), 2, ',', ''),
                    $amendment->physicalExecutionPercentage().'%',
                ], ';');
            }

            fclose($output);
        }, 'emendas-'.str($municipality->name)->slug().'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function ensurePublished(Municipality $municipality): void
    {
        abort_unless($municipality->transparency_enabled && filled($municipality->transparency_slug), 404);
    }

    private function safe(?string $value): string
    {
        $value = trim((string) $value);

        return preg_match('/^[=+\-@]/', $value) === 1 ? "'{$value}" : $value;
    }
}
