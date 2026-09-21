<?php

namespace App\Http\Controllers\Web\Settings;

use App\Bot\Language\BotTranslator;
use App\Bot\Language\ClaudeTranslationEngine;
use App\Bot\Language\TranslationEngine;
use App\Bot\Language\TranslationMask;
use App\Bot\Language\TranslationSources;
use App\Http\Controllers\Concerns\RespondsWithData;
use App\Http\Controllers\Controller;
use App\Models\BotTranslation;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Settings → «الترجمات» (design 2026-09-21 §2): every Arabic text the bot can say and
 * its English, where it is used, and whether the English was written by a person or by
 * the model. The owner searches, edits inline (an edit becomes `human` and is never
 * overwritten again), asks for a retranslation, and filters the texts that have no
 * English yet.
 *
 * The «مفيش ترجمة» rows are not table rows at all: they are the sources
 * (App\Bot\Language\TranslationSources) that have no row yet, so a text that appears in
 * a new flow or script shows up here the moment it exists.
 */
class BotTranslationController extends Controller
{
    use RespondsWithData;

    public const LOCALE = 'en';

    /** Rows per page: the whole set is a few hundred texts, so one page is plenty. */
    private const LIMIT = 500;

    public function index(Request $request, BotTranslator $translator): InertiaResponse
    {
        return Inertia::render('settings/BotTranslations', [
            'rows' => $this->rows(),
            'usage' => $translator->usage(),
            'engine' => app(TranslationEngine::class) instanceof ClaudeTranslationEngine,
        ]);
    }

    /** An owner edit: stored as `human`, so no automatic translation ever replaces it. */
    public function update(Request $request, BotTranslation $translation): Response
    {
        $data = $request->validate(['text' => ['required', 'string', 'max:2000']]);

        $translation->forceFill([
            'text' => trim($data['text']),
            'origin' => BotTranslation::ORIGIN_HUMAN,
        ])->save();

        return $this->done($request, ['rows' => $this->rows()]);
    }

    /**
     * «ترجمة من جديد» for one text: the stored row is dropped and the text is translated
     * again. A `human` row is only replaced when she explicitly asks for it here.
     */
    public function retranslate(Request $request, BotTranslator $translator): Response
    {
        $data = $request->validate([
            'source' => ['required', 'string', 'max:4000'],
            'short' => ['sometimes', 'boolean'],
        ]);

        $source = $data['source'];

        if (! TranslationMask::hasArabic($source)) {
            return $this->done($request, ['rows' => $this->rows()], 422);
        }

        $hash = BotTranslation::hash($source);
        BotTranslation::query()->where('source_hash', $hash)->where('locale', self::LOCALE)->delete();

        try {
            // A fresh translator: the request-level one has the old text memoized.
            app()->forgetInstance(BotTranslator::class);
            app(BotTranslator::class)->many([$source], self::LOCALE, 'retranslate', [(bool) ($data['short'] ?? false)]);
        } catch (Throwable) {
            // The row is simply left missing; the page shows it under «مفيش ترجمة».
        }

        return $this->done($request, ['rows' => $this->rows()]);
    }

    /**
     * Every source the bot can say, joined with its translation. A source that no longer
     * exists anywhere (an old script the owner deleted) still shows, marked `orphan`, so
     * nothing silently disappears — and so the row can be cleaned up.
     *
     * @return list<array<string, mixed>>
     */
    private function rows(): array
    {
        $stored = BotTranslation::query()->where('locale', self::LOCALE)->get()->keyBy('source_hash');
        $rows = [];

        foreach (TranslationSources::all() as $source) {
            [$masked] = app(TranslationMask::class)->mask($source['text']);
            $hash = BotTranslation::hash($masked);
            $row = $stored->get($hash);
            $stored->forget($hash);

            $rows[] = [
                'id' => $row?->id,
                'source' => $masked,
                'text' => $row?->text,
                'origin' => $row?->origin,
                'context' => $source['context'],
                'short' => $source['short'],
                'orphan' => false,
            ];
        }

        foreach ($stored as $row) {
            $rows[] = [
                'id' => $row->id,
                'source' => (string) $row->source_text,
                'text' => (string) $row->text,
                'origin' => (string) $row->origin,
                'context' => (string) ($row->context ?? ''),
                'short' => false,
                'orphan' => true,
            ];
        }

        return array_slice($rows, 0, self::LIMIT);
    }
}
