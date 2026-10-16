<?php

use App\Enums\Platform;
use App\Enums\UserRole;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\SupportCase;
use App\Models\User;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;

/* Support cases on the mobile API (/api/v1/cases): the web Cases page's list as JSON, plus show/update. */

function mcCase(Platform $platform, array $attrs = []): SupportCase
{
    $account = ChannelAccount::factory()->create(['platform' => $platform]);
    $conversation = Conversation::factory()->create(['channel_account_id' => $account->id]);

    return SupportCase::factory()->create($attrs + ['conversation_id' => $conversation->id]);
}

function mcModerator(Platform ...$platforms): User
{
    $u = User::factory()->create(['role' => UserRole::Moderator]);
    foreach ($platforms as $p) {
        $u->userPlatforms()->create(['platform' => $p]);
    }

    return $u;
}

it('lists only the cases on her platforms, with meta and counts', function () {
    $fb = mcCase(Platform::Facebook, ['status' => 'new']);
    mcCase(Platform::Facebook, ['status' => 'closed', 'closed_at' => now()]);
    mcCase(Platform::WhatsApp, ['status' => 'new']);
    Sanctum::actingAs(mcModerator(Platform::Facebook));

    $res = $this->getJson('/api/v1/cases')->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.current_page', 1)->assertJsonPath('meta.last_page', 1)
        ->assertJsonPath('meta.per_page', 25)->assertJsonPath('meta.total', 2)
        ->assertJsonPath('counts', ['all' => 2, 'new' => 1, 'in_progress' => 0, 'closed' => 1]);

    expect($res->json('data.1.id'))->toBe($fb->id) // newest first
        ->and(array_keys($res->json('data.0')))->toContain('type_label', 'summary_header', 'photos', 'customer', 'data');
});

it('filters by status (counts unaffected), type, search, dates and overdue, and sorts', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-10 12:00', 'Africa/Cairo'));
    $old = mcCase(Platform::Facebook, ['type' => 'return', 'status' => 'new', 'order_number' => '#5566', 'created_at' => now()->subDays(5)]);
    $late = mcCase(Platform::Facebook, ['type' => 'complaint', 'status' => 'in_progress', 'sla_due_at' => now()->subHour()]);
    $closed = mcCase(Platform::Facebook, ['type' => 'complaint', 'status' => 'closed', 'closed_at' => now()]);
    Sanctum::actingAs(User::factory()->create(['role' => UserRole::Supervisor]));

    $ids = fn (string $qs) => collect($this->getJson('/api/v1/cases'.$qs)->assertOk()->json('data'))->pluck('id')->all();

    expect($ids('?status=closed'))->toBe([$closed->id])
        ->and($this->getJson('/api/v1/cases?status=closed')->json('counts.all'))->toBe(3)
        ->and($ids('?type=return'))->toBe([$old->id])
        ->and($ids('?q=5566'))->toBe([$old->id])
        ->and($ids('?from=2026-10-09&to=2026-10-10'))->toBe([$closed->id, $late->id])
        ->and($ids('?overdue=1'))->toBe([$late->id])
        ->and($ids('?sort=id'))->toBe([$old->id, $late->id, $closed->id]);

    $this->getJson('/api/v1/cases?status=bogus')->assertUnprocessable()->assertJsonValidationErrors(['status']);
    $this->getJson('/api/v1/cases?from=10-10-2026')->assertUnprocessable()->assertJsonValidationErrors(['from']);
});

it('pages by 25', function () {
    $account = ChannelAccount::factory()->create(['platform' => Platform::Facebook]);
    $conversation = Conversation::factory()->create(['channel_account_id' => $account->id]);
    SupportCase::factory()->count(27)->create(['conversation_id' => $conversation->id]);
    Sanctum::actingAs(mcModerator(Platform::Facebook));

    $this->getJson('/api/v1/cases?page=2')->assertOk()->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.current_page', 2)->assertJsonPath('meta.last_page', 2)->assertJsonPath('meta.total', 27);
});

it('shows a case and updates its status, stamping closed_at', function () {
    $case = mcCase(Platform::Facebook, ['status' => 'new']);
    Sanctum::actingAs($mod = mcModerator(Platform::Facebook));

    $this->getJson("/api/v1/cases/{$case->id}")->assertOk()->assertJsonPath('data.id', $case->id)->assertJsonPath('data.status', 'new');

    $this->patchJson("/api/v1/cases/{$case->id}", ['status' => 'closed'])->assertOk()
        ->assertJsonPath('data.status', 'closed')->assertJsonPath('data.closed_at', fn ($v) => $v !== null);
    expect($case->fresh()->closed_at)->not->toBeNull()->and($case->fresh()->closed_by_id)->toBe($mod->id);

    $this->patchJson("/api/v1/cases/{$case->id}", ['status' => 'in_progress', 'assigned_to_id' => $mod->id])->assertOk()
        ->assertJsonPath('data.assigned_to.id', $mod->id)->assertJsonPath('data.closed_at', null);
    $this->patchJson("/api/v1/cases/{$case->id}", ['status' => 'bogus'])->assertUnprocessable();
});

it('forbids a case on another platform and 404s a missing one', function () {
    $wa = mcCase(Platform::WhatsApp);
    Sanctum::actingAs(mcModerator(Platform::Facebook));

    $this->getJson("/api/v1/cases/{$wa->id}")->assertForbidden();
    $this->patchJson("/api/v1/cases/{$wa->id}", ['status' => 'closed'])->assertForbidden();
    $this->getJson('/api/v1/cases/999999')->assertNotFound();
    expect($wa->fresh()->status)->not->toBe('closed');
});

it('refuses ads roles, inactive users and missing tokens', function () {
    $case = mcCase(Platform::Facebook);

    $this->getJson('/api/v1/cases')->assertUnauthorized();

    Sanctum::actingAs(User::factory()->create(['role' => UserRole::Content]));
    $this->getJson('/api/v1/cases')->assertForbidden();
    $this->getJson("/api/v1/cases/{$case->id}")->assertForbidden();
    app('auth')->forgetGuards();

    $inactive = mcModerator(Platform::Facebook);
    $inactive->forceFill(['is_active' => false])->save();
    $this->withToken($inactive->createToken('phone')->plainTextToken)->getJson('/api/v1/cases')->assertUnauthorized();
});
