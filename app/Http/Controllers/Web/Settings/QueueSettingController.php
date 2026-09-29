<?php

namespace App\Http\Controllers\Web\Settings;

use App\Http\Controllers\Controller;
use App\Models\QueueSetting;
use App\Models\User;
use App\Queue\ShiftService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class QueueSettingController extends Controller
{
    private const ADMIN_FIELDS = ['points', 'shifts'];

    public function index(Request $request): Response
    {
        $settings = QueueSetting::current();
        // A row saved before a points key existed (points.no_reply, flow revision §7) shows its default.
        $settings->points = array_merge(QueueSetting::DEFAULT_POINTS, $settings->points ?? []);

        return Inertia::render('settings/Queue', [
            'settings' => $settings,
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
            // Flow revision §7.
            'waiting_update_seconds' => ['sometimes', 'integer', 'min:30', 'max:900'],
            'agent_apology_seconds' => ['sometimes', 'integer', 'min:30', 'max:3600'],
            'agent_reassign_first_seconds' => ['sometimes', 'integer', 'min:120', 'max:3600'],
            'agent_reassign_seconds' => ['sometimes', 'integer', 'min:120', 'max:3600'],
            'case_follow_owner' => ['sometimes', 'boolean'],
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
        // The apology comes before either hand-off (flow revision §7).
        $apology = (int) ($data['agent_apology_seconds'] ?? $s->agent_apology_seconds);
        $first = (int) ($data['agent_reassign_first_seconds'] ?? $s->agent_reassign_first_seconds);
        $later = (int) ($data['agent_reassign_seconds'] ?? $s->agent_reassign_seconds);
        if ($apology >= min($first, $later)) {
            throw ValidationException::withMessages(['agent_apology_seconds' => __('errors.queue.apology_before_handoff')]);
        }
        if (isset($data['points'])) {
            $data['points'] = array_merge($s->points ?? QueueSetting::DEFAULT_POINTS, $data['points']);
        }
        DB::transaction(function () use ($s, $data) {
            $s->fill($data)->save();

            // A leader named or changed in the templates takes her shift at once, even mid-shift (attendance design §2).
            if (array_key_exists('shifts', $data)) {
                app(ShiftService::class)->syncLeaders();
            }
        });

        return back()->with('status', 'saved');
    }
}
