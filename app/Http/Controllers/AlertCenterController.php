<?php

namespace App\Http\Controllers;

use App\Models\IntegrityAlert;
use App\Services\CurrentMunicipality;
use App\Services\IntegrityAlertProcessor;
use App\Services\IntegrityAlertService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AlertCenterController extends Controller
{
    public function index(
        Request $request,
        CurrentMunicipality $currentMunicipality,
        IntegrityAlertService $alertService,
    ): View {
        $municipality = $currentMunicipality->get($request);
        $alertService->syncIfDue($municipality);
        $query = $municipality->integrityAlerts()->with('amendment');
        $status = in_array($request->query('status'), ['open', 'resolved'], true)
            ? $request->query('status')
            : 'open';

        $query->where('status', $status);

        if (in_array($request->query('category'), ['deadline', 'document', 'consistency', 'assignment'], true)) {
            $query->where('category', $request->query('category'));
        }

        if (in_array($request->query('severity'), ['info', 'warning', 'critical'], true)) {
            $query->where('severity', $request->query('severity'));
        }

        $openQuery = $municipality->integrityAlerts()->where('status', IntegrityAlert::STATUS_OPEN);

        return view('alerts.index', [
            'municipality' => $municipality,
            'alerts' => $query
                ->orderByRaw("case severity when 'critical' then 1 when 'warning' then 2 else 3 end")
                ->orderBy('due_at')
                ->latest('detected_at')
                ->paginate(20)
                ->withQueryString(),
            'statusFilter' => $status,
            'openCount' => (clone $openQuery)->count(),
            'criticalCount' => (clone $openQuery)->where('severity', IntegrityAlert::SEVERITY_CRITICAL)->count(),
            'deadlineCount' => (clone $openQuery)->where('category', IntegrityAlert::CATEGORY_DEADLINE)->count(),
            'documentCount' => (clone $openQuery)->where('category', IntegrityAlert::CATEGORY_DOCUMENT)->count(),
            'settings' => $municipality->alertSetting()->firstOrCreate([]),
            'canManage' => $request->user()->roleForMunicipality($municipality->id) === 'manager',
        ]);
    }

    public function process(
        Request $request,
        CurrentMunicipality $currentMunicipality,
        IntegrityAlertProcessor $processor,
    ): RedirectResponse {
        $municipality = $currentMunicipality->get($request);
        $stats = $processor->process($municipality);

        return back()->with('status', sprintf(
            'Verificação concluída: %d alerta(s) aberto(s) e %d novo(s) envio(s).',
            $stats['open'],
            $stats['sent'],
        ));
    }

    public function updateSettings(Request $request, CurrentMunicipality $currentMunicipality): RedirectResponse
    {
        $municipality = $currentMunicipality->get($request);
        $validated = $request->validate([
            'deadline_warning_days' => ['required', 'integer', 'min:7', 'max:90'],
            'deadline_critical_days' => ['required', 'integer', 'min:1', 'lt:deadline_warning_days'],
            'overdue_repeat_days' => ['required', 'integer', 'min:1', 'max:30'],
            'escalation_level_one_days' => ['required', 'integer', 'min:1', 'max:30'],
            'escalation_level_two_days' => ['required', 'integer', 'gt:escalation_level_one_days', 'max:90'],
        ]);
        $validated['notify_managers_on_warning'] = $request->boolean('notify_managers_on_warning');
        $validated['notify_editors_on_level_two'] = $request->boolean('notify_editors_on_level_two');

        $municipality->alertSetting()->updateOrCreate([], $validated);

        return back()->with('status', 'Regras de prazo atualizadas.');
    }
}
