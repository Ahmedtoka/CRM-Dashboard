<?php

namespace App\Bot\Language;

use App\Models\BotTranslation;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Everything the bot says in a language other than Arabic goes through here
 * (design 2026-09-21 §2):
 *
 *   a human translation (an owner edit, never overwritten)
 *   → an automatic translation already stored
 *   → translate now with Claude Haiku (one batched call, then cached)
 *   → the Arabic as it is.
 *
 * Sources are masked first (App\Bot\Language\TranslationMask), so one row covers every
 * order number and price, and no link, number or emoji can come back changed. Button
 * titles are asked for short and trimmed to Messenger's 20 characters.
 *
 * Cost guard: at most `crm.bot.translation_daily_cap` new automatic translations a day;
 * beyond that the Arabic goes out and the skip is logged.
 */
class BotTranslator
{
    /** @var array<string, string> masked-hash|locale => translation, for this request */
    private array $memo = [];

    /** @var array<string, true> masked-hash|locale the engine already failed on this request */
    private array $failed = [];

    private ?int $usedToday = null;

    public function __construct(
        private readonly TranslationEngine $engine,
        private readonly TranslationMask $mask,
    ) {}

    /** One text. Arabic in, $locale out (the Arabic itself when $locale is ar). */
    public function text(string $text, string $locale, ?string $context = null): string
    {
        return $this->many([$text], $locale, $context)[0];
    }

    /** A button title: short, and never longer than Messenger's 20 characters. */
    public function button(string $title, string $locale, ?string $context = null): string
    {
        return $this->many([$title], $locale, $context, [true])[0];
    }

    /**
     * Several texts of one message in one engine call.
     *
     * @param  list<string>  $texts
     * @param  list<bool>  $short  which of them are button titles
     * @return list<string> in the same order, the Arabic where nothing could be translated
     */
    public function many(array $texts, string $locale, ?string $context = null, array $short = []): array
    {
        $texts = array_values($texts);

        if ($locale === LanguageDetector::AR || $texts === []) {
            return $texts;
        }

        $jobs = [];

        foreach ($texts as $i => $text) {
            if (trim($text) === '' || ! TranslationMask::hasArabic($text)) {
                continue;
            }

            [$masked, $values] = $this->mask->mask($text);
            $jobs[$i] = ['masked' => $masked, 'values' => $values, 'hash' => BotTranslation::hash($masked), 'short' => (bool) ($short[$i] ?? false)];
        }

        if ($jobs === []) {
            return $texts;
        }

        $this->load($jobs, $locale);
        $this->fetchMissing($jobs, $locale, $context);

        $out = $texts;

        foreach ($jobs as $i => $job) {
            $translated = $this->memo[$job['hash'].'|'.$locale] ?? null;

            if ($translated !== null) {
                $out[$i] = $this->mask->restore($translated, $job['values']);
            }
        }

        return $out;
    }

    /** The stored translation of one text, without ever calling the engine. */
    public function cached(string $text, string $locale): ?string
    {
        if ($locale === LanguageDetector::AR || trim($text) === '' || ! TranslationMask::hasArabic($text)) {
            return null;
        }

        [$masked, $values] = $this->mask->mask($text);
        $hash = BotTranslation::hash($masked);
        $key = $hash.'|'.$locale;

        if (! array_key_exists($key, $this->memo)) {
            $row = BotTranslation::query()->where('source_hash', $hash)->where('locale', $locale)->first();

            if ($row === null) {
                return null;
            }

            $this->memo[$key] = (string) $row->text;
        }

        return $this->mask->restore($this->memo[$key], $values);
    }

    /** How many automatic translations were stored today, and the cap. */
    public function usage(): array
    {
        return ['used' => $this->used(), 'cap' => self::cap()];
    }

    public static function cap(): int
    {
        return max(0, (int) config('crm.bot.translation_daily_cap', 200));
    }

    /** @param  array<int, array{hash:string}>  $jobs */
    private function load(array $jobs, string $locale): void
    {
        $wanted = [];

        foreach ($jobs as $job) {
            if (! array_key_exists($job['hash'].'|'.$locale, $this->memo)) {
                $wanted[$job['hash']] = true;
            }
        }

        if ($wanted === []) {
            return;
        }

        BotTranslation::query()->where('locale', $locale)->whereIn('source_hash', array_keys($wanted))
            ->get()->each(fn (BotTranslation $row) => $this->memo[$row->source_hash.'|'.$locale] = (string) $row->text);
    }

    /** @param  array<int, array{masked:string, hash:string, short:bool}>  $jobs */
    private function fetchMissing(array $jobs, string $locale, ?string $context): void
    {
        $sources = [];
        $short = [];
        $hashes = [];

        foreach ($jobs as $job) {
            $key = $job['hash'].'|'.$locale;

            if (array_key_exists($key, $this->memo) || isset($this->failed[$key]) || in_array($job['hash'], $hashes, true)) {
                continue;
            }

            $hashes[] = $job['hash'];
            $sources[] = $job['masked'];
            $short[] = $job['short'];
        }

        if ($sources === []) {
            return;
        }

        $budget = self::cap() - $this->used();

        if ($budget <= 0) {
            Log::warning('bot.translation.cap_reached', ['locale' => $locale, 'cap' => self::cap(), 'skipped' => count($sources)]);

            return;
        }

        if (count($sources) > $budget) {
            $sources = array_slice($sources, 0, $budget);
            $short = array_slice($short, 0, $budget);
            $hashes = array_slice($hashes, 0, $budget);
        }

        try {
            $translations = $this->engine->translate($sources, $locale, $short);
        } catch (Throwable $e) {
            Log::warning('bot.translation.failed', ['locale' => $locale, 'error' => $e->getMessage()]);

            foreach ($hashes as $hash) {
                $this->failed[$hash.'|'.$locale] = true;
            }

            return;
        }

        foreach ($hashes as $i => $hash) {
            $text = $translations[$i] ?? null;
            $key = $hash.'|'.$locale;

            if (! is_string($text) || trim($text) === '' || ! $this->mask->keepsMarkers($sources[$i], $text)) {
                $this->failed[$key] = true;

                if (is_string($text)) {
                    Log::warning('bot.translation.markers_lost', ['locale' => $locale, 'source' => $sources[$i]]);
                }

                continue;
            }

            $this->memo[$key] = $text;
            $this->store($hash, $sources[$i], $locale, $text, $context);
        }
    }

    private function store(string $hash, string $masked, string $locale, string $text, ?string $context): void
    {
        try {
            $created = BotTranslation::query()->firstOrCreate(
                ['source_hash' => $hash, 'locale' => $locale],
                ['source_text' => $masked, 'text' => $text, 'origin' => BotTranslation::ORIGIN_AUTO, 'context' => $context],
            );

            if ($created->wasRecentlyCreated) {
                $this->usedToday = $this->used() + 1;
            }
        } catch (Throwable $e) {
            Log::warning('bot.translation.store_failed', ['error' => $e->getMessage()]);
        }
    }

    private function used(): int
    {
        return $this->usedToday ??= BotTranslation::query()
            ->where('origin', BotTranslation::ORIGIN_AUTO)
            ->whereDate('created_at', now()->toDateString())
            ->count();
    }
}
