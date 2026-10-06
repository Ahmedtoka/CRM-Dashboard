<?php

use App\Inbox\Outcomes\EpisodeEnd;
use App\Inbox\Outcomes\Outcome;

it('has exactly the contract outcomes', function () {
    expect(array_map(fn (Outcome $o) => $o->value, Outcome::cases()))
        ->toBe(['ordered', 'price', 'size_out', 'shipping', 'no_answer', 'browsing', 'service', 'other', 'unknown']);
});

it('lets a person pick everything except ordered and unknown', function () {
    expect(Outcome::agentValues())->toBe(['price', 'size_out', 'shipping', 'browsing', 'no_answer', 'service', 'other']);
});

it('counts only the lost-sale outcomes as why-not-bought reasons', function () {
    expect(Outcome::lostValues())->toBe(['price', 'size_out', 'shipping', 'no_answer', 'browsing', 'other'])
        ->and(Outcome::Service->isLostSale())->toBeFalse()
        ->and(Outcome::Ordered->isLostSale())->toBeFalse()
        ->and(Outcome::Price->isLostSale())->toBeTrue();
});

it('labels every outcome in both languages', function (string $locale) {
    app()->setLocale($locale);
    foreach (Outcome::cases() as $o) {
        expect($o->label())->not->toBe('labels.outcomes.'.$o->value);
    }
})->with(['ar', 'en']);

it('names the five episode ends', function () {
    expect(array_map(fn (EpisodeEnd $e) => $e->value, EpisodeEnd::cases()))
        ->toBe(['close', 'auto_close', 'resolve', 'api_resolve', 'idle']);
});
