<?php

namespace App\Channels\Adapters\TikTok;

use App\Models\ChannelAccount;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * The TikTok account token on a messaging ChannelAccount (x6, 2026-10-24). TikTok's access token lives one
 * day and the refresh token one year (Accounts API Authentication), so every API call asks here first and
 * the token is renewed under a lock when fewer than REFRESH_AHEAD_SECONDS are left. Credentials stay in
 * `ChannelAccount.credentials` (encrypted): access_token, refresh_token, access_token_expires_at,
 * refresh_token_expires_at, scope.
 */
final class TikTokTokens
{
    /** Renew this long before the access token would expire. */
    public const REFRESH_AHEAD_SECONDS = 1800;

    /**
     * @param  array<string, mixed>  $data  a /tt_user/oauth2/token/ or refresh_token/ `data` object
     * @return array<string, mixed> the credentials to store
     */
    public static function credentialsFrom(array $data, array $previous = []): array
    {
        $now = CarbonImmutable::now();

        return array_filter([
            'access_token' => (string) ($data['access_token'] ?? ''),
            'refresh_token' => (string) ($data['refresh_token'] ?? ($previous['refresh_token'] ?? '')),
            'access_token_expires_at' => isset($data['expires_in']) ? $now->addSeconds((int) $data['expires_in'])->toIso8601String() : null,
            'refresh_token_expires_at' => isset($data['refresh_token_expires_in'])
                ? $now->addSeconds((int) $data['refresh_token_expires_in'])->toIso8601String()
                : ($previous['refresh_token_expires_at'] ?? null),
            'scope' => isset($data['scope']) ? (string) $data['scope'] : ($previous['scope'] ?? null),
        ], fn ($v) => $v !== null && $v !== '');
    }

    public static function accessExpiresAt(ChannelAccount $account): ?CarbonImmutable
    {
        $at = $account->credentials['access_token_expires_at'] ?? null;

        return filled($at) ? CarbonImmutable::parse($at) : null;
    }

    public static function refreshExpiresAt(ChannelAccount $account): ?CarbonImmutable
    {
        $at = $account->credentials['refresh_token_expires_at'] ?? null;

        return filled($at) ? CarbonImmutable::parse($at) : null;
    }

    public static function needsRefresh(ChannelAccount $account): bool
    {
        $expires = self::accessExpiresAt($account);

        return $expires !== null && $expires->subSeconds(self::REFRESH_AHEAD_SECONDS)->isPast();
    }

    /** @return list<string> */
    public static function scopes(ChannelAccount $account): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) ($account->credentials['scope'] ?? '')))));
    }

    /**
     * A usable access token: the stored one, renewed first when it is about to expire.
     *
     * @param  callable(string $refreshToken): array<string, mixed>  $refresh  the API's refresh call
     *
     * @throws TikTokApiException when there is no token or TikTok refused the renewal
     */
    public static function valid(ChannelAccount $account, callable $refresh): string
    {
        $token = (string) ($account->credentials['access_token'] ?? '');

        if ($token === '') {
            throw new TikTokApiException(TikTokApiException::INVALID_TOKEN, 'No TikTok token is stored for this account.');
        }

        if (! self::needsRefresh($account)) {
            return $token;
        }

        $lock = Cache::lock('tiktok-token-refresh:'.$account->id, 20);

        try {
            $lock->block(10);
            $account->refresh();

            if (! self::needsRefresh($account)) {
                return (string) $account->credentials['access_token'];
            }

            $refreshToken = (string) ($account->credentials['refresh_token'] ?? '');

            if ($refreshToken === '') {
                throw new TikTokApiException(TikTokApiException::INVALID_TOKEN, 'The TikTok access token expired and no refresh token is stored: connect the account again.');
            }

            $data = $refresh($refreshToken);
            $account->forceFill(['credentials' => self::credentialsFrom($data, $account->credentials ?? [])])->save();

            return (string) $account->credentials['access_token'];
        } finally {
            $lock->release();
        }
    }
}
