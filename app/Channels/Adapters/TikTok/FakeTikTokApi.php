<?php

namespace App\Channels\Adapters\TikTok;

use App\Models\ChannelAccount;

/**
 * The Business Messaging API as a recorder (tests, the demo, any server where TIKTOK_DM_DRIVER=fake):
 * every call lands in a static log, nothing leaves the machine. `failNext()` makes the next call throw a
 * TikTokApiException with the given TikTok code, so the adapter's error paths are testable.
 */
class FakeTikTokApi implements TikTokMessagingApi
{
    /** @var list<array<string, mixed>> */
    private static array $calls = [];

    /** @var array{code: int, message: string}|null */
    private static ?array $failNext = null;

    private static int $sequence = 0;

    public static function reset(): void
    {
        self::$calls = [];
        self::$failNext = null;
        // The id sequence keeps counting across reset(): a message id must stay unique within one test database.
    }

    /** @return list<array<string, mixed>> */
    public static function calls(?string $method = null): array
    {
        return $method === null ? self::$calls : array_values(array_filter(self::$calls, fn ($c) => $c['method'] === $method));
    }

    /** The bodies of every `send` call, in order. @return list<array<string, mixed>> */
    public static function sent(): array
    {
        return array_map(fn ($c) => $c['body'], self::calls('send'));
    }

    public static function failNext(int $code, string $message = 'fake failure'): void
    {
        self::$failNext = ['code' => $code, 'message' => $message];
    }

    public function send(ChannelAccount $account, array $body): array
    {
        $this->record('send', ['account_id' => $account->id, 'body' => $body]);

        return ['message_id' => ($body['message_type'] ?? '') === 'SENDER_ACTION' ? '' : 'tt_fake_'.(++self::$sequence)];
    }

    public function uploadImage(ChannelAccount $account, string $path, string $filename): string
    {
        $this->record('uploadImage', ['account_id' => $account->id, 'path' => $path, 'filename' => $filename]);

        return 'media_fake_'.(++self::$sequence);
    }

    public function downloadUrl(ChannelAccount $account, string $conversationId, string $messageId, string $mediaId, string $mediaType): string
    {
        $this->record('downloadUrl', compact('conversationId', 'messageId', 'mediaId', 'mediaType') + ['account_id' => $account->id]);

        return 'https://fake.tiktok.local/media/'.$mediaId;
    }

    public function canSendImage(ChannelAccount $account, string $conversationId, string $conversationType = 'SINGLE'): bool
    {
        $this->record('canSendImage', compact('conversationId', 'conversationType') + ['account_id' => $account->id]);

        return true;
    }

    public function businessInfo(ChannelAccount $account): array
    {
        $this->record('businessInfo', ['account_id' => $account->id]);

        return ['username' => 'levoilestores', 'display_name' => 'Le Voile', 'profile_image' => 'https://fake.tiktok.local/avatar.jpg', 'has_advanced_access' => true];
    }

    public function exchangeCode(string $code, string $redirectUri): array
    {
        $this->record('exchangeCode', compact('code', 'redirectUri'));

        return [
            'access_token' => 'fake-access-'.$code,
            'refresh_token' => 'fake-refresh-'.$code,
            'open_id' => 'fake-open-id',
            'expires_in' => 86400,
            'refresh_token_expires_in' => 31536000,
            'scope' => implode(',', (array) config('services.tiktok.scopes', [])),
            'token_type' => 'Bearer',
        ];
    }

    public function refreshToken(string $refreshToken): array
    {
        $this->record('refreshToken', compact('refreshToken'));

        return [
            'access_token' => 'fake-access-refreshed-'.(++self::$sequence),
            'refresh_token' => $refreshToken,
            'open_id' => 'fake-open-id',
            'expires_in' => 86400,
            'refresh_token_expires_in' => 31536000,
            'scope' => implode(',', (array) config('services.tiktok.scopes', [])),
        ];
    }

    public function revoke(string $accessToken): void
    {
        $this->record('revoke', ['accessToken' => $accessToken]);
    }

    public function tokenScopes(string $accessToken): array
    {
        $this->record('tokenScopes', ['accessToken' => $accessToken]);

        return (array) config('services.tiktok.scopes', []);
    }

    public function subscribeWebhook(string $callbackUrl): void
    {
        $this->record('subscribeWebhook', compact('callbackUrl'));
    }

    /** @param  array<string, mixed>  $args */
    private function record(string $method, array $args): void
    {
        self::$calls[] = ['method' => $method] + $args;

        if (self::$failNext !== null) {
            $failure = self::$failNext;
            self::$failNext = null;

            throw new TikTokApiException($failure['code'], $failure['message']);
        }
    }
}
