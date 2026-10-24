<?php

use App\Confirmations\ConfirmationSettings;
use App\Confirmations\Contracts\ConfirmationSender;
use App\Confirmations\Jobs\SendOrderConfirmation;
use App\Confirmations\OrderConfirmations;
use App\Confirmations\Readiness\FakeTemplateStatus;
use App\Confirmations\Readiness\TemplateStatusSource;
use App\Confirmations\Readiness\WhatsAppReadiness;
use App\Confirmations\WhatsApp\TemplateParams;
use App\Confirmations\WhatsApp\WhatsAppConfirmationSender;
use App\Enums\OrderConfirmationStatus;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\Platform;
use App\Enums\UserRole;
use App\Models\ChannelAccount;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Order;
use App\Models\OrderConfirmation;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

// WhatsApp order confirmation with the real number (round 2026-10-24): the readiness checklist, the test send to the
// admin's own number, and the rules the job must keep (the configured number, the 3-minute delay, cash vs paid).

beforeEach(function () {
    $this->admin = User::factory()->create(['role' => UserRole::Admin]);
});

function readinessAccount(array $over = []): ChannelAccount
{
    return ChannelAccount::factory()->create(array_merge([
        'platform' => Platform::WhatsApp, 'driver' => 'fake', 'status' => 'connected', 'name' => 'Le Voile',
        'external_id' => '1234567890', 'credentials' => ['access_token' => 'EAAsecretValue42'],
        'profile' => ['display_phone_number' => '+20 100 000 0000', 'waba_id' => '9999', 'verified_name' => 'Le Voile'],
    ], $over));
}

function checkOf(array $report, string $key): array
{
    return collect($report['checks'])->firstWhere('key', $key);
}

it('is for admins only', function () {
    $this->getJson('/settings/whatsapp-readiness')->assertUnauthorized();
    $this->actingAs(User::factory()->create(['role' => UserRole::Supervisor]))->getJson('/settings/whatsapp-readiness')->assertForbidden();
    $this->actingAs(User::factory()->create(['role' => UserRole::Moderator]))->postJson('/settings/whatsapp-readiness/test', ['phone' => '01000000000'])->assertForbidden();
});

it('reports nothing ready when no WhatsApp number is on file', function () {
    $report = app(WhatsAppReadiness::class)->check();

    expect($report['ready'])->toBeFalse()
        ->and($report['account'])->toBeNull()
        ->and(checkOf($report, 'account')['ok'])->toBeFalse()
        ->and(checkOf($report, 'account')['reason'])->toBe('none')
        ->and(checkOf($report, 'templates')['ok'])->toBeNull()
        ->and(checkOf($report, 'enabled')['ok'])->toBeFalse();
});

it('reads the orders number, its ids and token, the template states and the settings', function () {
    readinessAccount();
    app(ConfirmationSettings::class)->save(['enabled' => true, 'delay_minutes' => 3]);

    $report = $this->actingAs($this->admin)->getJson('/settings/whatsapp-readiness')->assertOk()->json('data');

    expect($report['ready'])->toBeTrue()
        ->and($report['account']['phone'])->toBe('+20 100 000 0000')
        ->and($report['account']['is_orders_number'])->toBeTrue()
        ->and(checkOf($report, 'phone_number_id')['detail'])->toBe('1234567890')
        ->and(checkOf($report, 'waba_id')['detail'])->toBe('9999')
        ->and(checkOf($report, 'token')['ok'])->toBeTrue()
        ->and(checkOf($report, 'templates')['ok'])->toBeTrue()
        ->and(collect($report['templates'])->pluck('name')->all())->toBe(['order_confirm_ar', 'order_reminder_ar'])
        ->and($report['templates'][0]['status'])->toBe('APPROVED')
        ->and(checkOf($report, 'delay')['detail'])->toBe('3')
        ->and($report['settings']['delay_minutes'])->toBe(3);

    // No secret in the answer.
    expect(json_encode($report))->not->toContain('EAAsecretValue42');
});

it('shows a pending or rejected template and a Meta that cannot be asked', function () {
    readinessAccount(['driver' => 'live']);
    config(['crm.confirmations.allow_live_outside_production' => true]);
    app(ConfirmationSettings::class)->save(['enabled' => true]);

    $this->app->instance(TemplateStatusSource::class, new FakeTemplateStatus(['order_reminder_ar' => 'PENDING']));
    $report = app(WhatsAppReadiness::class)->check();
    expect(checkOf($report, 'templates')['ok'])->toBeFalse()
        ->and($report['templates'][1]['status'])->toBe('PENDING')
        ->and($report['ready'])->toBeFalse();

    $this->app->instance(TemplateStatusSource::class, new FakeTemplateStatus([], failWith: 'token_refused'));
    $report = app(WhatsAppReadiness::class)->check();
    expect(checkOf($report, 'templates')['ok'])->toBeNull()
        ->and(checkOf($report, 'templates')['detail'])->toBe('token_refused')
        ->and($report['templates'])->toBe([]);
});

it('explains why the number on file is not the orders number', function () {
    // A live number on a local copy: the job refuses it unless the flag says so.
    readinessAccount(['driver' => 'live']);

    $report = app(WhatsAppReadiness::class)->check();
    expect(checkOf($report, 'account')['ok'])->toBeFalse()
        ->and(checkOf($report, 'account')['reason'])->toBe('live_outside_production')
        ->and($report['account']['is_orders_number'])->toBeFalse()
        ->and(checkOf($report, 'environment')['ok'])->toBeFalse();

    ChannelAccount::query()->update(['status' => 'disconnected']);
    expect(checkOf(app(WhatsAppReadiness::class)->check(), 'account')['reason'])->toBe('disconnected');
});

it('sends the test to the number the admin typed, never to a customer\'s number', function () {
    readinessAccount();
    $order = Order::factory()->create(['source' => OrderSource::Store, 'shopify_order_id' => '555', 'shopify_order_name' => '#555', 'status' => OrderStatus::Confirmed]);
    Customer::factory()->create(['phone' => '01111111111']);

    $this->actingAs($this->admin)->postJson('/settings/whatsapp-readiness/test', ['phone' => '01111111111'])
        ->assertStatus(422)->assertJsonPath('error', 'customer_number');

    $this->actingAs($this->admin)->postJson('/settings/whatsapp-readiness/test', ['phone' => '0223456789'])
        ->assertStatus(422)->assertJsonPath('error', 'invalid_phone');

    $this->actingAs($this->admin)->postJson('/settings/whatsapp-readiness/test', ['phone' => '01000000000'])
        ->assertOk()->assertJsonPath('ok', true)->assertJsonPath('order', '#555')->assertJsonPath('phone', '+201000000000');

    // The template went out through the thread of the typed number, as a test (no confirmation row for the order).
    $message = Message::query()->latest('id')->firstOrFail();
    expect($message->body)->toContain('تأكيد الأوردر #555')
        ->and($order->fresh()->customer->normalized_phone)->not->toBe('+201000000000')
        ->and(OrderConfirmation::count())->toBe(0);
});

it('refuses the test when no usable number exists or there is no order', function () {
    $this->actingAs($this->admin)->postJson('/settings/whatsapp-readiness/test', ['phone' => '01000000000'])
        ->assertStatus(422)->assertJsonPath('error', 'no_whatsapp_account');

    readinessAccount();
    $this->actingAs($this->admin)->postJson('/settings/whatsapp-readiness/test', ['phone' => '01000000000'])
        ->assertStatus(422)->assertJsonPath('error', 'no_order');
});

it('schedules from the configured number with the 3-minute delay, and words cash vs paid orders apart', function () {
    Queue::fake([SendOrderConfirmation::class]);
    $this->freezeSecond();
    $account = readinessAccount();
    app(ConfirmationSettings::class)->save(['enabled' => true]); // delay_minutes stays at its default of 3

    $cod = Order::factory()->create(['source' => OrderSource::Store, 'status' => OrderStatus::Confirmed, 'shopify_order_id' => '7001', 'shipping_phone' => '01012345678', 'placed_at' => now(), 'financial_status' => 'pending', 'payment_gateway' => 'Cash on Delivery (COD)']);
    $paid = Order::factory()->create(['source' => OrderSource::Store, 'status' => OrderStatus::Confirmed, 'shopify_order_id' => '7002', 'shipping_phone' => '01012345679', 'placed_at' => now(), 'financial_status' => 'paid']);

    $c = app(OrderConfirmations::class)->onOrderCreated($cod);
    expect($c->status)->toBe(OrderConfirmationStatus::Scheduled)
        ->and($c->channel_account_id)->toBe($account->id)
        ->and($c->send_at->equalTo(now()->addMinutes(3)))->toBeTrue();
    Queue::assertPushed(SendOrderConfirmation::class, fn ($job) => $job->confirmationId === $c->id && $job->delay->equalTo(now()->addMinutes(3)));

    // Spec 2026-10-16 §1: every order is confirmed; the cash line changes for a paid one («اتأكدنا من الدفع»).
    expect(TemplateParams::forOrder($cod)['payment'])->toBe(TemplateParams::COD)
        ->and(TemplateParams::forOrder($paid)['payment'])->toBe(TemplateParams::PAID)
        ->and(app(ConfirmationSender::class))->toBeInstanceOf(WhatsAppConfirmationSender::class);
});
