<?php

namespace App\Http\Controllers\Web\Settings;

use App\Http\Controllers\Controller;
use App\Models\{QueueSetting, User};
use Illuminate\Http\{RedirectResponse, Request};
use Illuminate\Validation\ValidationException;
use Inertia\{Inertia, Response};

class QueueSettingController extends Controller
{
    private const ADMIN_FIELDS = ['points', 'shifts'];

    public function index(Request $request): Response
    {
        return Inertia::render('settings/Queue', [
            'settings' => QueueSetting::current(),
            'supervisors' => User::query()->where('is_active', true)->whereIn('role', ['admin', 'supervisor'])->orderBy('name')->get(['id', 'name', 'role']),
            'canEditAdmin' => $request->user()->isAdmin(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        abort_if(! $request->user()->isAdmin() && $request->hasAny(self::ADMIN_FIELDS), 403);
        $data = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'windows_per_moderator' => ['sometimes', 'integer', 'min:1', 'max:10'],
            'silence_warn_seconds' => ['sometimes', 'integer', 'min:30', 'max:3600'],
            'silence_close_seconds' => ['sometimes', 'integer', 'min:60', 'max:7200'],
            'return_priority_minutes' => ['sometimes', 'integer', 'min:0', 'max:1440'],
            'close_confirm_minutes' => ['sometimes', 'integer', 'min:0', 'max:1440'],
            'sla_first_reply_seconds' => ['sometimes', 'integer', 'min:30', 'max:7200'],
            'sla_target_pct' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'occupancy_cap_pct' => ['sometimes', 'integer', 'min:10', 'max:100'],
            'break_minutes' => ['sometimes', 'integer', 'min:0', 'max:120'],
            'break_after_minutes' => ['sometimes', 'integer', 'min:0', 'max:600'],
            'review_sample_pct' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'review_delay_seconds' => ['sometimes', 'integer', 'min:0', 'max:3600'],
            'case_sla_hours' => ['sometimes', 'integer', 'min:1', 'max:168'],
            'night_message_enabled' => ['sometimes', 'boolean'],
            'speed_fast_seconds' => ['sometimes', 'integer', 'min:10', 'max:3600'],
            'speed_ok_seconds' => ['sometimes', 'integer', 'min:10', 'max:3600'],
            'eta_default_handle_seconds' => ['sometimes', 'integer', 'min:30', 'max:3600'],
            'points' => ['sometimes', 'array'],
            'points.*' => ['integer', 'min:-100', 'max:1000'],
            'shifts' => ['sometimes', 'array', 'min:1', 'max:4'],
            'shifts.*.key' => ['required', 'string', 'alpha_dash', 'max:40'],
            'shifts.*.name' => ['required', 'string', 'max:60'],
            'shifts.*.from' => ['required', 'date_format:H:i'],
            'shifts.*.to' => ['required', 'date_format:H:i'],
            'shifts.*.location' => ['required', 'in:office,home'],
            'shifts.*.leader_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'default_roster' => ['sometimes', 'array'],
        ]);
        $s = QueueSetting::current();
        $warn = (int) ($data['silence_warn_seconds'] ?? $s->silence_warn_seconds);
        $close = (int) ($data['silence_close_seconds'] ?? $s->silence_close_seconds);
        if ($warn >= $close) {
            throw ValidationException::withMessages(['silence_warn_seconds' => __('errors.queue.warn_before_close')]);
        }
        if (isset($data['points'])) {
            $data['points'] = array_merge($s->points ?? QueueSetting::DEFAULT_POINTS, $data['points']);
        }
        $s->fill($data)->save();

        return back()->with('status', 'saved');
    }
}
