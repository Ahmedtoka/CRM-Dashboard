<?php

namespace App\Channels\Integrations;

use App\Channels\Adapters\TikTok\TikTokApiException;
use App\Channels\Adapters\TikTok\TikTokMessagingApi;
use App\Channels\Adapters\TikTok\TikTokTokens;
use App\Models\ChannelAccount;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Str;
use Throwable;

/**
 * The TikTok messaging account's part of ConnectionHealthCheck (x6, 2026-10-24):
 *
 *  - token     a stored token, renewable (the one-day access token is refreshed here when due), the messaging
 *              scopes present, the one-year refresh token not about to expire;
 *  - account   `/business/get/`: still a TikTok Account with Advanced Access / Verified Business Account
 *              (`has_advanced_access`), and the card's name / picture refreshed;
 *  - webhooks  the app-level DIRECT_MESSAGE subscription was made from here (TikTok offers no read-back:
 *              ASSUMPTION, so the check trusts our own record and the last webhook time).
 *
 * ConnectionHealthCheck adds the shared inbound check, decides the status and stores the result.
 */
final class TikTokHealthCheck
{
    /** Scopes a DM connection cannot work without. */
    public const REQUIRED_SCOPES = ['message.list.read', 'message.list.send'];

    public const RECOMMENDED_SCOPES = ['message.list.manage', 'user.account.type'];

    public function __construct(private readonly TikTokMessagingApi $api, private readonly TikTokConnector $connector) {}

    /** @return list<array<string, mixed>> */
    public function checks(ChannelAccount $account): array
    {
        $token = $this->safely('token', fn () => $this->tokenCheck($account));
        $checks = [$token];

        $checks[] = $token['status'] === 'problem'
            ? $this->check('account', 'warning', 'check_failed', detail: 'token')
            : $this->safely('account', fn () => $this->accountCheck($account));

        $checks[] = $this->webhookCheck($account);

        return $checks;
    }

    /** @return array<string, mixed> */
    private function tokenCheck(ChannelAccount $account): array
    {
        if (blank($account->credentials['access_token'] ?? null)) {
            return $this->check('token', 'problem', 'token_missing', fix: 'reconnect');
        }

        $refreshExpires = TikTokTokens::refreshExpiresAt($account);

        if ($refreshExpires !== null && $refreshExpires->isPast()) {
            return $this->check('token', 'problem', 'refresh_token_expired', fix: 'reconnect');
        }

        try {
            $token = TikTokTokens::valid($account, fn (string $refresh) => $this->api->refreshToken($refresh));
            $scopes = $this->api->tokenScopes($token);
        } catch (TikTokApiException $e) {
            return $e->isAuth() || $e->apiCode === TikTokApiException::NO_PERMISSION
                ? $this->check('token', 'problem', 'token_invalid', detail: $e->getMessage(), fix: 'reconnect')
                : $this->check('token', 'warning', 'check_failed', detail: $e->getMessage());
        }

        $scopes = $scopes !== [] ? $scopes : TikTokTokens::scopes($account);

        if ($scopes !== [] && ($missing = array_values(array_diff(self::REQUIRED_SCOPES, $scopes))) !== []) {
            return $this->check('token', 'problem', 'missing_scopes', missing: $missing, fix: 'reconnect');
        }

        if ($refreshExpires !== null && $refreshExpires->lt(now()->addDays(14))) {
            return $this->check('token', 'warning', 'token_expiring', detail: $refreshExpires->toIso8601String(), fix: 'reconnect');
        }

        if ($scopes !== [] && ($missing = array_values(array_diff(self::RECOMMENDED_SCOPES, $scopes))) !== []) {
            return $this->check('token', 'warning', 'missing_recommended_scopes', missing: $missing);
        }

        return $this->check('token', 'ok', 'token_valid', detail: $refreshExpires?->toIso8601String());
    }

    /** @return array<string, mixed> */
    private function accountCheck(ChannelAccount $account): array
    {
        $info = $this->api->businessInfo($account);
        $this->connector->refreshProfile($account);

        if (array_key_exists('has_advanced_access', $info) && ! $info['has_advanced_access']) {
            return $this->check('account', 'problem', 'no_advanced_access', detail: filled($info['username'] ?? null) ? '@'.$info['username'] : null);
        }

        return $this->check('account', 'ok', 'account_ok', detail: filled($info['username'] ?? null) ? '@'.$info['username'] : null);
    }

    /** @return array<string, mixed> */
    private function webhookCheck(ChannelAccount $account): array
    {
        $subscribedAt = $account->profile['webhook_subscribed_at'] ?? null;
        $callback = (string) ($account->profile['webhook_callback_url'] ?? '');

        if (blank($subscribedAt)) {
            return $this->check('webhooks', 'problem', 'not_subscribed', fix: 'resubscribe');
        }

        if ($callback !== '' && $callback !== TikTokConnector::callbackUrl()) {
            return $this->check('webhooks', 'warning', 'callback_mismatch', detail: $callback, fix: 'resubscribe');
        }

        return $this->check('webhooks', 'ok', 'subscribed', detail: (string) $subscribedAt);
    }

    /**
     * @param  callable(): array<string, mixed>  $run
     * @return array<string, mixed>
     */
    private function safely(string $key, callable $run): array
    {
        try {
            return $run();
        } catch (ConnectionException) {
            return $this->check($key, 'warning', 'check_failed', detail: 'tiktok_unreachable');
        } catch (TikTokApiException $e) {
            return $this->check($key, 'warning', 'check_failed', detail: $e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return $this->check($key, 'warning', 'check_failed', detail: class_basename($e));
        }
    }

    /**
     * @param  list<string>  $missing
     * @return array<string, mixed>
     */
    private function check(string $key, string $status, string $code, mixed $detail = null, array $missing = [], ?string $fix = null): array
    {
        return array_filter([
            'key' => $key,
            'status' => $status,
            'code' => $code,
            'detail' => is_string($detail) && $detail !== '' ? Str::limit($detail, 300) : null,
            'missing' => $missing ?: null,
            'fix' => $fix,
        ], fn ($v) => $v !== null);
    }
}
