<?php

use App\Ads\Launch\LaunchState;
use App\Enums\UserRole;
use App\Models\AdLaunch;
use App\Models\AdMaterial;
use App\Models\AdMaterialFile;
use App\Models\AdsAuditLog;
use App\Models\AdSet;
use App\Models\BotSetting;
use App\Models\MediaBuyer;
use App\Models\User;
use App\Models\UserNotification;
use Tests\Support\LaunchWorld;

beforeEach(fn () => LaunchWorld::boot());

function ldStore($test, $user, array $w, array $over = [])
{
    return $test->actingAs($user)->postJson("/ads/materials/{$w['material']->id}/launches", $over + [
        'adset_id' => $w['adset']->id, 'file_ids' => [$w['files'][0]->id], 'captions' => [LaunchWorld::caption()],
    ]);
}

it('lets content prepare a draft on an open slot with the product link (T1)', function () {
    $w = LaunchWorld::make();

    ldStore($this, $w['content'], $w)->assertCreated()->assertJsonPath('launch.state', 'draft')->assertJsonPath('launch.revision', 1);

    $l = AdLaunch::sole();
    expect($l->prepared_by_id)->toBe($w['content']->id)->and($l->ad_account_id)->toBe($w['account']->id)
        ->and($l->adset_external_id)->toBe($w['adset']->external_id)->and($l->campaign_name)->toBe($w['campaign']->name)
        ->and($l->link)->toBe(rtrim(BotSetting::current()->storeUrl(), '/').'/products/silk-abaya')
        ->and(AdsAuditLog::where('action', 'launch.created')->count())->toBe(1);
});

it('TC-07: refuses a closed slot, budget / link / objective / allow_duplicate inputs and foreign files (422)', function () {
    $w = LaunchWorld::make();
    $closed = AdSet::factory()->create(['ad_campaign_id' => $w['campaign']->id]);
    $foreign = AdMaterialFile::factory()->create(['ad_material_id' => AdMaterial::factory()->create()->id]);

    ldStore($this, $w['content'], $w, ['adset_id' => $closed->id])->assertStatus(422)->assertJsonValidationErrors('adset_id');
    ldStore($this, $w['content'], $w, ['daily_budget' => 5000, 'link' => 'https://evil.test', 'objective' => 'SALES', 'allow_duplicate' => true, 'url_tags' => 'x'])
        ->assertStatus(422)->assertJsonValidationErrors(['daily_budget', 'link', 'objective', 'allow_duplicate', 'url_tags']);
    ldStore($this, $w['content'], $w, ['file_ids' => [$foreign->id]])->assertStatus(422)->assertJsonValidationErrors('file_ids');
    ldStore($this, $w['content'], $w, ['captions' => [LaunchWorld::caption(), LaunchWorld::caption()]])->assertStatus(422)->assertJsonValidationErrors('captions');
    expect(AdLaunch::count())->toBe(0);
});

it('refuses a material without a product, and an agent', function () {
    $w = LaunchWorld::make();
    $w['material']->update(['product_id' => null]);

    ldStore($this, $w['content'], $w)->assertStatus(422)->assertJsonValidationErrors('material');
    ldStore($this, $w['agent'], $w)->assertForbidden();
});

it('submits to the buyer holding the account (T2): revision++, original kept, buyer notified', function () {
    $w = LaunchWorld::make();
    $l = LaunchWorld::launch($w);

    $this->actingAs($w['content'])->postJson("/ads/launches/{$l->public_id}/submit", ['revision' => 1])->assertOk()
        ->assertJsonPath('launch.state', 'buyer_review')->assertJsonPath('launch.revision', 2);

    $l->refresh();
    expect($l->reviewer_buyer_id)->toBe($w['buyer']->id)->and($l->submitted_at)->not->toBeNull()
        ->and($l->original['captions'])->toBe([LaunchWorld::caption()])->and($l->checks_hash)->toHaveLength(64)
        ->and(UserNotification::where('type', 'ads.launch.submitted')->where('user_id', $w['buyerUser']->id)->count())->toBe(1);
});

it('keeps a draft that fails a blocking check and answers with the checks', function () {
    $w = LaunchWorld::make();
    $l = LaunchWorld::launch($w, LaunchState::Draft, ['captions' => [['headline' => 'H', 'primary_text' => 'بـ 500 جنيه', 'cta' => 'SHOP_NOW']]]);

    $this->actingAs($w['content'])->postJson("/ads/launches/{$l->public_id}/submit", ['revision' => 1])->assertStatus(422)
        ->assertJsonPath('code', 'checks_failed')->assertJsonPath('details.keys.0', 'caption_price');
    expect($l->fresh()->state)->toBe(LaunchState::Draft);
});

it('answers 409 on a stale revision', function () {
    $w = LaunchWorld::make();
    $l = LaunchWorld::launch($w);

    $this->actingAs($w['content'])->postJson("/ads/launches/{$l->public_id}/submit", ['revision' => 7])->assertStatus(409)->assertJsonPath('code', 'launch_changed');
});

it('TC-02: buyer sends back with a reason, content edits and resubmits', function () {
    $w = LaunchWorld::make();
    $l = LaunchWorld::launch($w);
    $this->actingAs($w['content'])->postJson("/ads/launches/{$l->public_id}/submit", ['revision' => 1])->assertOk();

    $this->actingAs($w['buyerUser'])->postJson("/ads/launches/{$l->public_id}/send-back", ['code' => 'caption_wrong', 'text' => 'اكتبي المقاسات'])->assertOk()
        ->assertJsonPath('launch.state', 'changes_requested');
    $note = UserNotification::where('type', 'ads.launch.changes_requested')->sole();
    expect($note->user_id)->toBe($w['content']->id)->and($note->data['code'])->toBe('caption_wrong')->and($note->data['reason'])->toBe('اكتبي المقاسات');

    $this->actingAs($w['content'])->putJson("/ads/launches/{$l->public_id}", ['revision' => 2, 'captions' => [LaunchWorld::caption(2)]])->assertOk()->assertJsonPath('launch.revision', 3);
    $this->actingAs($w['content'])->postJson("/ads/launches/{$l->public_id}/submit", ['revision' => 3])->assertOk()
        ->assertJsonPath('launch.state', 'buyer_review')->assertJsonPath('launch.revision', 4);
    expect($l->fresh()->original['captions'])->toBe([LaunchWorld::caption()]); // the first submit's version stays the diff base
});

it('requires a known reason code, and text for other', function () {
    $w = LaunchWorld::make();
    $l = LaunchWorld::launch($w, LaunchState::BuyerReview);

    $this->actingAs($w['buyerUser'])->postJson("/ads/launches/{$l->public_id}/send-back", ['code' => 'bogus'])->assertStatus(422);
    $this->actingAs($w['buyerUser'])->postJson("/ads/launches/{$l->public_id}/send-back", ['code' => 'other'])->assertStatus(422);
});

it('lets only the reviewing buyer or Ads authority send back or edit in review', function () {
    $w = LaunchWorld::make();
    $l = LaunchWorld::launch($w, LaunchState::BuyerReview);
    $stranger = User::factory()->create(['role' => UserRole::MediaBuyer]);
    MediaBuyer::factory()->create(['user_id' => $stranger->id]);

    $this->actingAs($stranger)->postJson("/ads/launches/{$l->public_id}/send-back", ['code' => 'off_brand'])->assertNotFound(); // cannot even see it
    $this->actingAs($w['supervisor'])->postJson("/ads/launches/{$l->public_id}/send-back", ['code' => 'off_brand'])->assertForbidden()->assertJsonPath('code', 'launch_forbidden');
    $this->actingAs($w['content'])->putJson("/ads/launches/{$l->public_id}", ['revision' => 1, 'captions' => [LaunchWorld::caption(3)]])->assertForbidden();
    $this->actingAs($w['manager'])->postJson("/ads/launches/{$l->public_id}/send-back", ['code' => 'off_brand'])->assertOk();
});

it('TC-03 (edit part): the buyer edits in review, revision++, the diff is audited', function () {
    $w = LaunchWorld::make();
    $l = LaunchWorld::launch($w, LaunchState::BuyerReview, ['original' => ['ad_set_id' => null, 'file_ids' => [], 'captions' => [LaunchWorld::caption()]]]);

    $this->actingAs($w['buyerUser'])->putJson("/ads/launches/{$l->public_id}", [
        'revision' => 1, 'captions' => [LaunchWorld::caption(9)], 'identity' => ['page_id' => 'fake_page_1', 'page_name' => 'Le Voile'],
    ])->assertOk()->assertJsonPath('launch.state', 'buyer_review')->assertJsonPath('launch.revision', 2);

    $audit = AdsAuditLog::where('action', 'launch.edited')->sole();
    expect($audit->meta['before']['captions'][0]['headline'])->toBe('عباية حرير 1')->and($audit->meta['after']['captions'][0]['headline'])->toBe('عباية حرير 9')
        ->and($l->fresh()->identity['page_id'])->toBe('fake_page_1');
});

it('withdraws (T16): content in draft, buyer in review; content not in review', function () {
    $w = LaunchWorld::make();
    $draft = LaunchWorld::launch($w);
    $review = LaunchWorld::launch($w, LaunchState::BuyerReview);

    $this->actingAs($w['content'])->postJson("/ads/launches/{$draft->public_id}/withdraw")->assertOk()->assertJsonPath('launch.state', 'withdrawn');
    $this->actingAs($w['content'])->postJson("/ads/launches/{$review->public_id}/withdraw")->assertForbidden();
    $this->actingAs($w['buyerUser'])->postJson("/ads/launches/{$review->public_id}/withdraw")->assertOk()->assertJsonPath('launch.state', 'withdrawn');
});

it('returns review launches to content when their slot closes (E13)', function () {
    $w = LaunchWorld::make();
    $l = LaunchWorld::launch($w, LaunchState::BuyerReview);

    $this->actingAs($w['buyerUser'])->postJson("/ads/slots/{$w['adset']->id}", ['open' => false])->assertOk();

    expect($l->fresh()->state)->toBe(LaunchState::ChangesRequested)->and($l->fresh()->decision_code)->toBe('slot_closed')
        ->and(UserNotification::where('type', 'ads.launch.changes_requested')->count())->toBe(1);
});

it('names a refused spend or link field in words, not its raw key (final fix 10)', function () {
    $w = LaunchWorld::make();
    $w['content']->forceFill(['locale' => 'ar'])->save();

    $errors = ldStore($this, $w['content'], $w, ['daily_budget' => 5000, 'link' => 'https://evil.test', 'targeting' => ['x' => 1]])
        ->assertStatus(422)->json('errors');

    expect($errors['daily_budget'][0])->toBe('مش مسموح تحدد الميزانية اليومية في طلب الإطلاق، دي بتتظبط وقت النشر.')
        ->and($errors['link'][0])->toContain('اللينك')
        ->and($errors['targeting'][0])->toContain('الاستهداف')
        ->and(implode(' ', array_merge(...array_values($errors))))->not->toContain('daily budget');
});

it('final review A-m1: refuses a review identity whose page is not one of the account pages (422)', function () {
    $w = LaunchWorld::make();
    $l = LaunchWorld::launch($w, LaunchState::BuyerReview, ['original' => ['ad_set_id' => null, 'file_ids' => [], 'captions' => [LaunchWorld::caption()]]]);

    $this->actingAs($w['buyerUser'])->putJson("/ads/launches/{$l->public_id}", [
        'revision' => 1, 'identity' => ['page_id' => 'someone_elses_page', 'page_name' => 'Other'],
    ])->assertUnprocessable()->assertJsonValidationErrors(['identity.page_id']);
    expect($l->fresh()->identity)->toBeNull()->and($l->fresh()->revision)->toBe(1);
});
