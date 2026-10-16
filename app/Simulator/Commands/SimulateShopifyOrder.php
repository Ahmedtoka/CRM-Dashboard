<?php

namespace App\Simulator\Commands;

use App\Confirmations\ConfirmationSettings;
use App\Confirmations\Contracts\ConfirmationSender;
use App\Confirmations\OrderConfirmations;
use App\Enums\OrderConfirmationStatus;
use App\Models\OrderConfirmation;
use App\Simulator\Simulator;
use Illuminate\Console\Command;

/**
 * Local demo of WhatsApp order confirmation (spec 2026-10-16): a fake Shopify orders/create
 * goes through the real webhook pipeline, so the confirmation row appears on the
 * «تأكيدات الأوردرات» page. Local and testing only.
 *
 * By default the order carries Shopify's `test` flag, so the trigger skips it. Only
 * --fake-channel makes a real confirmation, and only while the number confirmations would
 * go out from is a fake (demo) one: a local copy with the live number connected must
 * never message the phone given here. With --now the template is "sent" at once.
 */
class SimulateShopifyOrder extends Command
{
    protected $signature = 'crm:simulate-shopify-order
        {--phone= : Customer phone on the order (required)}
        {--name= : Customer name (default: a random demo name)}
        {--fake-channel : Make a real confirmation on the demo (fake) WhatsApp channel}
        {--now : With --fake-channel, send the confirmation right away instead of after the delay}';

    protected $description = 'Local demo: fire a fake Shopify orders/create and watch the WhatsApp confirmation';

    public function handle(Simulator $simulator, ConfirmationSettings $settings, ConfirmationSender $sender): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('This demo runs on a local machine only (APP_ENV=local).');

            return self::FAILURE;
        }

        $phone = trim((string) $this->option('phone'));

        if ($phone === '') {
            $this->error('--phone is required (the phone written on the fake order).');

            return self::FAILURE;
        }

        $fakeChannel = (bool) $this->option('fake-channel');

        if ($fakeChannel && ($reason = $this->liveChannelRisk()) !== null) {
            $this->error($reason);

            return self::FAILURE;
        }

        $order = $simulator->shopifyOrderCreated($phone, $this->option('name'), test: ! $fakeChannel);

        $this->info("Fake Shopify order {$order->order_number} (id {$order->id}) for {$order->shipping_name}, {$order->shipping_phone}.");

        if (! $settings->enabled()) {
            $this->warn('WhatsApp order confirmation is turned off, so no confirmation was made. Turn it on in settings and run this again.');

            return self::SUCCESS;
        }

        if (! $fakeChannel) {
            $this->line('The order is marked as a Shopify test, so no confirmation is sent. Pass --fake-channel to see one on the demo WhatsApp channel.');
        }

        $confirmation = OrderConfirmation::query()->where('order_id', $order->id)->first();

        if ($confirmation === null) {
            $this->warn('No confirmation was made for this order (is the order trigger installed?).');

            return self::SUCCESS;
        }

        if ($fakeChannel && $confirmation->status === OrderConfirmationStatus::Scheduled && $this->option('now')) {
            $sender->send($confirmation, 'confirm');
            $confirmation->refresh();
        }

        $this->line('Confirmation: '.$confirmation->status->value
            .($confirmation->skip_reason ? " ({$confirmation->skip_reason})" : '')
            .($confirmation->last_error ? " - {$confirmation->last_error}" : ''));

        if ($confirmation->status === OrderConfirmationStatus::Scheduled) {
            $this->line('It goes out at '.$confirmation->send_at?->format('H:i').' if a queue worker runs (php artisan queue:work), or rerun with --now.');
        }

        return self::SUCCESS;
    }

    /** Why --fake-channel must not run here, or null when confirmations would go out on a fake number. */
    private function liveChannelRisk(): ?string
    {
        if (! class_exists(OrderConfirmations::class)) {
            return 'The order confirmation trigger is not installed.';
        }

        $account = app(OrderConfirmations::class)->whatsAppAccount();

        if ($account === null) {
            return 'No connected WhatsApp channel. Seed the demo data (it has the demo WhatsApp number) first.';
        }

        if ($account->isLive()) {
            return "A live WhatsApp number is connected here ({$account->name}): the demo would message a real phone. Use a database with only the demo channel.";
        }

        return null;
    }
}
