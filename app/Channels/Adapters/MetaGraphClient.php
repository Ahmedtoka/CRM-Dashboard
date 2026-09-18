<?php

namespace App\Channels\Adapters;

use App\Channels\Data\SendResult;
use App\Models\ChannelAccount;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Thin wrapper around the Meta Graph API HTTP client shared by the
 * Messenger, Instagram and WhatsApp Cloud API adapters.
 *
 * Every HTTP call this class makes goes through `guarded()` (final fix wave
 * I1): a Guzzle/Laravel `ConnectionException` message embeds the full request
 * URI — including the `appsecret_proof` query parameter added by `withProof()`
 * — so it is rethrown with the query string stripped and without chaining the
 * original exception (whose message would otherwise still reach a log). A
 * `RequestException` is never raised here (no call uses `throw()`), and its
 * message carries only the status code and a body summary, never the URI.
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
        return $this->guarded(fn () => $this->client($account)->get($endpoint, $query));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function post(ChannelAccount $account, string $endpoint, array $payload): SendResult
    {
        return $this->toResult($this->guarded(fn () => $this->client($account)->post($endpoint, $payload)));
    }

    /**
     * Multipart upload used by the WhatsApp Cloud API's `/{phone-number-id}/media`
     * endpoint: the response carries a media id (mapped by toResult() same as any
     * other Graph "id") to reference in the follow-up `/messages` send.
     *
     * Deliberately its own client (not `client()`'s 10s/2-retries): a multipart
     * upload of up to a few MB needs more headroom than a small JSON POST, but it
     * must still fit the outbound send job's overall timeout budget (75s) next to
     * the optional ffmpeg transcode (15s) and the follow-up `/messages` call(s) —
     * so it gets a single try, failing fast rather than doubling a slow upload.
     */
    public function upload(ChannelAccount $account, string $endpoint, string $absolutePath, string $mime, string $filename): SendResult
    {
        $response = $this->guarded(fn () => $this->noRetryClient($account, 30)
            ->attach('file', (string) file_get_contents($absolutePath), $filename, ['Content-Type' => $mime])
            ->post($endpoint, ['messaging_product' => 'whatsapp', 'type' => $mime]));

        return $this->toResult($response);
    }

    /**
     * A fast, single-try post for a follow-up call made from inside an
     * already budget-constrained path (currently: the WhatsApp attachment
     * send's caption follow-up) — a fixed, short timeout and no retry, so a
     * slow/stuck follow-up can never add the shared client()'s full ~20s
     * worst case (10s timeout × up to 2 tries) on top of a job that has
     * already spent most of its 75s budget upstream.
     *
     * @param  array<string, mixed>  $payload
     */
    public function postFast(ChannelAccount $account, string $endpoint, array $payload, int $timeoutSeconds = 10): SendResult
    {
        return $this->toResult($this->guarded(fn () => $this->noRetryClient($account, $timeoutSeconds)->post($endpoint, $payload)));
    }

    /**
     * Removes every URL query string (and any stray `appsecret_proof=` /
     * `access_token=` pair) from text that may be persisted, broadcast or logged.
     */
    public static function redact(string $text): string
    {
        $text = preg_replace('~(https?://[^\s?#"\'<>]+)\?[^\s"\'<>]*~i', '$1', $text) ?? '';

        return preg_replace('~\b(appsecret_proof|access_token|input_token)=[^&\s"\'<>]*~i', '[redacted]', $text) ?? '';
    }

    /**
     * @template T
     *
     * @param  callable(): T  $call
     * @return T
     *
     * @throws ConnectionException with a redacted message and no previous exception
     */
    private function guarded(callable $call): mixed
    {
        try {
            return $call();
        } catch (ConnectionException $e) {
            throw new ConnectionException(self::redact($e->getMessage()), (int) $e->getCode());
        }
    }

    private function noRetryClient(ChannelAccount $account, int $timeoutSeconds): PendingRequest
    {
        $version = config('crm.meta.graph_version', 'v23.0');
        $token = (string) ($account->graphToken() ?? '');

        return $this->withProof(
            Http::baseUrl('https://graph.facebook.com/'.$version)->withToken($token)->timeout($timeoutSeconds),
            $token,
        );
    }

    private function toResult(Response $response): SendResult
    {
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
