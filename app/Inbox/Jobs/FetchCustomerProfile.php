<?php

namespace App\Inbox\Jobs;

use App\Channels\Adapters\MetaGraphClient;
use App\Enums\Platform;
use App\Models\ChannelAccount;
use App\Models\CustomerIdentity;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Messenger and Instagram webhooks carry only the sender's page-scoped id, so a
 * new customer is first saved under that number. This asks the Graph API for the
 * real name and picture and replaces the placeholder — never a name a moderator
 * (or an earlier lookup) already set. Best effort: a failed lookup just leaves
 * the id in place.
 */
class FetchCustomerProfile implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    /** @var array<int, int> */
    public array $backoff = [30];

    public int $timeout = 30;

    public function __construct(public readonly int $identityId, public readonly int $accountId)
    {
        $this->onQueue('webhooks');
    }

    /** Only Meta platforms whose webhooks omit the sender name, and only while the name is still the id. */
    public static function needed(CustomerIdentity $identity): bool
    {
        return in_array($identity->platform, [Platform::Facebook, Platform::Instagram], true)
            && ($identity->display_name === null || $identity->display_name === $identity->external_id);
    }

    public function handle(MetaGraphClient $graph): void
    {
        $identity = CustomerIdentity::with('customer')->find($this->identityId);
        $account = ChannelAccount::find($this->accountId);

        if ($identity === null || $account === null || $account->driver !== 'live' || ! self::needed($identity)) {
            return;
        }

        $fields = $identity->platform === Platform::Instagram ? 'name,username,profile_pic' : 'first_name,last_name,profile_pic';
        $response = $graph->get($account, $identity->external_id, ['fields' => $fields]);

        if ($response->failed()) {
            return;
        }

        $name = $identity->platform === Platform::Instagram
            ? trim((string) ($response->json('name') ?: $response->json('username')))
            : trim($response->json('first_name').' '.$response->json('last_name'));
        $avatar = $response->json('profile_pic');

        if ($name === '') {
            return;
        }

        $identity->forceFill(array_filter([
            'display_name' => $name,
            'username' => $identity->platform === Platform::Instagram ? $response->json('username') : null,
            'avatar_url' => is_string($avatar) ? $avatar : null,
        ]))->save();

        $customer = $identity->customer;

        if ($customer !== null) {
            if ($customer->name === $identity->external_id || blank($customer->name)) {
                $customer->name = $name;
            }
            if (is_string($avatar) && blank($customer->avatar_url)) {
                $customer->avatar_url = $avatar;
            }
            $customer->save();
        }
    }
}
