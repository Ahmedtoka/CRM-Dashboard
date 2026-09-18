<?php

namespace App\Media;

use App\Channels\Adapters\FakeChannelAdapter;
use App\Channels\Adapters\MetaGraphClient;
use App\Channels\ChannelRegistry;
use App\Enums\AttachmentType;
use App\Enums\Platform;
use App\Models\MessageAttachment;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\StreamInterface;

/**
 * Downloads the real bytes for a pending inbound MessageAttachment: a
 * simulator fixture, a WhatsApp media id (resolved through the Graph API
 * with the account's token), or a direct platform CDN url.
 *
 * Every network error is converted to a short, code-style MediaFetchFailed
 * message — never the raw exception text — since that message ends up
 * stored on the attachment (and, if the job exhausts its retries, in
 * `failed_jobs`); a raw Guzzle exception could otherwise leak a signed url,
 * an appsecret_proof, or a bearer token into either place.
 */
final class InboundMediaFetcher
{
    /**
     * Downloads are only ever fetched from Meta's own CDN/media hosts (spec
     * §1, "Security"). A host is allowed when it equals one of these, or is a
     * subdomain of one (`graph.facebook.com` and `lookaside.fbsbx.com` are
     * both covered by their respective suffixes below).
     */
    private const ALLOWED_HOST_SUFFIXES = [
        'fbcdn.net',
        'fbsbx.com',
        'cdninstagram.com',
        'whatsapp.net',
        'facebook.com',
    ];

    public function __construct(private readonly MetaGraphClient $graph, private readonly ChannelRegistry $registry) {}

    public function fetch(MessageAttachment $a): FetchedMedia
    {
        $remote = (string) $a->remote_url;

        if (str_starts_with($remote, 'fixture:')) {
            $kind = substr($remote, 8);

            return new FetchedMedia(SampleMedia::bytes($kind), SampleMedia::mime($kind), SampleMedia::filename($kind));
        }

        $conversation = $a->message?->conversation ?? throw new MediaFetchFailed('no_conversation');

        if ($this->registry->adapter($conversation->platform) instanceof FakeChannelAdapter) {
            throw new MediaFetchFailed('fake_driver_no_network');
        }

        if ($a->remote_id !== null && $conversation->platform === Platform::WhatsApp) {
            $account = $conversation->channelAccount;

            try {
                $meta = $this->graph->get($account, $a->remote_id);
            } catch (ConnectionException|RequestException) {
                throw new MediaFetchFailed('download_connection_error');
            }

            if (! $meta->successful() || ! is_string($meta->json('url'))) {
                throw new MediaFetchFailed('whatsapp_media_lookup_'.$meta->status());
            }

            return $this->download((string) $meta->json('url'), (string) $account->graphToken(), $meta->json('mime_type'), $a->type);
        }

        if ($remote !== '') {
            return $this->download($remote, null, $a->mime, $a->type);
        }

        throw new MediaFetchFailed('nothing_to_fetch');
    }

    private function download(string $url, ?string $token, ?string $mime, AttachmentType $type): FetchedMedia
    {
        $this->assertUrlAllowed($url);

        $max = $this->maxBytesFor($type);

        $request = Http::timeout(30)->withOptions(['stream' => true, 'allow_redirects' => false]);

        if ($token) {
            $request = $request->withToken($token);
        }

        try {
            $response = $request->get($url);
        } catch (ConnectionException|RequestException) {
            throw new MediaFetchFailed('download_connection_error');
        }

        if (! $response->successful()) {
            throw new MediaFetchFailed('download_http_'.$response->status());
        }

        $declared = $response->header('Content-Length');
        if ($declared !== null && is_numeric($declared) && (int) $declared > $max) {
            throw new MediaFetchFailed('download_too_large');
        }

        $bytes = $this->readBounded($response->toPsrResponse()->getBody(), $max);

        return new FetchedMedia($bytes, $mime ?? ($response->header('Content-Type') ?: null), null);
    }

    /**
     * Reads a PSR-7 stream into a temp file in chunks rather than buffering
     * the whole (attacker-controlled) body in memory up front, aborting as
     * soon as the running total crosses $max — protects against a body
     * that lied about (or omitted) Content-Length.
     */
    private function readBounded(StreamInterface $body, int $max): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'crm-fetch');
        $sink = fopen($tmp, 'wb');
        $written = 0;

        try {
            while (! $body->eof()) {
                $chunk = $body->read(65536);
                $written += strlen($chunk);

                if ($written > $max) {
                    throw new MediaFetchFailed('download_too_large');
                }

                fwrite($sink, $chunk);
            }

            fclose($sink);

            return (string) file_get_contents($tmp);
        } finally {
            if (is_resource($sink)) {
                fclose($sink);
            }
            @unlink($tmp);
        }
    }

    private function maxBytesFor(AttachmentType $type): int
    {
        $configured = config("crm.media.types.{$type->value}.max_bytes");

        if (is_numeric($configured)) {
            return (int) $configured;
        }

        // Types with no dedicated config entry (currently just stickers) borrow
        // the image cap — stickers are small images in practice.
        return (int) config('crm.media.types.image.max_bytes', 8 * 1024 * 1024);
    }

    private function assertUrlAllowed(string $url): void
    {
        $parsed = parse_url($url);

        // parse_url() returns false (not an empty array) for a malformed url —
        // reading ['scheme']/['host'] off `false` would throw a PHP notice, and
        // silently coercing it to '' would report the wrong reason via
        // insecure_scheme. Reject it explicitly, as an unrecognised host.
        if ($parsed === false) {
            throw new MediaFetchFailed('host_not_allowed');
        }

        $scheme = strtolower((string) ($parsed['scheme'] ?? ''));
        $host = strtolower((string) ($parsed['host'] ?? ''));

        if ($scheme !== 'https') {
            throw new MediaFetchFailed('insecure_scheme');
        }

        foreach (self::ALLOWED_HOST_SUFFIXES as $suffix) {
            if ($host === $suffix || str_ends_with($host, '.'.$suffix)) {
                return;
            }
        }

        throw new MediaFetchFailed('host_not_allowed');
    }
}
