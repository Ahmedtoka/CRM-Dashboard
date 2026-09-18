<?php

use App\Bot\Flows\BranchFinder;
use App\Models\Branch;

it('seeds the 26 website branches and lists every branch of an area', function () {
    expect(Branch::count())->toBe(26)
        ->and(app(BranchFinder::class)->match('انا ساكنة في مدينه نصر'))->toBe('nasr_city')
        ->and(app(BranchFinder::class)->match('nasr city'))->toBe('nasr_city')
        ->and(app(BranchFinder::class)->match('قريب من سيتي ستارز'))->toBe('nasr_city')
        ->and(app(BranchFinder::class)->match('الشيخ زايد'))->toBe('october_zayed')
        ->and(app(BranchFinder::class)->match('اسوان'))->toBeNull();

    $text = app(BranchFinder::class)->listText('nasr_city');
    expect(substr_count($text, '📍'))->toBe(5)
        ->and($text)->toContain('01094170690')->and($text)->toContain('https://goo.gl/maps/uRQpbNA7naTHhCTL8');
});

it('lists areas with counts and skips inactive branches', function () {
    Branch::where('area_key', 'zagazig')->update(['is_active' => false]);
    $keys = collect(app(BranchFinder::class)->areas())->pluck('key');

    expect($keys)->toContain('nasr_city')->not->toContain('zagazig')
        ->and(collect(app(BranchFinder::class)->areas())->firstWhere('key', 'alexandria')['count'])->toBe(4);
});

it('guesses null on the fake ai driver without hitting the network', function () {
    config(['crm.drivers.ai' => 'fake']);

    expect(app(BranchFinder::class)->guess('حاجة مش واضحة خالص'))->toBeNull();
});
