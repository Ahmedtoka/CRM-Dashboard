<?php

use App\Bot\Flows\FlowScripts;
use App\Support\Emoji;
use Symfony\Component\Finder\Finder;

/**
 * Spec 2026-10-01 §6: nothing the system writes carries an emoji. Lines that list emoji the
 * system must RECOGNISE in customer messages carry the marker `emoji-input` and are skipped.
 */
function emojiOffenders(Finder $files): array
{
    $bad = [];
    foreach ($files as $file) {
        // `u` matters: without it \R also matches the byte 0x85, which sits inside ★ and «م».
        foreach (preg_split('/\R/u', $file->getContents()) as $i => $line) {
            if (str_contains($line, 'emoji-input')) {
                continue;
            }
            if (Emoji::contains($line)) {
                $bad[] = $file->getRelativePathname().':'.($i + 1).'  '.trim(mb_substr($line, 0, 120));
            }
        }
    }

    return $bad;
}

it('has no emoji in application code, config, lang files, seeders or the UI', function () {
    $finder = (new Finder)->files()->in([base_path('app'), base_path('config'), base_path('lang'), base_path('database/seeders'), base_path('resources')])
        ->name(['*.php', '*.ts', '*.vue', '*.js', '*.json'])->notPath('#(^|/)node_modules/#');
    expect(emojiOffenders($finder))->toBe([]);
});

it('has no emoji in any default script text', function () {
    // all() maps key => ['title' => …, 'body' => …]: every string leaf is checked.
    $bad = collect(FlowScripts::all())->filter(fn ($text) => Emoji::stripDeep($text) !== $text);
    expect($bad->keys()->all())->toBe([]);
});
