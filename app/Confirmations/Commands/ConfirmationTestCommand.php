<?php

namespace App\Confirmations\Commands;

use App\Confirmations\ConfirmationSettings;
use App\Confirmations\Contracts\ConfirmationSender;
use App\Enums\OrderConfirmationStatus;
use App\Models\Order;
use App\Models\OrderConfirmation;
use App\Shopify\Customers\PhoneNormalizer;
use Illuminate\Console\Command;

/**
 * Sends one order-confirmation template for a real order to a phone the owner chooses,
 * through the same ConfirmationSender the feature uses, to see it on a real phone.
 *
 * By default the buttons are inert: after the send the row is parked as skipped
 * (`test_send`), so a tap never tags the real order in Shopify or opens a case.
 * --live-buttons keeps the row live (use it with a test order).
 */
class ConfirmationTestCommand extends Command
{
    use ResolvesWhatsAppAccount;

    public const SKIP_REASON = 'test_send';

    protected $signature = 'whatsapp:confirmation-test
        {phone : The phone that receives the test (01xxxxxxxxx or +20...)}
        {--order= : Order id or order number (default: the latest order)}
        {--account= : ChannelAccount id of the WhatsApp number (default: the connected live one)}
        {--live-buttons : Keep the buttons working: a tap tags the order in Shopify and opens a case}
        {--force : Send even while the feature is turned off; with --no-interaction, skip the question}';

    protected $description = 'Send one WhatsApp order-confirmation test message to a phone';

    public function handle(ConfirmationSettings $settings, ConfirmationSender $sender): int
    {
        $force = (bool) $this->option('force');

        if (! $settings->enabled() && ! $force) {
            $this->error('Order confirmation is turned off in settings. Pass --force to send a test anyway.');

            return self::FAILURE;
        }

        $phone = PhoneNormalizer::toE164((string) $this->argument('phone'));

        // The feature messages Egyptian mobiles only (the sender's own rule), so the test does too.
        if (! PhoneNormalizer::isEgyptianMobile($phone)) {
            $this->error("'{$this->argument('phone')}' is not an Egyptian mobile number (01xxxxxxxxx or +201xxxxxxxxx).");

            return self::FAILURE;
        }

        $order = $this->order();

        if ($order === null) {
            $this->error('No order found'.(filled($this->option('order')) ? " for '{$this->option('order')}'." : '.'));

            return self::FAILURE;
        }

        if (OrderConfirmation::query()->where('order_id', $order->id)->exists()) {
            $this->error("Order {$order->order_number} already has a confirmation. Pick another one with --order.");

            return self::FAILURE;
        }

        $account = $this->whatsAppAccount();

        if ($account === null) {
            return self::FAILURE;
        }

        $live = (bool) $this->option('live-buttons');

        $this->table(['', ''], [
            ['WhatsApp number', "{$account->name} (account {$account->id})"],
            ['Order', "{$order->order_number} (id {$order->id}), total {$order->total} {$order->currency}"],
            ['Customer on the order', (string) ($order->shipping_name ?: $order->customer?->name)],
            ['Send to', $phone],
            ['Template', $settings->template('confirm').' ('.$settings->templateLanguage().')'],
            ['Buttons', $live ? 'LIVE' : 'inert (test only)'],
        ]);

        if ($live) {
            $this->warn('Live buttons: a tap will tag this order in Shopify (wa-confirmed / wa-cancel-request / wa-edit-request) and may open a case.');
        }

        // Without a terminal only --force sends; otherwise the owner says yes first.
        $approved = $this->input->isInteractive()
            ? $this->confirm('Send this test message now?')
            : $force;

        if (! $approved) {
            $this->line('Nothing sent.');

            return self::SUCCESS;
        }

        $confirmation = OrderConfirmation::create([
            'order_id' => $order->id,
            'channel_account_id' => $account->id,
            'customer_id' => $order->customer_id,
            'phone_e164' => $phone,
            'status' => OrderConfirmationStatus::Scheduled,
            'send_at' => now(),
        ]);

        // Kind 'test': sent even while the feature is off, and the phone never joins the order's customer.
        $sender->send($confirmation, 'test');
        $confirmation->refresh();

        if ($confirmation->status !== OrderConfirmationStatus::Sent) {
            $this->error('Not sent: '.($confirmation->last_error ?: $confirmation->status->value));
            // Nothing reached the phone: drop the row so the order and the counters stay clean.
            $confirmation->delete();

            return self::FAILURE;
        }

        if (! $live) {
            $confirmation->forceFill(['status' => OrderConfirmationStatus::Skipped, 'skip_reason' => self::SKIP_REASON])->save();
        }

        $this->info('Sent. Check WhatsApp on the phone above.');

        return self::SUCCESS;
    }

    private function order(): ?Order
    {
        $wanted = trim((string) $this->option('order'));

        if ($wanted === '') {
            return Order::query()->latest('id')->first();
        }

        $number = '#'.ltrim($wanted, '#');

        return Order::query()->where('order_number', $number)->orWhere('order_number', ltrim($wanted, '#'))->latest('id')->first()
            ?? (ctype_digit($wanted) ? Order::find((int) $wanted) : null);
    }
}
