<?php

namespace App\Confirmations\Commands;

use App\Channels\Integrations\WhatsAppConnector;
use App\Enums\Platform;
use App\Models\ChannelAccount;

/**
 * The WhatsApp number a confirmation tool works on: --account=<id>, else the connected
 * live number (WhatsAppConnector::liveAccount). Prints why and returns null when none fits.
 */
trait ResolvesWhatsAppAccount
{
    private function whatsAppAccount(): ?ChannelAccount
    {
        $id = $this->option('account');

        $account = filled($id)
            ? ChannelAccount::query()->where('platform', Platform::WhatsApp)->find((int) $id)
            : app(WhatsAppConnector::class)->liveAccount();

        if ($account === null) {
            $this->error(filled($id)
                ? "WhatsApp account {$id} was not found."
                : 'No connected live WhatsApp account. Connect the number in Settings > Integrations first.');

            return null;
        }

        return $account;
    }
}
