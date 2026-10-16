<?php

namespace App\Http\Controllers\Web\Settings;

use App\Channels\Integrations\WhatsAppConnector;
use App\Confirmations\ConfirmationSettings;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «تأكيد الأوردرات على الواتساب» (spec 2026-10-16, W4): the admin edits ConfirmationSettings and sees whether a
 * live WhatsApp number is connected (read-only; the number is connected on «الربط»).
 */
class OrderConfirmationSettingsController extends Controller
{
    public const DELAY_MAX = 60;

    public const REMINDER_MAX = 48;

    public const NO_RESPONSE_MAX = 96;

    public function index(ConfirmationSettings $settings, WhatsAppConnector $whatsApp): Response
    {
        $account = $whatsApp->liveAccount(includeDisconnected: true);

        return Inertia::render('settings/OrderConfirmations', [
            'settings' => $settings->all(),
            'whatsapp' => $account === null ? null : [
                'name' => $account->name,
                'phone' => $account->profile['display_phone_number'] ?? null,
                'connected' => $account->status !== 'disconnected',
                'status' => $account->status,
            ],
            'limits' => ['delay_max' => self::DELAY_MAX, 'reminder_max' => self::REMINDER_MAX, 'no_response_max' => self::NO_RESPONSE_MAX],
        ]);
    }

    public function update(Request $request, ConfirmationSettings $settings): RedirectResponse
    {
        $template = ['required', 'string', 'max:512', 'regex:/^[a-z0-9_]+$/'];

        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'delay_minutes' => ['required', 'integer', 'min:0', 'max:'.self::DELAY_MAX],
            'reminder_after_hours' => ['required', 'integer', 'min:1', 'max:'.self::REMINDER_MAX],
            'no_response_after_hours' => ['required', 'integer', 'gt:reminder_after_hours', 'max:'.self::NO_RESPONSE_MAX],
            'template_confirm' => $template,
            'template_reminder' => $template,
            // Meta's language codes: «ar», «en», «en_US».
            'template_language' => ['required', 'string', 'max:15', 'regex:/^[a-z]{2,3}(_[A-Z]{2})?$/'],
            'skip_chat_orders' => ['required', 'boolean'],
            'max_order_age_minutes' => ['required', 'integer', 'min:10', 'max:1440'],
        ]);

        $settings->save([
            ...$data,
            'enabled' => (bool) $data['enabled'],
            'skip_chat_orders' => (bool) $data['skip_chat_orders'],
            'delay_minutes' => (int) $data['delay_minutes'],
            'reminder_after_hours' => (int) $data['reminder_after_hours'],
            'no_response_after_hours' => (int) $data['no_response_after_hours'],
            'max_order_age_minutes' => (int) $data['max_order_age_minutes'],
        ]);

        return back()->with('status', 'saved');
    }
}
