<?php

namespace App\Http\Controllers\Web\Settings;

use App\Bot\Ai\AiResponder;
use App\Bot\ArabicNormalizer;
use App\Bot\RuleEngine;
use App\Enums\Platform;
use App\Http\Controllers\Concerns\RespondsWithData;
use App\Http\Controllers\Controller;
use App\Models\BotRule;
use App\Models\BotSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Throwable;

class BotController extends Controller
{
    use RespondsWithData;

    /** AI settings are admin-only (spec §6); supervisors manage the rest. */
    private const AI_FIELDS = ['ai_enabled', 'ai_classifier_model', 'ai_reply_model', 'system_prompt'];

    public function index(Request $request): Response
    {
        return Inertia::render('settings/Bot', [
            'settings' => BotSetting::current(),
            'rules' => BotRule::orderByDesc('priority')->orderBy('id')->get(),
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
        ]);

        $settings = BotSetting::current();
        $settings->update($data);

        return $this->done($request, $settings);
    }

    public function storeRule(Request $request): HttpResponse
    {
        $rule = BotRule::create($this->validatedRule($request));

        return $this->done($request, $rule, 201);
    }

    public function updateRule(Request $request, BotRule $rule): HttpResponse
    {
        $rule->update($this->validatedRule($request));

        return $this->done($request, $rule);
    }

    public function destroyRule(Request $request, BotRule $rule): HttpResponse
    {
        $rule->delete();

        return $this->done($request, ['id' => $rule->id]);
    }

    /**
     * Rule tester: which rule would match (without counting a hit), the normalized text,
     * and what the AI classifier says.
     */
    public function test(Request $request, RuleEngine $rules, ArabicNormalizer $normalizer, AiResponder $ai): JsonResponse
    {
        $data = $request->validate([
            'text' => ['required', 'string', 'max:2000'],
            'scope' => ['required', Rule::in(['message', 'comment'])],
            'platform' => ['required', Rule::enum(Platform::class)],
        ]);

        $rule = $rules->peek($data['text'], $data['scope'], Platform::from($data['platform']));

        try {
            $c = $ai->classify($data['text']);
            $classification = [
                'intent' => $c->intent->value,
                'confidence' => $c->confidence,
                'needs_human' => $c->needsHuman,
                'model' => $c->model,
            ];
        } catch (Throwable $e) {
            $classification = ['error' => $e->getMessage()];
        }

        return response()->json([
            'rule' => $rule ? [
                'id' => $rule->id,
                'name' => $rule->name,
                'action' => $rule->action,
                'public_replies' => $rule->public_replies,
                'private_reply' => $rule->private_reply,
            ] : null,
            'normalized' => $normalizer->normalize($data['text']),
            'ai_classification' => $classification,
        ]);
    }

    private function validatedRule(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'is_active' => ['boolean'],
            'priority' => ['integer', 'min:0', 'max:100000'],
            'scope' => ['required', Rule::in(['comment', 'message', 'both'])],
            'platforms' => ['nullable', 'array'],
            'platforms.*' => [Rule::enum(Platform::class)],
            'match_type' => ['required', Rule::in(['any_keyword', 'all_keywords', 'exact', 'regex'])],
            'keywords' => ['required', 'array', 'min:1'],
            'keywords.*' => ['string', 'max:255'],
            'public_replies' => ['nullable', 'array'],
            'public_replies.*' => ['string', 'max:2000'],
            'private_reply' => ['nullable', 'string', 'max:2000'],
            'action' => ['required', Rule::in(['reply', 'reply_and_handover', 'handover', 'hide'])],
        ]);
    }
}
