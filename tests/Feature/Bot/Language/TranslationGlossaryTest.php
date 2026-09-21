<?php

use App\Bot\Language\BotTranslator;
use App\Bot\Language\LanguageDetector;

it('keeps the brand and the agent name spelled the same way in English', function () {
    // A model asked to translate «Le Voile» produced "Lofoual" once and "Lufoual" the next time.
    config(['crm.bot.translation_glossary' => ['لوفوال' => 'Le Voile', 'ميار' => 'Mayar']]);

    $out = app(BotTranslator::class)->text('مع حضرتك ميار من لوفوال', LanguageDetector::EN);

    expect($out)->toContain('Le Voile')->toContain('Mayar')
        ->not->toContain('لوفوال')->not->toContain('ميار');
});
