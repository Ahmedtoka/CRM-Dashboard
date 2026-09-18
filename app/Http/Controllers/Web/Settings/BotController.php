<?php

namespace App\Http\Controllers\Web\Settings;

use App\Http\Controllers\Concerns\RespondsWithData;
use App\Http\Controllers\Controller;
use App\Models\BotSetting;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Bot settings page. The old keyword-rule editor and tester were removed from the UI
 * (the main menu + guided flows replaced them); inactive bot_rules rows and the
 * RuleEngine stay in place and simply never fire.
 */
class BotController extends Controller
{
    use RespondsWithData;

    /** AI settings are admin-only (spec §6); supervisors manage the rest. */
    private const AI_FIELDS = ['ai_enabled', 'ai_classifier_model', 'ai_reply_model', 'system_prompt'];

    public function index(Request $request): Response
    {
        return Inertia::render('settings/Bot', [
            'settings' => BotSetting::current(),
            'canEditAi' => $request->user()->isAdmin(),
        ]);
    }

    public function update(Request $request): HttpResponse
    {
        abort_if(! $request->user()->isAdmin() && $request->hasAny(self::AI_FIELDS), 403, 'Only admins can change AI settings.');

        $data = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'min_confidence' => ['sometimes', 'numeric', 'between:0,1'],
            'max_bot_turns' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'handover_keywords' => ['sometimes', 'array'],
            'handover_keywords.*' => ['string', 'max:100'],
            'comment_reply_delay_min' => ['sometimes', 'integer', 'min:0', 'max:600'],
            'comment_reply_delay_max' => ['sometimes', 'integer', 'min:0', 'max:600', 'gte:comment_reply_delay_min'],
            'working_hours' => ['sometimes', 'nullable', 'array'],
            'working_hours.from' => ['nullable', 'date_format:H:i'],
            'working_hours.to' => ['nullable', 'date_format:H:i'],
            'working_hours.days' => ['nullable', 'array'],
            'working_hours.days.*' => ['integer', 'between:0,6'],
            'outside_hours_message' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'spam_phrases' => ['sometimes', 'array'],
            'spam_phrases.*' => ['string', 'max:100'],
            'low_value_phrases' => ['sometimes', 'array'],
            'low_value_phrases.*' => ['string', 'max:100'],
            'allowed_link_domains' => ['sometimes', 'array'],
            'allowed_link_domains.*' => ['string', 'max:255'],
            'spam_repeat_threshold' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'ai_enabled' => ['sometimes', 'boolean'],
            'ai_classifier_model' => ['sometimes', 'string', 'max:100'],
            'ai_reply_model' => ['sometimes', 'string', 'max:100'],
            'system_prompt' => ['sometimes', 'nullable', 'string', 'max:10000'],
            // Human bot flow timing (Task 5 ruling 3).
            'burst_wait_seconds' => ['sometimes', 'integer', 'min:0', 'max:60'],
            'burst_max_wait_seconds' => ['sometimes', 'integer', 'min:0', 'max:120'],
            'typing_ms_per_char' => ['sometimes', 'integer', 'min:0', 'max:120'],
            'order_lookup_enabled' => ['sometimes', 'boolean'],
        ]);

        $settings = BotSetting::current();

        // Max wait must never be below the wait, whether either side is sent now or already saved.
        if (array_key_exists('burst_wait_seconds', $data) || array_key_exists('burst_max_wait_seconds', $data)) {
            $wait = (int) ($data['burst_wait_seconds'] ?? $settings->burst_wait_seconds);
            $maxWait = (int) ($data['burst_max_wait_seconds'] ?? $settings->burst_max_wait_seconds);

            if ($maxWait < $wait) {
                throw ValidationException::withMessages([
                    'burst_max_wait_seconds' => __('validation.gte.numeric', ['attribute' => 'burst max wait seconds', 'value' => $wait]),
                ]);
            }
        }

        $settings->update($data);

        return $this->done($request, $settings);
    }
}
