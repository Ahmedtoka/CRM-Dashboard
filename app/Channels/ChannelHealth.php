<?php

namespace App\Channels;

use App\Channels\Data\SendResult;
use App\Enums\Platform;
use App\Enums\UserRole;
use App\Events\UserNotified;
use App\Models\ChannelAccount;
use App\Models\User;
use App\Support\SafeBroadcast;
use Illuminate\Support\Str;

/**
 * Channel connection health (spec §9): credential failures flip the account to
 * `error` (shown to admins as the channel alert strip) and notify admins once;
 * every accepted webhook refreshes `last_webhook_at`.
 */
class ChannelHealth
{
    /** Meta OAuthException / token failures that may arrive without the structured flag. */
    private const AUTH_ERROR_PATTERN = '/\(#190\)|OAuthException|access token|session has expired|HTTP 40[13]\b/i';

    public static function isAuthError(SendResult $result): bool
    {
        if ($result->success) {
            return false;
        }

        return $result->authError || preg_match(self::AUTH_ERROR_PATTERN, (string) $result->error) === 1;
    }

    /**
     * @return bool true when the failure was a credential problem and the account was flagged
     */
    public function recordSendFailure(?ChannelAccount $account, SendResult $result): bool
    {
        if ($account === null || ! self::isAuthError($result)) {
            return false;
        }

        $wasError = $account->status === 'error';

        $account->forceFill([
            'status' => 'error',
            'last_error' => Str::limit((string) ($result->error ?? 'Authentication failed'), 250),
        ])->save();

        if (! $wasError) {
            $this->notifyAdmins($account);
        }

        return true;
    }

    public function recordWebhook(Platform $platform): void
    {
        ChannelAccount::query()->where('platform', $platform->value)->update(['last_webhook_at' => now()]);
    }

    private function notifyAdmins(ChannelAccount $account): void
    {
        User::query()
            ->where('is_active', true)
            ->where('role', UserRole::Admin->value)
            ->get()
            ->each(fn (User $u) => SafeBroadcast::send(new UserNotified($u->id, 'channel.error', [
                'channel_account_id' => $account->id,
                'platform' => $account->platform?->value,
                'name' => $account->name,
                'error' => $account->last_error,
            ])));
    }
}
