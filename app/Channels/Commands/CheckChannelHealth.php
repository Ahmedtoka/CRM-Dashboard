<?php

namespace App\Channels\Commands;

use App\Channels\Integrations\ConnectionHealthCheck;
use App\Models\ChannelAccount;
use Illuminate\Console\Command;

/**
 * Daily health check of every live, connected Meta channel (Settings → Integrations
 * shows the result; a new problem notifies admins). Scheduled in ChannelsServiceProvider.
 */
class CheckChannelHealth extends Command
{
    protected $signature = 'channels:health {--account= : Only this channel account id}';

    protected $description = 'Check token, webhook subscription and recent traffic of every live Meta channel';

    public function handle(ConnectionHealthCheck $check): int
    {
        $accounts = ChannelAccount::query()
            ->where('driver', 'live')
            ->where('status', '!=', 'disconnected')
            ->whereIn('platform', ['facebook', 'instagram', 'whatsapp'])
            ->when($this->option('account'), fn ($q, $id) => $q->whereKey($id))
            ->orderBy('id')
            ->get();

        foreach ($accounts as $account) {
            $result = $check->run($account);
            $codes = collect($result['checks'])->where('status', '!=', 'ok')->pluck('code')->implode(', ');

            $this->line(sprintf('#%d %s %s: %s%s', $account->id, $account->platform->value, $account->name, $result['status'], $codes !== '' ? " ({$codes})" : ''));
        }

        if ($accounts->isEmpty()) {
            $this->info('No live channels to check.');
        }

        return self::SUCCESS;
    }
}
