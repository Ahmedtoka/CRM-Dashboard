<?php

namespace App\Confirmations\Commands;

use App\Confirmations\ConfirmationSettings;
use App\Confirmations\Contracts\ConfirmationSender;
use App\Confirmations\EgyptPhone;
use App\Confirmations\OrderConfirmations;
use App\Models\Order;
use Illuminate\Console\Command;
use Throwable;

/**
 * Sends the order-confirmation template of one order to a phone the owner chooses, to see it
 * on a real phone. It goes through the sender's rowless test path (sendTest): no
 * order_confirmations row is made or changed, so the order's real confirmation is never
 * blocked, and a tap on the test message's buttons never tags the order in Shopify.
 */
class ConfirmationTestCommand extends Command
{
    protected $signature = 'whatsapp:confirmation-test
        {phone : The phone that receives the test (01xxxxxxxxx or +20...)}
        {--order= : Order id or order number (required)}
        {--force : Send even while the feature is turned off; with --no-interaction, skip the question}';

    protected $description = 'Send one WhatsApp order-confirmation test message to a phone';

    public function handle(ConfirmationSettings $settings, ConfirmationSender $sender, OrderConfirmations $confirmations): int
    {
        $force = (bool) $this->option('force');
        $wanted = trim((string) $this->option('order'));

        if ($wanted === '') {
            $this->error('--order is required: pick the order whose message you want to see (its id or its number).');

            return self::FAILURE;
        }

        if (! $settings->enabled() && ! $force) {
            $this->error('Order confirmation is turned off in settings. Pass --force to send a test anyway.');

            return self::FAILURE;
        }

        $phone = EgyptPhone::toE164((string) $this->argument('phone'));

        if ($phone === null) {
            $this->error("'{$this->argument('phone')}' is not an Egyptian mobile number (01xxxxxxxxx or +201xxxxxxxxx).");

            return self::FAILURE;
        }

        $order = $this->order($wanted);

        if ($order === null) {
            $this->error("No order found for '{$wanted}'.");

            return self::FAILURE;
        }

        if (! method_exists($sender, 'sendTest')) {
            $this->error('This build has no test send in the WhatsApp sender yet.');

            return self::FAILURE;
        }

        $account = $confirmations->whatsAppAccount();

        if ($account === null) {
            $this->error('No connected WhatsApp number to send from. Connect it in Settings > Integrations first.');

            return self::FAILURE;
        }

        $this->table(['', ''], [
            ['WhatsApp number', "{$account->name} (account {$account->id}, {$account->driver})"],
            ['Order', "{$order->order_number} (id {$order->id}), total {$order->total} {$order->currency}"],
            ['Customer on the order', (string) ($order->shipping_name ?: $order->customer?->name)],
            ['Send to', $phone],
            ['Template', $settings->template('confirm').' ('.$settings->templateLanguage().')'],
        ]);
        $this->line('Test only: nothing is recorded on the order, and the buttons in this message do nothing.');

        // Without a terminal only --force sends; otherwise the owner says yes first.
        $approved = $this->input->isInteractive()
            ? $this->confirm('Send this test message now?')
            : $force;

        if (! $approved) {
            $this->line('Nothing sent.');

            return self::SUCCESS;
        }

        try {
            $error = $sender->sendTest($order, $phone);
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }

        if ($error !== null) {
            $this->error('Not sent: '.$error);

            return self::FAILURE;
        }

        $this->info('Sent. Check WhatsApp on the phone above.');

        return self::SUCCESS;
    }

    private function order(string $wanted): ?Order
    {
        $number = ltrim($wanted, '#');

        return Order::query()->whereIn('order_number', ['#'.$number, $number])->latest('id')->first()
            ?? (ctype_digit($wanted) ? Order::find((int) $wanted) : null);
    }
}
