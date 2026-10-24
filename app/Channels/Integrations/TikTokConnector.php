<?php

namespace App\Channels\Integrations;

use App\Channels\Adapters\TikTok\TikTokApiException;
use App\Channels\Adapters\TikTok\TikTokMessagingApi;
use App\Channels\Adapters\TikTok\TikTokTokens;
use App\Channels\Data\SendResult;
use App\Enums\Platform;
use App\Models\ChannelAccount;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;

/**
 * TikTok Business Messaging (x6, 2026-10-24): the owner authorizes the developer app on his TikTok Business Account
 * (Accounts API authorization), the code becomes a one-day access token + one-year refresh token
 * (`/tt_user/oauth2/token/`), the account is saved as the live TikTok *messaging* ChannelAccount
 * (external_id = open_id = business_id, profile.kind = messaging) and the app-level DIRECT_MESSAGE webhook is
 * (re)pointed at our callback (`/business/webhook/update/`).
 *
 * The ad-comments poller keeps its own TikTok ChannelAccounts (external_id = advertiser id, profile.source =
 * ad_comments); only the messaging one carries a token and appears on Settings → Channels.
 */
final class TikTokConnector
{
    public const KIND = 'messaging';

    public function __construct(private readonly TikTokMessagingApi $api) {}

    public static function configured(): bool
    {
        return filled(config('services.tiktok.app_id')) && filled(config('services.tiktok.app_secret'));
    }

    /** Where TikTok redirects after the owner authorizes: must be registered verbatim in the app (https, trailing slash). */
    public static function redirectUri(): string
    {
        return rtrim((string) config('app.url'), '/').'/settings/channels/tiktok/callback/';
    }

    /** Where TikTok posts DIRECT_MESSAGE webhooks. */
    public static function callbackUrl(): string
    {
        return rtrim((string) config('app.url'), '/').'/webhooks/tiktok';
    }

    /**
     * The authorization URL the owner opens. TIKTOK_AUTHORIZE_URL (the portal's own "TikTok account holder
     * authorization URL") wins when set; otherwise it is built on https://www.tiktok.com/v2/auth/authorize with the
     * messaging scopes (ASSUMPTION: the parameter names follow TikTok's v2 authorize endpoint). `state` is always
     * appended; `disable_auto_auth=1` forces the consent screen so a re-connect yields a fresh code.
     */
    public static function authorizeUrl(string $state): string
    {
        $pasted = trim((string) config('services.tiktok.authorize_url'));

        if ($pasted !== '') {
            $url = preg_replace('/([?&])state=[^&]*/', '$1', $pasted) ?? $pasted;
            $url = rtrim($url, '?&');

            return $url.(str_contains($url, '?') ? '&' : '?').'state='.urlencode($state).'&disable_auto_auth=1';
        }

        return rtrim((string) config('services.tiktok.authorize_base'), '/').'?'.http_build_query([
            'client_key' => (string) config('services.tiktok.app_id'),
            'scope' => implode(',', (array) config('services.tiktok.scopes', [])),
            'response_type' => 'code',
            'redirect_uri' => self::redirectUri(),
            'state' => $state,
            'disable_auto_auth' => 1,
        ]);
    }

    /**
     * Exchange the code, read the account, save it and subscribe the webhook.
     *
     * @return array{0: ChannelAccount, 1: SendResult} the account and the webhook subscription outcome
     *
     * @throws IntegrationException
     */
    public function connectWithCode(string $code, string $method = 'oauth'): array
    {
        try {
            $data = $this->api->exchangeCode(trim($code), self::redirectUri());
        } catch (ConnectionException) {
            throw new IntegrationException('tiktok_unreachable');
        } catch (TikTokApiException $e) {
            throw new IntegrationException('tiktok_code_invalid', $e->getMessage());
        }

        $openId = (string) ($data['open_id'] ?? '');

        if ($openId === '' || blank($data['access_token'] ?? null)) {
            throw new IntegrationException('tiktok_code_invalid', 'TikTok returned no open_id / access_token');
        }

        $account = $this->liveAccount(includeDisconnected: true);

        // A different TikTok account than the one on file replaces it (one messaging account per CRM).
        if ($account === null || (string) $account->external_id !== $openId) {
            $account = ChannelAccount::query()->where('platform', Platform::TikTok)->where('external_id', $openId)->first() ?? new ChannelAccount(['platform' => Platform::TikTok]);
        }

        $previousProfile = (array) ($account->profile ?? []);

        $account->forceFill([
            'platform' => Platform::TikTok,
            'external_id' => $openId,
            'driver' => 'live',
            'status' => 'connected',
            'name' => $account->name ?: 'TikTok',
            'credentials' => TikTokTokens::credentialsFrom($data),
            'connected_at' => now(),
            'last_error' => null,
            'health' => null,
            'health_status' => null,
            'profile' => array_merge($previousProfile, ['kind' => self::KIND, 'method' => $method]),
        ])->save();

        $this->refreshProfile($account);

        return [$account, $this->subscribeWebhook($account)];
    }

    /** `/business/get/`: username, display name, picture, advanced access — on the card and for the health check. */
    public function refreshProfile(ChannelAccount $account): void
    {
        try {
            $info = $this->api->businessInfo($account);
        } catch (TikTokApiException|ConnectionException $e) {
            Log::warning('tiktok.business_info_failed', ['account_id' => $account->id, 'exception' => $e::class]);

            return;
        }

        $username = filled($info['username'] ?? null) ? (string) $info['username'] : null;
        $display = filled($info['display_name'] ?? null) ? (string) $info['display_name'] : null;

        $account->forceFill([
            'name' => $display ?? ($username !== null ? '@'.$username : $account->name),
            'profile' => array_merge((array) ($account->profile ?? []), array_filter([
                'username' => $username,
                'display_name' => $display,
                'picture' => filled($info['profile_image'] ?? null) ? (string) $info['profile_image'] : null,
                'has_advanced_access' => array_key_exists('has_advanced_access', $info) ? (bool) $info['has_advanced_access'] : null,
            ], fn ($v) => $v !== null)),
        ])->save();
    }

    /** App-level: one subscription serves every authorized account. Remembered on the account for the health check. */
    public function subscribeWebhook(ChannelAccount $account): SendResult
    {
        if (! self::configured()) {
            return SendResult::fail('app_not_configured');
        }

        try {
            $this->api->subscribeWebhook(self::callbackUrl());
        } catch (ConnectionException) {
            return SendResult::fail('tiktok_unreachable', retryable: true);
        } catch (TikTokApiException $e) {
            return SendResult::fail($e->getMessage());
        }

        $account->forceFill(['profile' => array_merge((array) ($account->profile ?? []), [
            'webhook_subscribed_at' => now()->toIso8601String(),
            'webhook_callback_url' => self::callbackUrl(),
        ])])->save();

        return SendResult::ok('subscribed');
    }

    /** Best effort: TikTok forgets the token; a dead token never blocks the disconnect. */
    public function revoke(ChannelAccount $account): void
    {
        $token = $account->credentials['access_token'] ?? null;

        if (blank($token) || ! self::configured()) {
            return;
        }

        try {
            $this->api->revoke((string) $token);
        } catch (\Throwable $e) {
            Log::warning('tiktok.revoke_failed', ['account_id' => $account->id, 'exception' => $e::class]);
        }
    }

    public function liveAccount(bool $includeDisconnected = false): ?ChannelAccount
    {
        return ChannelAccount::query()
            ->where('platform', Platform::TikTok)
            ->where('driver', 'live')
            ->where('profile->kind', self::KIND)
            ->when(! $includeDisconnected, fn ($q) => $q->where('status', '!=', 'disconnected'))
            ->orderByRaw("case when status = 'disconnected' then 1 else 0 end")
            ->orderByDesc('id')
            ->first();
    }

    public static function isMessagingAccount(ChannelAccount $account): bool
    {
        return $account->platform === Platform::TikTok && ($account->profile['kind'] ?? null) === self::KIND;
    }
}
