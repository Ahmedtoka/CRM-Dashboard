<?php

namespace App\Channels\Adapters\TikTok;

use App\Ads\Platforms\SecretScrubber;
use App\Models\ChannelAccount;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Business Messaging API v1.3 over HTTP (read from the portal 2026-10-24). The token rides in the
 * `Access-Token` header, never a query string; every envelope is checked (`code` 0) and a failure becomes a
 * TikTokApiException with TikTok's message, tokens scrubbed. Account calls renew the one-day token first
 * (TikTokTokens). Short timeouts: a send runs inside the outbound job's 75 s budget.
 */
final class LiveTikTokMessagingApi implements TikTokMessagingApi
{
    public function send(ChannelAccount $account, array $body): array
    {
        $token = $this->token($account);
        $data = $this->call($token, fn () => $this->http($token, 15)->post($this->url('business/message/send/'), ['business_id' => (string) $account->external_id] + $body));

        return ['message_id' => (string) ($data['message']['message_id'] ?? '')];
    }

    public function uploadImage(ChannelAccount $account, string $path, string $filename): string
    {
        $token = $this->token($account);
        $stream = fopen($path, 'rb');

        if ($stream === false) {
            throw new TikTokApiException(40002, 'The image file could not be read.');
        }

        try {
            $data = $this->call($token, fn () => $this->http($token, 60)
                ->attach('file', $stream, $filename)
                ->post($this->url('business/message/media/upload/'), ['business_id' => (string) $account->external_id, 'media_type' => 'IMAGE']));
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return (string) ($data['media_id'] ?? '');
    }

    public function downloadUrl(ChannelAccount $account, string $conversationId, string $messageId, string $mediaId, string $mediaType): string
    {
        $token = $this->token($account);
        $data = $this->call($token, fn () => $this->http($token, 15)->post($this->url('business/message/media/download/'), [
            'business_id' => (string) $account->external_id,
            'conversation_id' => $conversationId,
            'message_id' => $messageId,
            'media_id' => $mediaId,
            'media_type' => $mediaType,
        ]));

        return (string) ($data['download_url'] ?? '');
    }

    public function canSendImage(ChannelAccount $account, string $conversationId, string $conversationType = 'SINGLE'): bool
    {
        $token = $this->token($account);
        $data = $this->call($token, fn () => $this->http($token, 10)->get($this->url('business/message/capabilities/get/'), [
            'business_id' => (string) $account->external_id,
            'conversation_id' => $conversationId,
            'conversation_type' => $conversationType,
            'capability_types' => json_encode(['IMAGE_SEND']),
        ]));

        foreach ((array) ($data['capability_infos'] ?? []) as $info) {
            if (($info['capability_type'] ?? null) === 'IMAGE_SEND') {
                return (bool) ($info['capability_result'] ?? false);
            }
        }

        return false;
    }

    public function businessInfo(ChannelAccount $account): array
    {
        $token = $this->token($account);

        return $this->call($token, fn () => $this->http($token, 10)->get($this->url('business/get/'), [
            'business_id' => (string) $account->external_id,
            'fields' => json_encode(['username', 'display_name', 'profile_image', 'has_advanced_access']),
        ]));
    }

    public function exchangeCode(string $code, string $redirectUri): array
    {
        return $this->call(null, fn () => $this->http(null, 15)->post($this->url('tt_user/oauth2/token/'), [
            'client_id' => (string) config('services.tiktok.app_id'),
            'client_secret' => (string) config('services.tiktok.app_secret'),
            'grant_type' => 'authorization_code',
            'auth_code' => $code,
            'redirect_uri' => $redirectUri,
        ]));
    }

    public function refreshToken(string $refreshToken): array
    {
        return $this->call(null, fn () => $this->http(null, 15)->post($this->url('tt_user/oauth2/refresh_token/'), [
            'client_id' => (string) config('services.tiktok.app_id'),
            'client_secret' => (string) config('services.tiktok.app_secret'),
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]));
    }

    public function revoke(string $accessToken): void
    {
        $this->call($accessToken, fn () => $this->http(null, 10)->post($this->url('tt_user/oauth2/revoke/'), [
            'client_id' => (string) config('services.tiktok.app_id'),
            'client_secret' => (string) config('services.tiktok.app_secret'),
            'access_token' => $accessToken,
        ]));
    }

    public function tokenScopes(string $accessToken): array
    {
        $data = $this->call($accessToken, fn () => $this->http($accessToken, 10)->get($this->url('tt_user/token_info/get/'), [
            'app_id' => (string) config('services.tiktok.app_id'),
            'access_token' => $accessToken,
        ]));

        $scope = $data['scope'] ?? ($data['scopes'] ?? []);

        return array_values(array_filter(array_map('trim', is_array($scope) ? array_map('strval', $scope) : explode(',', (string) $scope))));
    }

    public function subscribeWebhook(string $callbackUrl): void
    {
        $this->call(null, fn () => $this->http(null, 15)->post($this->url('business/webhook/update/'), [
            'app_id' => (string) config('services.tiktok.app_id'),
            'secret' => (string) config('services.tiktok.app_secret'),
            'event_type' => 'DIRECT_MESSAGE',
            'callback_url' => $callbackUrl,
        ]));
    }

    private function token(ChannelAccount $account): string
    {
        return TikTokTokens::valid($account, fn (string $refresh) => $this->refreshToken($refresh));
    }

    private function http(?string $token, int $timeout): PendingRequest
    {
        $request = Http::timeout($timeout)->connectTimeout(10)->acceptJson();

        return $token === null ? $request : $request->withHeaders(['Access-Token' => $token]);
    }

    private function url(string $path): string
    {
        return rtrim((string) config('services.tiktok.base_url'), '/').'/'.ltrim($path, '/');
    }

    /**
     * @param  callable(): Response  $send
     * @return array<string, mixed> the envelope's `data`
     */
    private function call(?string $token, callable $send): array
    {
        $response = $send();
        $secrets = array_values(array_filter([$token, (string) config('services.tiktok.app_secret')]));

        if ($response->status() === 429) {
            throw new TikTokApiException(TikTokApiException::THROTTLED, 'TikTok rate limit reached; retry later.');
        }

        $code = $response->json('code');

        if ($code === null) {
            throw new TikTokApiException(TikTokApiException::SYSTEM_ERROR, 'TikTok API error: unexpected response (HTTP '.$response->status().')');
        }

        if ((int) $code !== 0) {
            $message = SecretScrubber::scrub((string) ($response->json('message') ?: 'TikTok API error (code '.$code.')'), $secrets);

            throw new TikTokApiException((int) $code, $message, $response->json('request_id'));
        }

        return (array) ($response->json('data') ?? []);
    }
}
