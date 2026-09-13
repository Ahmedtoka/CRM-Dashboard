<?php

namespace App\Channels\Adapters\Concerns;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shared GET handshake + POST signature verification for the Meta family
 * of webhooks (Messenger, Instagram, WhatsApp Cloud API all use the same
 * subscription handshake and X-Hub-Signature-256 scheme).
 */
trait VerifiesMetaWebhooks
{
    public function handshake(Request $request): ?Response
    {
        $mode = $request->query('hub_mode');
        $token = $request->query('hub_verify_token');
        $challenge = (string) $request->query('hub_challenge');

        if ($mode === 'subscribe' && is_string($token) && hash_equals((string) config('crm.meta.verify_token'), $token)) {
            return response($challenge, 200);
        }

        return response('Forbidden', 403);
    }

    public function verifySignature(Request $request): bool
    {
        $secret = config('crm.meta.app_secret');

        if (! $secret) {
            return false;
        }

        $header = (string) $request->header('X-Hub-Signature-256', '');
        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);

        return hash_equals($expected, $header);
    }
}
