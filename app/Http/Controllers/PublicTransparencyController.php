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

        return response()->streamDownload(function () use ($amendments, $analyticsService, $municipality): void {
            $output = fopen('php://output', 'wb');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, [
                'Autor', 'Identificação da emenda', 'Objeto e finalidade', 'Órgão executor ou beneficiário',
                'Valor autorizado', 'Valor liberado', 'Valor executado', 'Conta bancária',
                'Forma de rastreabilidade', 'Fonte de recursos', 'Código de aplicação fixo',
                'Código de aplicação variável', 'Destinação', 'Município ou localidade beneficiada',
                'Instrumento jurídico', 'Processo administrativo', 'Cronograma físico-financeiro',
                'Prazo para aplicação', 'Situação', 'Última atualização',
            ], ';');

            foreach ($amendments as $amendment) {
                $plan = $amendment->municipalWorkPlan;
                $schedule = $plan?->stages->map(fn ($stage) => implode(' | ', [
                    $stage->title,
                    $stage->physical_delivery,
                    number_format((float) $stage->planned_amount, 2, ',', ''),
                    $stage->planned_start_at->format('d/m/Y').' a '.$stage->planned_end_at->format('d/m/Y'),
                ]))->implode(' / ');
                fputcsv($output, [
                    $this->safe($amendment->author_name),
                    $this->safe($amendment->reference),
                    $this->safe($amendment->object),
                    $this->safe($plan?->beneficiary_name ?: $amendment->responsible_department),
                    number_format((float) $amendment->expected_amount, 2, ',', ''),
                    number_format((float) $amendment->received_amount, 2, ',', ''),
                    number_format($analyticsService->paidAmount($amendment), 2, ',', ''),
                    $this->safe($amendment->bank_account_number),
                    $amendment->bankTrackingTypeLabel(),
                    $this->safe($amendment->funding_source_code),
                    $this->safe($amendment->application_code_fixed),
                    $this->safe($amendment->application_code_variable),
                    $amendment->expenseDestinationLabel(),
                    $this->safe($amendment->beneficiary_location ?: $municipality->name),
                    $this->safe($amendment->legal_instrument),
                    $this->safe($amendment->administrative_process),
                    $this->safe($schedule),
                    $amendment->application_deadline?->format('d/m/Y'),
                    $amendment->statusLabel(),
                    $amendment->updated_at?->format('d/m/Y H:i'),
                ], ';');
            }

            fclose($output);
        }, 'emendas-'.str($municipality->name)->slug().'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function detail(
        Municipality $municipality,
        int $emenda,
        AmendmentAnalyticsService $analyticsService,
    ): View {
        $this->ensurePublished($municipality);
        $amendment = $municipality->amendments()
            ->with([
                'municipalWorkPlan.stages',
                'financialPayments',
                'transparencyEvents',
            ])
            ->findOrFail($emenda);

        return view('transparency.detail', [
            'municipality' => $municipality,
            'amendment' => $amendment,
            'authorizedAmount' => (float) $amendment->expected_amount,
            'releasedAmount' => (float) $amendment->received_amount,
            'executedAmount' => $analyticsService->paidAmount($amendment),
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
