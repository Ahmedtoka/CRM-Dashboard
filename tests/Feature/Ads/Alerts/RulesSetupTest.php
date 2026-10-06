<?php

use App\Ads\Alerts\RuleSettings;
use App\Enums\UserRole;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\AlertWorld as W;

beforeEach(fn () => W::freeze());

// S2 owns the «القواعد» page (Ads/SetupRules); S5 adds its numbers under the `rules` prop and owns the writes.
it('shows the rules tab with the 2.5 default floor and the shadow switch off', function () {
    W::account(['name' => 'LV-Main 2']);

    $this->withoutVite()->actingAs(W::authority())->get('/ads/setup/rules')->assertOk()
        ->assertInertia(fn (Assert $p) => $p->component('Ads/SetupRules', false)
            ->has('settings.tax_rate')
            ->where('rules.notify_enabled', false)->where('rules.can_edit', true)->where('rules.default_floor', 2.5)
            ->where('rules.general.low_stock_units', 10)
            ->where('rules.accounts.0.name', 'LV-Main 2')->where('rules.accounts.0.effective.floor', 2.5)->where('rules.accounts.0.effective.is_default', true));
});

it('lets Ads authority save global and account numbers, and clear an account value', function () {
    $acc = W::account();
    $admin = W::authority();

    $this->actingAs($admin)->put('/ads/setup/rules', ['account_id' => null, 'margin_pct' => 55, 'shipping_subsidy' => 60, 'return_cost' => 70])->assertRedirect();
    $this->actingAs($admin)->put('/ads/setup/rules', ['account_id' => $acc->id, 'margin_pct' => 50, 'target_cpp' => 300])->assertRedirect();
    $this->actingAs($admin)->put('/ads/setup/rules', ['account_id' => $acc->id, 'target_cpp' => null])->assertRedirect();
    $this->actingAs($admin)->put('/ads/setup/rules', ['low_stock_units' => 12, 'spike_min_amount' => 2000])->assertRedirect();

    $s = app(RuleSettings::class);
    expect($s->globalInputs()['margin_pct'])->toBe(55.0)->and($s->accountInputs($acc->id))->toMatchArray(['margin_pct' => 50.0, 'target_cpp' => null])
        ->and($s->lowStockUnits())->toBe(12)->and($s->spikeMinAmount())->toBe(2000.0);
});

it('validates the numbers', function () {
    $this->actingAs(W::authority())->put('/ads/setup/rules', ['margin_pct' => 150, 'target_cpp' => 0])
        ->assertSessionHasErrors(['margin_pct', 'target_cpp']);
});

it('flips the notifications switch for Ads authority only', function () {
    $this->actingAs(W::authority())->put('/ads/setup/rules/notify', ['enabled' => true])->assertRedirect();
    expect(app(RuleSettings::class)->notifyEnabled())->toBeTrue();

    $supervisor = User::factory()->create(['role' => UserRole::Supervisor]);
    $this->actingAs($supervisor)->put('/ads/setup/rules/notify', ['enabled' => false])->assertForbidden();
    $this->actingAs($supervisor)->put('/ads/setup/rules', ['margin_pct' => 40])->assertForbidden();
    $this->withoutVite()->actingAs($supervisor)->get('/ads/setup/rules')->assertOk()
        ->assertInertia(fn (Assert $p) => $p->where('rules.can_edit', false));
});

it('keeps buyers, content and moderators out', function () {
    $buyer = W::buyer(W::account());
    $this->actingAs($buyer)->get('/ads/setup/rules')->assertForbidden();
    $this->actingAs($buyer)->put('/ads/setup/rules/notify', ['enabled' => true])->assertForbidden();
    $this->actingAs(User::factory()->create(['role' => UserRole::Moderator]))->get('/ads/setup/rules')->assertForbidden();
    $this->actingAs(User::factory()->create(['role' => UserRole::Content]))->get('/ads/setup/rules')->assertRedirect();
});
