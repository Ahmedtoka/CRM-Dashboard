<?php

namespace App\Channels\Adapters\TikTok;

use App\Models\ChannelAccount;

/**
 * The Business Messaging API calls the CRM makes (business-api.tiktok.com/open_api/v1.3, read 2026-10-24).
 * `LiveTikTokMessagingApi` talks to TikTok; `FakeTikTokApi` records everything for tests and the demo.
 * Account calls take the ChannelAccount so the live client can refresh its one-day access token first.
 *
 * Every method throws TikTokApiException on a non-zero envelope code, and
 * Illuminate\Http\Client\ConnectionException when TikTok cannot be reached.
 */
interface TikTokMessagingApi
{
    /** `POST /business/message/send/` — the body minus business_id. @return array{message_id: string} */
    public function send(ChannelAccount $account, array $body): array;

    /** `POST /business/message/media/upload/` (multipart, IMAGE only, JPG/PNG ≤ 3 MB). @return string media_id, valid 30 days */
    public function uploadImage(ChannelAccount $account, string $path, string $filename): string;

    /** `POST /business/message/media/download/` → a 24-hour download_url (fetched with header x-user: token). */
    public function downloadUrl(ChannelAccount $account, string $conversationId, string $messageId, string $mediaId, string $mediaType): string;

    /** `GET /business/message/capabilities/get/` IMAGE_SEND for one conversation. */
    public function canSendImage(ChannelAccount $account, string $conversationId, string $conversationType = 'SINGLE'): bool;

    /** `GET /business/get/` on the account's own token. @return array<string, mixed> username, display_name, profile_image, has_advanced_access */
    public function businessInfo(ChannelAccount $account): array;

    /** `POST /tt_user/oauth2/token/` (grant_type authorization_code). @return array<string, mixed> access_token, refresh_token, open_id, expires_in, refresh_token_expires_in, scope */
    public function exchangeCode(string $code, string $redirectUri): array;

    /** `POST /tt_user/oauth2/refresh_token/`. @return array<string, mixed> same shape as exchangeCode() */
    public function refreshToken(string $refreshToken): array;

    /** `POST /tt_user/oauth2/revoke/` — best effort on disconnect. */
    public function revoke(string $accessToken): void;

    /** `GET /tt_user/token_info/get/` — the scopes a token really carries. @return list<string> */
    public function tokenScopes(string $accessToken): array;

    /** `POST /business/webhook/update/` — app-level: DIRECT_MESSAGE events to our callback, for every authorized account. */
    public function subscribeWebhook(string $callbackUrl): void;
}
