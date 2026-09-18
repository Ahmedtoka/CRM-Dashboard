<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Middleware\SetLocale;
use App\Models\DataDeletionRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;
use Illuminate\View\View;

/**
 * Public privacy policy, terms and data-deletion pages (Meta App Review).
 *
 * Rendered as plain server-side Blade — not Inertia — so the full text is in the first
 * HTML response for Meta's crawler and for people with scripts turned off. Language:
 * `?lang=ar|en`, else the session / account locale.
 */
class LegalController extends Controller
{
    /** Shown as "Last updated" on every legal page; bump whenever the text changes. */
    public const LAST_UPDATED = '2026-09-18';

    public function privacy(Request $request): View
    {
        return $this->page($request, 'privacy');
    }

    public function terms(Request $request): View
    {
        return $this->page($request, 'terms');
    }

    public function dataDeletion(Request $request): View
    {
        $code = trim((string) $request->query('code', ''));
        $lookup = null;

        if ($code !== '') {
            $found = strlen($code) <= 40
                ? DataDeletionRequest::where('confirmation_code', strtoupper($code))->first()
                : null;

            // Status and dates only — the row's platform id is never shown.
            $lookup = [
                'code' => $code,
                'status' => $found?->status,
                'requested_at' => $found?->requested_at,
                'completed_at' => $found?->completed_at,
            ];
        }

        return $this->page($request, 'data-deletion', ['lookup' => $lookup]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function page(Request $request, string $page, array $data = []): View
    {
        $lang = $request->query('lang');

        if (is_string($lang) && in_array($lang, SetLocale::SUPPORTED, true)) {
            app()->setLocale($lang);
        }

        $locale = app()->getLocale() === 'en' ? 'en' : 'ar';
        app()->setLocale($locale);

        $contactEmail = config('crm.legal.contact_email') ?: null;
        $replace = [
            ':company' => (string) config('crm.legal.company_name', 'Le Voile'),
            ':months' => (string) (int) config('crm.legal.retention_months', 24),
            ':contact' => $contactEmail ?? trans('legal.contact_fallback'),
            ':website' => 'levoilestores.com',
        ];

        $content = trans('legal.pages.'.str_replace('-', '_', $page));

        return view('legal.'.$page, $data + [
            'page' => $page,
            'locale' => $locale,
            'content' => $this->fill($content, $replace),
            'ui' => $this->fill(trans('legal.ui'), $replace),
            'company' => $replace[':company'],
            'contactEmail' => $contactEmail,
            'rich' => fn (string $text) => $this->rich($text, $contactEmail),
            'lastUpdated' => Carbon::parse(self::LAST_UPDATED)->locale($locale === 'ar' ? 'ar_EG' : 'en')->translatedFormat('j F Y'),
            'otherLocale' => $locale === 'ar' ? 'en' : 'ar',
            'switchUrl' => $request->fullUrlWithQuery(['lang' => $locale === 'ar' ? 'en' : 'ar']),
        ]);
    }

    /** Escapes a policy sentence, then turns the contact email in it into a mailto link. */
    private function rich(string $text, ?string $email): HtmlString
    {
        $html = e($text);

        if ($email) {
            $link = '<a href="mailto:'.e($email).'" dir="ltr" class="font-medium text-primary underline-offset-4 hover:underline">'.e($email).'</a>';
            $html = str_replace(e($email), $link, $html);
        }

        return new HtmlString($html);
    }

    /**
     * Replaces the :placeholders in every string of a (nested) translation array.
     *
     * @param  array<array-key, mixed>|string  $value
     * @param  array<string, string>  $replace
     * @return array<array-key, mixed>|string
     */
    private function fill(array|string $value, array $replace): array|string
    {
        if (is_string($value)) {
            return strtr($value, $replace);
        }

        return array_map(fn ($item) => is_array($item) || is_string($item) ? $this->fill($item, $replace) : $item, $value);
    }
}
