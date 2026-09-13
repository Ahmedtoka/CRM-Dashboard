<?php

namespace App\Channels\Adapters;

use App\Channels\Data\SendResult;
use App\Models\ChannelAccount;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Thin wrapper around the Meta Graph API HTTP client shared by the
 * Messenger, Instagram and WhatsApp Cloud API adapters.
 */
class MetaGraphClient
{
    public function client(ChannelAccount $account): PendingRequest
    {
        $version = config('crm.meta.graph_version', 'v23.0');
        $token = (string) ($account->graphToken() ?? '');

        $client = Http::baseUrl('https://graph.facebook.com/'.$version)
            ->withToken($token)
            ->timeout(10)
            ->retry(2, 200, throw: false);

        return $this->withProof($client, $token);
    }

    /**
     * Meta requires `appsecret_proof` (an HMAC of the access token actually used for the
     * call, keyed by the app secret) on every Graph API request once "Require App Secret"
     * is enabled for the app — added here as a query parameter for both GET and POST.
     */
    public function withProof(PendingRequest $client, string $accessToken): PendingRequest
    {
        $secret = config('crm.meta.app_secret');

        if (! $secret || $accessToken === '') {
            return $client;
        }

        return $client->withOptions(['query' => ['appsecret_proof' => hash_hmac('sha256', $accessToken, $secret)]]);
    }

    /**
     * Raw GET against the Graph API, used for one-off admin actions (the settings "Test"
     * button) rather than the structured send/receive flow.
     *
     * @param  array<string, mixed>  $query
     */
    public function get(ChannelAccount $account, string $endpoint, array $query = []): Response
    {
        return $this->client($account)->get($endpoint, $query);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function post(ChannelAccount $account, string $endpoint, array $payload): SendResult
    {
        $response = $this->client($account)->post($endpoint, $payload);

        if ($response->successful()) {
            $externalId = $response->json('message_id')
                ?? $response->json('id')
                ?? $response->json('messages.0.id')
                ?? '';

            return SendResult::ok((string) $externalId);
        }

        $error = $response->json('error.message') ?? 'graph_api_error';
        $retryable = $response->status() >= 500 || $response->status() === 429;

        // Expired/revoked page tokens (OAuthException code 190) or a rejected token (401/403)
        // mean the channel itself is broken: SendOutboundMessage raises a channel alert.
        $authError = (int) $response->json('error.code') === 190 || in_array($response->status(), [401, 403], true);

        return SendResult::fail($error, $retryable, $authError);
    }
}
