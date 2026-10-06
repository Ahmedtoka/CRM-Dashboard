<?php

namespace App\Http\Requests\Ads;

use App\Http\Controllers\Web\Ads\PublishController;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** A launch draft (spec 3.2): slot, files, captions, CTA (+ identity for the buyer). Everything that spends or redirects is refused. */
class LaunchRequest extends FormRequest
{
    /** Inputs content (and the buyer, in a draft) may never send: 422 when present (TC-07). */
    public const PROHIBITED = [
        'budget', 'daily_budget', 'lifetime_budget', 'bid', 'bid_amount', 'bid_strategy', 'targeting', 'objective', 'schedule',
        'link', 'url_tags', 'allow_duplicate', 'campaign_id', 'new_campaign', 'new_adset', 'website_links',
    ];

    public function authorize(): bool
    {
        return true; // LaunchService / LaunchPolicy decide per launch
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $creating = $this->isMethod('POST');

        return array_merge(array_fill_keys(self::PROHIBITED, ['prohibited']), [
            'adset_id' => [$creating ? 'required' : 'sometimes', 'integer', Rule::exists('ad_sets', 'id')],
            'file_ids' => [$creating ? 'required' : 'sometimes', 'array', 'min:1', 'max:20'],
            'file_ids.*' => ['integer', 'distinct'],
            'captions' => [$creating ? 'required' : 'sometimes', 'array', 'min:1', 'max:'.PublishController::MAX_CAPTIONS],
            'captions.*.headline' => ['required', 'string', 'max:255'],
            'captions.*.primary_text' => ['required', 'string', 'max:2000'],
            'captions.*.cta' => ['required', Rule::in(PublishController::CTAS)],
            'identity' => [$creating ? 'prohibited' : 'sometimes', 'nullable', 'array'],
            'identity.page_id' => ['required_with:identity', 'string', 'max:255'],
            'identity.page_name' => ['nullable', 'string', 'max:255'],
            'identity.instagram_id' => ['nullable', 'string', 'max:255'],
            'revision' => [$creating ? 'prohibited' : 'required', 'integer', 'min:1'],
        ]);
    }

    /** A refused field says what it is and why (final fix 10), never «حقل daily budget مش مسموح بيه». */
    /** @return array<string, string> */
    public function messages(): array
    {
        return array_fill_keys(array_map(fn (string $f) => $f.'.prohibited', self::PROHIBITED), __('ads.launch.prohibited'));
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return array_combine(self::PROHIBITED, array_map(fn (string $f) => __('ads.launch.fields.'.$f), self::PROHIBITED));
    }
}
