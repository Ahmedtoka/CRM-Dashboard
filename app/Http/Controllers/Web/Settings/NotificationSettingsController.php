<?php

namespace App\Http\Controllers\Web\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Per-user notification preferences (spec §5.4, Dashboard Experience Task 14):
 * sound, browser (desktop) notifications, whose conversations to notify
 * about, and the sound volume.
 */
class NotificationSettingsController extends Controller
{
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/Notifications', ['preferences' => $request->user()->notificationPreferences()]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'sound' => ['sometimes', 'boolean'],
            'desktop_notifications' => ['sometimes', 'boolean'],
            'notify_scope' => ['sometimes', Rule::in(['all_visible', 'mine_and_handover'])],
            'sound_volume' => ['sometimes', 'numeric', 'between:0,1'],
        ]);
        if (isset($data['sound_volume'])) {
            $data['sound_volume'] = (float) $data['sound_volume'];
        }
        $user = $request->user();
        $user->forceFill(['preferences' => array_replace($user->notificationPreferences(), $data)])->save();

        return response()->json(['data' => $user->notificationPreferences()]);
    }
}
