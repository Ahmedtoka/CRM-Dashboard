<?php

// Audit A2 follow-up: one rule for "is the Meta verify token usable", shared by the
// webhook handshake, the WhatsApp callback override and the Settings → Integrations hint.

use App\Channels\Integrations\WhatsAppConnector;
use App\Channels\VerifyToken;
use App\Enums\UserRole;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['crm.drivers.channels' => 'live', 'app.url' => 'https://crm.example.com']);
});

function inProduction(): void
{
    app()->detectEnvironment(fn () => 'production');
}

it('names the problem with the configured token', function (?string $token, bool $production, ?string $problem) {
    config(['crm.meta.verify_token' => $token]);
    if ($production) {
        inProduction();
    }

    expect(VerifyToken::problem())->toBe($problem)
        ->and(VerifyToken::usable())->toBe($problem === null);
})->with([
    'missing' => [null, false, 'missing'],
    'empty in production' => ['', true, 'missing'],
    'old default in production' => ['crm-verify', true, 'default'],
    'old default locally' => ['crm-verify', false, null],
    'short in production' => ['fifteen-chars-x', true, 'too_short'],
    'short locally' => ['short', false, null],
    '16 chars in production' => ['sixteen-chars-xx', true, null],
]);

it('refuses a short token at the handshake in production', function () {
    config(['crm.meta.verify_token' => 'fifteen-chars-x']);
    inProduction();

    $this->get('/webhooks/facebook?hub_mode=subscribe&hub_verify_token=fifteen-chars-x&hub_challenge=123')->assertForbidden();
});

it('accepts a long token at the handshake in production', function () {
    config(['crm.meta.verify_token' => 'sixteen-chars-xx']);
    inProduction();

    $this->get('/webhooks/facebook?hub_mode=subscribe&hub_verify_token=sixteen-chars-xx&hub_challenge=123')
        ->assertOk()->assertContent('123');
});

it('only overrides the WhatsApp callback with a usable token', function () {
    config(['crm.meta.verify_token' => 'a-long-random-token']);
    expect(WhatsAppConnector::canOverrideCallback())->toBeTrue();

    config(['crm.meta.verify_token' => 'crm-verify']);
    inProduction();
    expect(WhatsAppConnector::canOverrideCallback())->toBeFalse();
});

it('tells the admin on Settings → Integrations when the token is unusable', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    config(['crm.meta.verify_token' => 'crm-verify']);
    inProduction();

    $this->actingAs($admin)->get('/settings/integrations')->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('meta.verify_token_problem', 'default')
            ->where('meta.can_override_callback', false));

    config(['crm.meta.verify_token' => 'a-long-random-token']);

    $this->actingAs($admin)->get('/settings/integrations')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('meta.verify_token_problem', null));
});
