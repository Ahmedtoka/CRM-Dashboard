<?php

use App\Channels\Adapters\FakeChannelAdapter;
use App\Channels\Jobs\ProcessWebhookEvent;
use App\Confirmations\ConfirmationPayload;
use App\Confirmations\ConfirmationSettings;
use App\Confirmations\Contracts\ShopifyOrderTagger;
use App\Confirmations\Jobs\SendOrderConfirmation;
use App\Confirmations\OrderConfirmations;
use App\Confirmations\Shopify\GraphqlOrderTagger;
use App\Confirmations\WhatsApp\ConfirmationReplyHandler;
use App\Confirmations\WhatsApp\TemplateParams;
use App\Confirmations\WhatsApp\WhatsAppConfirmationSender;
use App\Enums\OrderConfirmationStatus;
use App\Enums\Platform;
use App\Enums\UserRole;
use App\Models\ChannelAccount;
use App\Models\Order;
use App\Models\OrderConfirmation;
use App\Models\SupportCase;
use App\Models\User;
use App\Models\UserNotification;
use App\Models\WebhookEvent;
use App\Shopify\Connection\ShopifyIntegration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/*
 * WhatsApp order confirmation, end to end across the slices (spec 2026-10-16), with fakes only:
 * Shopify orders/create webhook -> row scheduled -> SendOrderConfirmation -> template on the fake
 * WhatsApp channel -> customer taps a button -> status, Shopify tag, case, bell. And the silent
 * customer: reminder after 6h, call list after 24h, through the sweep command.
 *
 * Each test skips until the slices it needs are merged (W1 trigger, W2 sender + reply handler,
 * W3 tagger + follow-up + sweep): the coordinator's merge turns them on.
 */

/** @return list<string> the pieces of the pipeline that are not in this branch yet */
function confirmationPipelineMissing(): array
{
    $missing = array_values(array_filter([
        OrderConfirmations::class,
        SendOrderConfirmation::class,
        ConfirmationReplyHandler::class,
    ], fn (string $class) => ! class_exists($class)));

    // The contract skeleton binds placeholder classes until W2 / W3 replace them.
    foreach ([WhatsAppConfirmationSender::class, GraphqlOrderTagger::class] as $class) {
        if (str_contains((string) file_get_contents((new ReflectionClass($class))->getFileName()), 'Placeholder:')) {
            $missing[] = $class;
        }
    }

    if (! array_key_exists('crm:order-confirmations:sweep', Artisan::all())) {
        $missing[] = 'crm:order-confirmations:sweep';
    }

    return $missing;
}

/** Records the tags instead of writing to Shopify. */
function recordingOrderTagger(): object
{
    $tagger = new class implements ShopifyOrderTagger
    {
        /** @var list<array{order_id: int, tags: list<string>}> */
        public array $calls = [];

        public function add(Order $order, array $tags): bool
        {
            $this->calls[] = ['order_id' => $order->id, 'tags' => array_values($tags)];

            return true;
        }

        /** @return list<string> */
        public function tagsFor(Order $order): array
        {
            return collect($this->calls)->where('order_id', $order->id)->pluck('tags')->flatten()->unique()->values()->all();
        }
    };

    app()->instance(ShopifyOrderTagger::class, $tagger);

    return $tagger;
}

/** A signed Shopify orders/create for a brand-new order (placed now) to $phone. */
function postShopifyOrderCreated($test, int $shopifyId, string $phone): Order
{
    $payload = json_decode(file_get_contents(base_path('tests/Fixtures/shopify/webhook_order_store.json')), true);
    $payload = array_replace($payload, [
        'id' => $shopifyId,
        'admin_graphql_api_id' => 'gid://shopify/Order/'.$shopifyId,
        'name' => '#'.$shopifyId,
        'order_number' => $shopifyId,
        'created_at' => now()->toIso8601String(),
        'processed_at' => now()->toIso8601String(),
        'updated_at' => now()->toIso8601String(),
    ]);
    $payload['shipping_address']['phone'] = $phone;
    $payload['billing_address']['phone'] = $phone;
    $payload['customer']['id'] = $shopifyId + 1;
    $payload['customer']['phone'] = $phone;
    $payload['customer']['default_address']['phone'] = $phone;

    $body = json_encode($payload);

    $test->call('POST', '/webhooks/shopify/orders-create', [], [], [], [
        'HTTP_X-Shopify-Hmac-Sha256' => base64_encode(hash_hmac('sha256', $body, 'sec', true)),
        'HTTP_X-Shopify-Webhook-Id' => (string) Str::uuid(),
        'CONTENT_TYPE' => 'application/json',
    ], $body)->assertOk();

    return Order::where('shopify_order_id', (string) $shopifyId)->sole();
}

/** Runs the SendOrderConfirmation job W1 queued for $confirmation (the queue is faked for it). */
function runQueuedConfirmationJob(OrderConfirmation $confirmation): void
{
    $job = Queue::pushed(SendOrderConfirmation::class)
        ->first(fn ($job) => in_array($confirmation->id, array_map(
            fn ($v) => $v instanceof OrderConfirmation ? $v->id : $v,
            get_object_vars($job),
        ), true));

    expect($job)->not->toBeNull();

    app()->call([$job, 'handle']);
}

/** A WhatsApp quick-reply tap from $waId on the fake channel, through the real webhook pipeline. */
function tapConfirmationButton(ChannelAccount $account, string $waId, string $payload, string $text): void
{
    $event = WebhookEvent::create([
        'provider' => Platform::WhatsApp->value,
        'event_type' => 'message',
        'dedupe_key' => (string) Str::uuid(),
        'payload' => ['fake' => true, 'events' => [[
            'type' => 'message',
            'channel_id' => $account->external_id,
            'id' => 'wamid.'.Str::uuid(),
            'customer_id' => $waId,
            'name' => 'نور علي',
            'text' => $text,
            'payload' => $payload,
            'at' => now()->toIso8601String(),
        ]]],
        'signature_valid' => true,
        'status' => 'received',
    ]);

    ProcessWebhookEvent::dispatchSync($event->id);
}

/** @return list<array<string, mixed>> templates sent on the fake WhatsApp channel */
function sentConfirmationTemplates(): array
{
    return collect(FakeChannelAdapter::sent())
        ->filter(fn ($s) => ($s['platform'] ?? null) === 'whatsapp' && isset($s['options']['template']))
        ->map(fn ($s) => $s['options']['template'])
        ->values()->all();
}

beforeEach(function () {
    if (confirmationPipelineMissing() !== []) {
        return;
    }

    Http::preventStrayRequests();
    Queue::fake([SendOrderConfirmation::class]);
    FakeChannelAdapter::reset();

    config(['crm.drivers.commerce' => 'live', 'crm.shopify.driver' => 'live']);
    ShopifyIntegration::create(['shop_domain' => 'demo.myshopify.com', 'access_token' => 't', 'api_secret' => 'sec', 'status' => 'connected']);

    $this->whatsapp = ChannelAccount::factory()->create([
        'platform' => Platform::WhatsApp, 'driver' => 'fake', 'status' => 'connected', 'external_id' => 'wa-e2e',
    ]);
    $this->admin = User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);
    $this->tagger = recordingOrderTagger();

    app(ConfirmationSettings::class)->save(['enabled' => true, 'delay_minutes' => 3, 'reminder_after_hours' => 6, 'no_response_after_hours' => 24]);
});

it('confirms a new Shopify order on WhatsApp and turns a cancel tap into a tag, a case and a bell', function () {
    $order = postShopifyOrderCreated($this, 7000001, '01001234567');

    $row = OrderConfirmation::where('order_id', $order->id)->sole();
    expect($row->status)->toBe(OrderConfirmationStatus::Scheduled)
        ->and($row->phone_e164)->toBe('+201001234567')
        ->and($row->channel_account_id)->toBe($this->whatsapp->id)
        ->and($row->send_at->diffInMinutes(now()->addMinutes(3), true))->toBeLessThan(1.0)
        ->and(sentConfirmationTemplates())->toBe([]);

    $this->travel(3)->minutes();
    runQueuedConfirmationJob($row);

    $row->refresh();
    $templates = sentConfirmationTemplates();
    expect($row->status)->toBe(OrderConfirmationStatus::Sent)
        ->and($templates)->toHaveCount(1)
        ->and($templates[0]['name'])->toBe('order_confirm_ar')
        ->and($templates[0]['params'])->toHaveCount(count(TemplateParams::BODY))
        ->and($templates[0]['buttons'])->toBe([
            ConfirmationPayload::make($row->id, 'confirm'),
            ConfirmationPayload::make($row->id, 'edit'),
            ConfirmationPayload::make($row->id, 'cancel'),
        ]);

    $this->travel(20)->minutes();
    tapConfirmationButton($this->whatsapp, '201001234567', ConfirmationPayload::make($row->id, 'cancel'), 'إلغاء');

    $row->refresh();
    expect($row->status)->toBe(OrderConfirmationStatus::CancelRequested)
        ->and($row->answer)->toBe('cancel')
        ->and($row->answered_at)->not->toBeNull()
        ->and($this->tagger->tagsFor($order))->toContain('wa-cancel-request');

    $case = SupportCase::where('type', 'cancel_edit')->where('data->order_id', $order->id)->sole();
    expect($case->data['request'])->toBe('cancel')
        ->and($row->case_id)->toBe($case->id);

    // The bell: the case's own `case.created` (W3 records it through CaseRecorder), or
    // `order_confirmation.*` when the answer has no conversation to hang a case on.
    expect(UserNotification::where('user_id', $this->admin->id)
        ->where(fn ($q) => $q->where('type', 'case.created')->orWhere('type', 'like', 'order_confirmation.%'))
        ->exists())->toBeTrue();

    // The order itself is never cancelled by the CRM.
    expect($order->fresh()->status?->value)->not->toBe('cancelled');
})->skip(fn () => confirmationPipelineMissing() !== [], 'waiting for slices W1-W3');

it('reminds a silent customer once after 6 hours and puts the order on the call list after 24', function () {
    $order = postShopifyOrderCreated($this, 7000002, '01112223334');
    $row = OrderConfirmation::where('order_id', $order->id)->sole();

    $this->travel(3)->minutes();
    runQueuedConfirmationJob($row);
    expect($row->refresh()->status)->toBe(OrderConfirmationStatus::Sent);

    $this->travel(5)->hours();
    $this->artisan('crm:order-confirmations:sweep')->assertSuccessful();
    expect($row->refresh()->reminded_at)->toBeNull()->and(sentConfirmationTemplates())->toHaveCount(1);

    $this->travel(61)->minutes();
    $this->artisan('crm:order-confirmations:sweep')->assertSuccessful();
    $this->artisan('crm:order-confirmations:sweep')->assertSuccessful();

    $row->refresh();
    $templates = sentConfirmationTemplates();
    expect($row->reminded_at)->not->toBeNull()
        ->and($row->status)->toBe(OrderConfirmationStatus::Sent)
        ->and($templates)->toHaveCount(2)
        ->and($templates[1]['name'])->toBe('order_reminder_ar')
        ->and($templates[1]['buttons'][2])->toBe(ConfirmationPayload::make($row->id, 'cancel'));

    $this->travel(18)->hours();
    $this->artisan('crm:order-confirmations:sweep')->assertSuccessful();

    $row->refresh();
    expect($row->status)->toBe(OrderConfirmationStatus::NoResponse)
        ->and(OrderConfirmation::needsCall()->pluck('id')->all())->toBe([$row->id])
        ->and($this->tagger->tagsFor($order))->toContain('wa-no-response')
        ->and(sentConfirmationTemplates())->toHaveCount(2);

    // A late tap still counts while the order waits on the call list.
    tapConfirmationButton($this->whatsapp, '201112223334', ConfirmationPayload::make($row->id, 'confirm'), 'تأكيد الأوردر');

    expect($row->refresh()->status)->toBe(OrderConfirmationStatus::Confirmed)
        ->and($this->tagger->tagsFor($order))->toContain('wa-confirmed');
})->skip(fn () => confirmationPipelineMissing() !== [], 'waiting for slices W1-W3');

it('never messages an imported old order', function () {
    $payload = json_decode(file_get_contents(base_path('tests/Fixtures/shopify/webhook_order_store.json')), true);
    $payload = array_replace($payload, ['id' => 7000003, 'name' => '#7000003', 'created_at' => now()->subHours(5)->toIso8601String(), 'updated_at' => now()->toIso8601String()]);
    $body = json_encode($payload);

    $this->call('POST', '/webhooks/shopify/orders-create', [], [], [], [
        'HTTP_X-Shopify-Hmac-Sha256' => base64_encode(hash_hmac('sha256', $body, 'sec', true)),
        'HTTP_X-Shopify-Webhook-Id' => (string) Str::uuid(),
        'CONTENT_TYPE' => 'application/json',
    ], $body)->assertOk();

    $row = OrderConfirmation::sole();
    expect($row->status)->toBe(OrderConfirmationStatus::Skipped)
        ->and($row->skip_reason)->toBe('too_old');
    Queue::assertNotPushed(SendOrderConfirmation::class);
})->skip(fn () => confirmationPipelineMissing() !== [], 'waiting for slices W1-W3');
