<?php

namespace App\Channels\Adapters\Concerns;

use App\Channels\VerifyToken;
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
        $challenge = $request->query('hub_challenge');
        $expected = (string) config('crm.meta.verify_token');

        if ($mode === 'subscribe'
            && VerifyToken::usable()
            && is_string($token)
            && hash_equals($expected, $token)
            && is_string($challenge)
            && preg_match('/^[0-9A-Za-z_-]{1,128}$/', $challenge) === 1) {
            // Echoed back verbatim, so never as HTML (audit A2: reflected XSS).
            return response($challenge, 200, [
                'Content-Type' => 'text/plain; charset=UTF-8',
                'X-Content-Type-Options' => 'nosniff',
            ]);
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
