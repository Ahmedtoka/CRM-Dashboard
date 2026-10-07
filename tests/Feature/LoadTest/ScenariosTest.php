<?php

use App\Enums\Platform;
use App\Models\Ad;
use App\Simulator\LoadTest\Scenarios;
use App\Support\Emoji;

it('has at least fifteen distinct problem types, each with an opener, 2-3 follow-ups and a thanks line', function () {
    $all = Scenarios::all();

    expect(count($all))->toBeGreaterThanOrEqual(15)
        ->and(array_keys($all))->toContain(
            'quality_complaint', 'return', 'size_exchange', 'late_order', 'wrong_item', 'price', 'size_color',
            'from_ad', 'payment_link', 'branch_hours', 'track_order', 'cancel_order', 'discount', 'shipping_governorate', 'slow_reply',
        );

    foreach ($all as $key => $s) {
        expect($s['opener'])->toBeString()->not->toBeEmpty()
            ->and(count($s['followups']))->toBeGreaterThanOrEqual(2)->toBeLessThanOrEqual(3)
            ->and($s['thanks'])->toBeString()->not->toBeEmpty()
            ->and($s['category'])->toBeString()->not->toBeEmpty();

        foreach ([$s['opener'], $s['thanks'], ...$s['followups']] as $line) {
            expect(Emoji::contains($line))->toBeFalse("emoji in {$key}");
        }
    }
});

it('plays at most three follow-ups per chat, the last one always the thanks', function () {
    foreach (Scenarios::all() as $key => $s) {
        $lines = Scenarios::followUps($key);

        expect(count($lines))->toBe(3)->and(end($lines))->toBe($s['thanks'])
            ->and(Scenarios::followUp($key, 3))->toBe($s['thanks'])
            ->and(Scenarios::followUp($key, 4))->toBeNull();
    }
});

it('picks realistic Egyptian names, random platforms among the three test channels', function () {
    expect(count(Scenarios::names()))->toBeGreaterThanOrEqual(20);

    $platforms = collect(range(1, 60))->map(fn () => Scenarios::randomPlatform())->unique();
    expect($platforms->every(fn (Platform $p) => in_array($p, [Platform::Facebook, Platform::Instagram, Platform::WhatsApp], true)))->toBeTrue()
        ->and($platforms->count())->toBe(3);
});

it('sends the ad scenario with a referral of a real synced ad, or none when no ad is synced', function () {
    expect(Scenarios::referral('from_ad'))->toBeNull()
        ->and(Scenarios::referral('price'))->toBeNull();

    $ad = Ad::factory()->create(['external_id' => '120200000777', 'name' => 'فستان سواريه ستان']);

    expect(Scenarios::referral('from_ad'))->toMatchArray(['source' => 'ADS', 'ad_id' => '120200000777'])
        ->and(Scenarios::referral('from_ad')['ads_context_data']['ad_title'])->toBe('فستان سواريه ستان')
        ->and(Scenarios::referral('price'))->toBeNull();
});
