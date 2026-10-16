<?php

use App\Confirmations\ConfirmationSettings;
use App\Enums\Platform;
use App\Enums\UserRole;
use App\Models\ChannelAccount;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

// WhatsApp order confirmation W4 (spec 2026-10-16): «تأكيد الأوردرات على الواتساب» settings, admin only.

beforeEach(function () {
    $this->admin = User::factory()->create(['role' => UserRole::Admin]);
});

function confirmationSettingsPayload(array $over = []): array
{
    return array_merge([
        'enabled' => true,
        'delay_minutes' => 5,
        'reminder_after_hours' => 8,
        'no_response_after_hours' => 30,
        'template_confirm' => 'order_confirm_ar',
        'template_reminder' => 'order_reminder_ar',
        'template_language' => 'ar',
        'skip_chat_orders' => false,
        'max_order_age_minutes' => 90,
    ], $over);
}

it('is for admins only', function () {
    $this->get('/settings/order-confirmations')->assertRedirect('/login');

    foreach ([UserRole::Supervisor, UserRole::Moderator] as $role) {
        $user = User::factory()->create(['role' => $role]);
        $this->actingAs($user)->get('/settings/order-confirmations')->assertForbidden();
        $this->actingAs($user)->put('/settings/order-confirmations', confirmationSettingsPayload())->assertForbidden();
    }

    expect(app(ConfirmationSettings::class)->enabled())->toBeFalse();
});

it('shows the settings with their defaults and no WhatsApp number', function () {
    $this->actingAs($this->admin)->get('/settings/order-confirmations')->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->component('settings/OrderConfirmations')
            ->where('settings.enabled', false)
            ->where('settings.delay_minutes', 3)
            ->where('settings.template_confirm', 'order_confirm_ar')
            ->where('whatsapp', null)
            ->where('limits.no_response_max', 96));
});

it('shows the live WhatsApp number state read-only', function () {
    ChannelAccount::factory()->create([
        'platform' => Platform::WhatsApp, 'driver' => 'live', 'status' => 'connected', 'name' => 'Le Voile',
        'profile' => ['display_phone_number' => '+20 100 000 0000'],
    ]);

    $this->actingAs($this->admin)->get('/settings/order-confirmations')
        ->assertInertia(fn (AssertableInertia $p) => $p->where('whatsapp.connected', true)->where('whatsapp.name', 'Le Voile')
            ->where('whatsapp.phone', '+20 100 000 0000'));
});

it('saves valid settings', function () {
    $this->actingAs($this->admin)->from('/settings/order-confirmations')
        ->put('/settings/order-confirmations', confirmationSettingsPayload())
        ->assertRedirect('/settings/order-confirmations')
        ->assertSessionHasNoErrors();

    $s = app(ConfirmationSettings::class);
    expect($s->enabled())->toBeTrue()
        ->and($s->delayMinutes())->toBe(5)
        ->and($s->reminderAfterHours())->toBe(8)
        ->and($s->noResponseAfterHours())->toBe(30)
        ->and($s->skipChatOrders())->toBeFalse()
        ->and($s->maxOrderAgeMinutes())->toBe(90)
        ->and($s->all()['enabled'])->toBeTrue();
});

it('refuses values out of range', function (array $over, string $field) {
    $this->actingAs($this->admin)->from('/settings/order-confirmations')
        ->put('/settings/order-confirmations', confirmationSettingsPayload($over))
        ->assertSessionHasErrors($field);

    expect(app(ConfirmationSettings::class)->enabled())->toBeFalse();
})->with([
    'delay over 60' => [['delay_minutes' => 61], 'delay_minutes'],
    'negative delay' => [['delay_minutes' => -1], 'delay_minutes'],
    'reminder 0' => [['reminder_after_hours' => 0], 'reminder_after_hours'],
    'reminder over 48' => [['reminder_after_hours' => 49], 'reminder_after_hours'],
    'no response not after the reminder' => [['reminder_after_hours' => 10, 'no_response_after_hours' => 10], 'no_response_after_hours'],
    'no response over 96' => [['no_response_after_hours' => 97], 'no_response_after_hours'],
    'template with spaces' => [['template_confirm' => 'order confirm'], 'template_confirm'],
    'template missing' => [['template_reminder' => ''], 'template_reminder'],
    'bad language' => [['template_language' => 'Arabic'], 'template_language'],
    'age too small' => [['max_order_age_minutes' => 5], 'max_order_age_minutes'],
    'enabled not boolean' => [['enabled' => 'yes please'], 'enabled'],
]);
