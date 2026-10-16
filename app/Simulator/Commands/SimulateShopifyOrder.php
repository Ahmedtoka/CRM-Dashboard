<?php

namespace App\Simulator\Commands;

use App\Confirmations\ConfirmationSettings;
use App\Confirmations\Contracts\ConfirmationSender;
use App\Enums\OrderConfirmationStatus;
use App\Models\OrderConfirmation;
use App\Simulator\Simulator;
use Illuminate\Console\Command;

/**
 * Local demo of WhatsApp order confirmation (spec 2026-10-16): a fake Shopify orders/create
 * goes through the real webhook pipeline, so the confirmation row appears on the
 * «تأكيدات الأوردرات» page and, with --now, the template lands in the demo WhatsApp thread
 * (fake channel). Local and testing only.
 */
class SimulateShopifyOrder extends Command
{
    protected $signature = 'crm:simulate-shopify-order
        {--phone=01001234567 : Customer phone on the order}
        {--name= : Customer name (default: a random demo name)}
        {--now : Send the confirmation right away instead of after the delay}';

    protected $description = 'Local demo: fire a fake Shopify orders/create and watch the WhatsApp confirmation';

    public function handle(Simulator $simulator, ConfirmationSettings $settings, ConfirmationSender $sender): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('This demo runs on a local machine only (APP_ENV=local).');

            return self::FAILURE;
        }

        $order = $simulator->shopifyOrderCreated((string) $this->option('phone'), $this->option('name'));

        $this->info("Fake Shopify order {$order->order_number} (id {$order->id}) for {$order->shipping_name}, {$order->shipping_phone}.");

        if (! $settings->enabled()) {
            $this->warn('WhatsApp order confirmation is turned off, so no confirmation was made. Turn it on in settings and run this again.');

            return self::SUCCESS;
        }

        $confirmation = OrderConfirmation::query()->where('order_id', $order->id)->first();

        if ($confirmation === null) {
            $this->warn('No confirmation was made for this order (is the order trigger installed?).');

            return self::SUCCESS;
        }

        if ($confirmation->status === OrderConfirmationStatus::Scheduled && $this->option('now')) {
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
}
