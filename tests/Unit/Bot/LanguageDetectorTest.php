<?php

use App\Bot\Language\LanguageDetector;

// Round b1 live eval (2026-10-19, scenario 50): «tab hayegy emta el mandoub?» was read as English, so the
// bot answered an Egyptian customer in English. Franco without digit-letters must still be Arabic.
it('reads Franco without digit-letters as Arabic', function (string $text) {
    expect((new LanguageDetector)->detect($text))->toBe(LanguageDetector::AR);
})->with([
    'tab hayegy emta el mandoub?',
    'el order hayewsal emta',
    'howa el fostan da mawgood?',
    'ana 3ayza arga3 el fostan',
    'tamam shokran ya gamda',
    'bokra el mandoub hayegy?',
    'el se3r kam',
    'feen el far3 bta3ko fe nasr city',
    'howa fe isdal eswed?',
]);

it('still reads English as English', function (string $text) {
    expect((new LanguageDetector)->detect($text))->toBe(LanguageDetector::EN);
})->with([
    'When will my order arrive?',
    'Do you ship to Alexandria and how much is it',
    'I want to return the dress, size is too small',
    'hello, is the black dress available in L?',
    'ok thanks',
]);

it('says nothing about digits, emoji and links alone', function (string $text) {
    expect((new LanguageDetector)->detect($text))->toBeNull();
})->with(['209129', '🌸', 'https://levoilestores.com/', '']);
