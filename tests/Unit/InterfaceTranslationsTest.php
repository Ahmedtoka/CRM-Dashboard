<?php

/*
|--------------------------------------------------------------------------
| The interface dictionaries must stay mirrors of each other
|--------------------------------------------------------------------------
|
| resources/js/i18n/ar.ts is the source of truth and en.ts is its typed
| mirror. TypeScript catches a *missing* key, but nothing catches a key that
| was copied across and left in the wrong language — which is how Arabic
| strings ended up showing in English mode and vice versa. These two tests
| catch exactly that, without pulling a JS test runner into the project: the
| dictionaries are plain object literals, so a small regex reader is enough.
|
*/

/**
 * Flattens a `const x = { … };` TypeScript dictionary into "dotted.key" => "value".
 *
 * It walks the file character by character rather than matching a single big
 * pattern, so nested objects, commas inside a string and escaped quotes all
 * behave. Only string leaves are returned — that is all either check needs.
 *
 * @return array<string, string>
 */
function readI18nDictionary(string $file): array
{
    // Unit tests do not boot the framework, so resolve against the project root directly.
    $source = file_get_contents(dirname(__DIR__, 2).'/'.$file);

    expect($source)->not->toBeFalse("could not read {$file}");

    // Drop the leading import/const line so the walk starts at the literal.
    $start = strpos($source, '= {');
    expect($start)->not->toBeFalse("no object literal in {$file}");

    $out = [];
    $path = [];
    $key = null;
    $i = $start + 2;
    $length = strlen($source);

    while ($i < $length) {
        $char = $source[$i];

        // Comments: skip to the end of the line / block.
        if ($char === '/' && ($source[$i + 1] ?? '') === '/') {
            $i = strpos($source, "\n", $i) ?: $length;

            continue;
        }

        if ($char === '/' && ($source[$i + 1] ?? '') === '*') {
            $end = strpos($source, '*/', $i);
            $i = $end === false ? $length : $end + 2;

            continue;
        }

        // A quoted run is either a value (when a key is pending) or a quoted key.
        if ($char === "'" || $char === '"' || $char === '`') {
            [$text, $i] = readI18nString($source, $i);

            if ($key !== null) {
                $out[implode('.', [...$path, $key])] = $text;
                $key = null;
            } else {
                $key = $text;
            }

            continue;
        }

        if ($char === '{') {
            if ($key !== null) {
                $path[] = $key;
                $key = null;
            }
            $i++;

            continue;
        }

        if ($char === '}') {
            if ($path === []) {
                break;
            }
            array_pop($path);
            $key = null;
            $i++;

            continue;
        }

        // A bare identifier before a colon is a key.
        if (preg_match('/^([A-Za-z_$][\w$]*)\s*:/', substr($source, $i), $m) === 1) {
            $key = $m[1];
            $i += strlen($m[0]);

            continue;
        }

        $i++;
    }

    return $out;
}

/**
 * Reads one quoted string starting at `$i`, honouring backslash escapes.
 *
 * @return array{0: string, 1: int} the text, and the index just past the closing quote
 */
function readI18nString(string $source, int $i): array
{
    $quote = $source[$i];
    $text = '';
    $i++;

    while ($i < strlen($source)) {
        $char = $source[$i];

        if ($char === '\\') {
            $text .= $source[$i + 1] ?? '';
            $i += 2;

            continue;
        }

        if ($char === $quote) {
            return [$text, $i + 1];
        }

        $text .= $char;
        $i++;
    }

    return [$text, $i];
}

/**
 * Values in ar.ts that are allowed to have no Arabic letters at all.
 *
 * Brand names and product names we never translate, plus a handful of field
 * labels that must match a word the owner reads in someone else's dashboard
 * (Meta's app settings, Shopify's admin) — translating those would make them
 * harder to find, not easier. `switch_language` is the odd one out on purpose:
 * the toggle names the language you are switching TO, so the Arabic file
 * holds "English" and the English file holds "العربية".
 */
const LATIN_ONLY_ALLOWED_IN_ARABIC = [
    'app.name',                                          // "سوشيال CRM" still counts, but keep it listed if it is ever shortened
    'auth.email_placeholder',
    'auth.switch_language',
    'nav.settings_layout.email_placeholder',
    'nav.settings_shopify',
    'nav.switch_language',
    'orders.shopify',
    'reports.latency.p95',
    'reports.latency.p99',
    'settings.channels.facebook.redirect_uri',           // copied verbatim into Facebook Login → Settings
    'settings.integrations.meta_setup.object',           // the Meta app dashboard's own field names
    'settings.integrations.meta_setup.callback_url',
    'settings.integrations.meta_setup.verify_token',
    'settings.integrations.facebook.system_user.page_id',
    'settings.integrations.whatsapp.waba_id',
    'settings.integrations.whatsapp.phone_number_id',
    'settings.integrations.shopify.title',
    'settings.shopify.title',
    'settings.shopify.form.token',                       // "Admin API access token", Shopify's own wording
];

/** Values in en.ts that are allowed to contain Arabic letters — see the note above. */
const ARABIC_ALLOWED_IN_ENGLISH = [
    'auth.switch_language',
    'nav.switch_language',
];

const ARABIC_LETTERS = '/[\x{0600}-\x{06FF}\x{0750}-\x{077F}\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}]/u';

it('keeps ar.ts and en.ts on exactly the same keys', function () {
    $ar = readI18nDictionary('resources/js/i18n/ar.ts');
    $en = readI18nDictionary('resources/js/i18n/en.ts');

    expect($ar)->not->toBeEmpty()->and($en)->not->toBeEmpty();

    $missingInEnglish = array_values(array_diff(array_keys($ar), array_keys($en)));
    $missingInArabic = array_values(array_diff(array_keys($en), array_keys($ar)));

    expect($missingInEnglish)->toBe([], 'keys in ar.ts with no English: '.implode(', ', $missingInEnglish));
    expect($missingInArabic)->toBe([], 'keys in en.ts with no Arabic: '.implode(', ', $missingInArabic));
});

it('never leaves Arabic text in the English dictionary', function () {
    $offenders = [];

    foreach (readI18nDictionary('resources/js/i18n/en.ts') as $key => $value) {
        if (in_array($key, ARABIC_ALLOWED_IN_ENGLISH, true)) {
            continue;
        }

        if (preg_match(ARABIC_LETTERS, $value) === 1) {
            $offenders[] = "{$key} = {$value}";
        }
    }

    expect($offenders)->toBe([], 'Arabic in en.ts: '.implode(' | ', $offenders));
});

it('never leaves a purely Latin string in the Arabic dictionary', function () {
    $offenders = [];

    foreach (readI18nDictionary('resources/js/i18n/ar.ts') as $key => $value) {
        if (in_array($key, LATIN_ONLY_ALLOWED_IN_ARABIC, true)) {
            continue;
        }

        if (preg_match(ARABIC_LETTERS, $value) === 1) {
            continue;
        }

        // `{name}` placeholders, digits, punctuation and emoji are language-neutral.
        $letters = preg_replace('/\{\w+\}/', '', $value);
        $letters = preg_replace('/[^A-Za-z]/', '', (string) $letters);

        if ($letters !== '') {
            $offenders[] = "{$key} = {$value}";
        }
    }

    expect($offenders)->toBe([], 'untranslated Latin in ar.ts: '.implode(' | ', $offenders));
});

/**
 * The PHP lang files must mirror each other too.
 *
 * `validation` is excluded on purpose: Laravel ships the English rule messages
 * inside the framework and merges them, so lang/en/validation.php only needs to
 * name the fields, while lang/ar/validation.php carries the full set.
 */
it('keeps the PHP lang files on exactly the same keys', function (string $file) {
    $read = function (string $locale) use ($file): array {
        $path = dirname(__DIR__, 2)."/lang/{$locale}/{$file}.php";

        expect($path)->toBeFile();

        $flatten = function (array $rows, string $prefix = '') use (&$flatten): array {
            $out = [];

            foreach ($rows as $key => $value) {
                $dotted = $prefix === '' ? (string) $key : "{$prefix}.{$key}";
                $out += is_array($value) ? $flatten($value, $dotted) : [$dotted => $value];
            }

            return $out;
        };

        return $flatten(require $path);
    };

    $ar = $read('ar');
    $en = $read('en');

    $missingInEnglish = array_values(array_diff(array_keys($ar), array_keys($en)));
    $missingInArabic = array_values(array_diff(array_keys($en), array_keys($ar)));

    expect($missingInEnglish)->toBe([], "{$file}: no English for ".implode(', ', $missingInEnglish));
    expect($missingInArabic)->toBe([], "{$file}: no Arabic for ".implode(', ', $missingInArabic));
})->with(['cases', 'commerce', 'errors', 'labels', 'legal']);
