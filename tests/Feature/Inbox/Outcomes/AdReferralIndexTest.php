<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

// Review round 1 (IMPORTANT 4): the chat funnel's referral lookup by ad and time is indexed.

it('indexes the referrals by ad and time, reversibly and re-runnably', function () {
    $path = 'database/migrations/2026_10_08_300020_add_ad_time_index_to_conversation_ad_referrals.php';
    expect(Schema::hasIndex('conversation_ad_referrals', ['ad_external_id', 'referred_at']))->toBeTrue();

    Artisan::call('migrate:rollback', ['--path' => $path]);
    expect(Schema::hasIndex('conversation_ad_referrals', 'conv_ad_referrals_ad_time_idx'))->toBeFalse();

    Artisan::call('migrate', ['--path' => $path]);
    expect(Schema::hasIndex('conversation_ad_referrals', 'conv_ad_referrals_ad_time_idx'))->toBeTrue();

    (require base_path($path))->up(); // guarded: a second run changes nothing
    expect(Schema::hasIndex('conversation_ad_referrals', 'conv_ad_referrals_ad_time_idx'))->toBeTrue();
});
