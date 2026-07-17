<?php

namespace App\Http\Controllers;

use App\Services\CurrentMunicipality;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\View\View;

class NotificationCenterController extends Controller
{
    public function index(Request $request, CurrentMunicipality $currentMunicipality): View
    {
        $municipality = $currentMunicipality->get($request);
        $membership = $request->user()->municipalities()->findOrFail($municipality->id)->pivot;
        $notifications = $request->user()->notifications()
            ->whereJsonContains('data->municipality_id', $municipality->id)
            ->latest()
            ->paginate(20);

        return view('notifications.index', [
            'municipality' => $municipality,
            'notifications' => $notifications,
            'membership' => $membership,
        ]);
    }

    public function updatePreferences(Request $request, CurrentMunicipality $currentMunicipality): RedirectResponse
    {
        $municipality = $currentMunicipality->get($request);
        $request->user()->municipalities()->updateExistingPivot($municipality->id, [
            'notify_in_app' => $request->boolean('notify_in_app'),
            'notify_email' => $request->boolean('notify_email'),
            'notify_deadlines' => $request->boolean('notify_deadlines'),
            'notify_integrity' => $request->boolean('notify_integrity'),
        ]);

        return back()->with('status', 'Preferências de notificação salvas.');
    }

    public function markAsRead(
        Request $request,
        CurrentMunicipality $currentMunicipality,
        string $notification,
    ): RedirectResponse {
        $municipality = $currentMunicipality->get($request);
        /** @var DatabaseNotification $item */
        $item = $request->user()->notifications()->findOrFail($notification);
        abort_unless((int) ($item->data['municipality_id'] ?? 0) === $municipality->id, 404);
        $item->markAsRead();

        return back();
    }

    public function markAllAsRead(Request $request, CurrentMunicipality $currentMunicipality): RedirectResponse
    {
        $municipality = $currentMunicipality->get($request);
        $request->user()->unreadNotifications()
            ->whereJsonContains('data->municipality_id', $municipality->id)
            ->update(['read_at' => now()]);

        return back()->with('status', 'Notificações marcadas como lidas.');
    }
}
