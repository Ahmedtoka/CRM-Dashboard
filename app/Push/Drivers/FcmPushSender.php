<?php

namespace App\Push\Drivers;

use App\Models\DeviceToken;
use App\Push\PushMessage;
use App\Push\PushResult;
use App\Push\PushSender;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Firebase Cloud Messaging HTTP v1 (spec D8). Authenticates with a service account: a JWT
 * signed RS256 with openssl is exchanged for an OAuth2 access token, cached 50 minutes
 * (Google issues them for 60). No SDK dependency.
 *
 * `crm.push.fcm.credentials` (FCM_CREDENTIALS) may be a path to the JSON key file
 * (relative paths resolve from the project root), the JSON itself, or the JSON base64-encoded.
 *
 * Only token-attributable answers are device faults (review 01, I3): 404 / UNREGISTERED and
 * INVALID_ARGUMENT whose message is about the registration token. SENDER_ID_MISMATCH is a
 * project/configuration error (review 03, R2).
 * Auth, connection, 429 and 5xx are transient (the job retries); anything else is an error
 * that leaves the device alone.
 */
final class FcmPushSender implements PushSender
{
    public const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    private const TOKEN_TTL_SECONDS = 3000;

    public function send(DeviceToken $device, PushMessage $message): PushResult
    {
        try {
            $credentials = $this->credentials();
        } catch (RuntimeException $e) {
            return PushResult::failed('fcm_config: '.$e->getMessage());
        }

        $project = (string) (config('crm.push.fcm.project_id') ?: ($credentials['project_id'] ?? ''));

        if ($project === '') {
            return PushResult::failed('fcm_config: project id missing');
        }

        try {
            $accessToken = $this->accessToken($credentials);
        } catch (RuntimeException|ConnectionException $e) {
            return PushResult::transient('fcm_auth: '.$e->getMessage());
        }

        try {
            $response = Http::withToken($accessToken)->acceptJson()->timeout(5)->connectTimeout(3)
                ->post("https://fcm.googleapis.com/v1/projects/{$project}/messages:send", ['message' => $this->payload($device, $message)]);
        } catch (ConnectionException) {
            return PushResult::transient('fcm_connection');
        }

        if ($response->successful()) {
            return PushResult::sent();
        }

        return $this->classify($response, $credentials);
    }

    /** @param  array<string, mixed>  $credentials */
    private function classify(Response $response, array $credentials): PushResult
    {
        $status = $response->status();
        $code = $this->errorCode($response);
        $error = 'fcm_'.$status.($code !== null ? ':'.$code : '');

        // The token belongs to another Firebase project: our configuration is wrong, not the
        // phone — never count it against the device (review 03, R2).
        if ($code === 'SENDER_ID_MISMATCH') {
            return PushResult::failed('fcm_config: '.$error);
        }

        if ($status === 404 || $code === 'UNREGISTERED') {
            return PushResult::deviceFault($error, invalidToken: true);
        }

        if ($status === 400 && $code === 'INVALID_ARGUMENT' && $this->aboutToken($response)) {
            return PushResult::deviceFault($error, invalidToken: true);
        }

        if ($status === 401) {
            Cache::forget($this->cacheKey($credentials));

            return PushResult::transient($error);
        }

        if ($status === 429 || $status >= 500) {
            return PushResult::transient($error);
        }

        return PushResult::failed($error);
    }

    /** FCM says "The registration token is not a valid FCM registration token" for a bad token. */
    private function aboutToken(Response $response): bool
    {
        $message = strtolower((string) $response->json('error.message', ''));

        if (str_contains($message, 'registration token')) {
            return true;
        }

        foreach ((array) $response->json('error.details', []) as $detail) {
            foreach ((array) ($detail['fieldViolations'] ?? []) as $violation) {
                if (is_array($violation) && in_array($violation['field'] ?? null, ['message.token', 'token'], true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    private function payload(DeviceToken $device, PushMessage $message): array
    {
        $high = $message->priority === 'high';
        $android = [
            'priority' => $high ? 'HIGH' : 'NORMAL',
            // Lock screen shows the app name only, not the customer's name or the excerpt (review 01, M7).
            'notification' => ['channel_id' => $message->channel, 'visibility' => 'PRIVATE'],
        ];
        $apnsHeaders = ['apns-priority' => $high ? '10' : '5'];

        if ($message->collapseKey !== null) {
            $android['collapse_key'] = $message->collapseKey;
            $apnsHeaders['apns-collapse-id'] = substr($message->collapseKey, 0, 64);
        }

        return [
            'token' => $device->token,
            'notification' => ['title' => $message->title, 'body' => $message->body],
            'data' => array_map('strval', $message->data),
            'android' => $android,
            'apns' => ['headers' => $apnsHeaders],
        ];
    }

    private function errorCode(Response $response): ?string
    {
        foreach ((array) $response->json('error.details', []) as $detail) {
            if (is_array($detail) && isset($detail['errorCode'])) {
                return (string) $detail['errorCode'];
            }
        }

        $status = $response->json('error.status');

        return is_string($status) ? $status : null;
    }

    /** @return array<string, mixed> */
    private function credentials(): array
    {
        $raw = trim((string) config('crm.push.fcm.credentials'));

        if ($raw === '') {
            throw new RuntimeException('FCM_CREDENTIALS is empty');
        }

        if (str_starts_with($raw, '{')) {
            $json = $raw;
        } else {
            $path = preg_match('#^([A-Za-z]:[\\\\/]|/|\\\\)#', $raw) === 1 ? $raw : base_path($raw);

            if (is_file($path) && is_readable($path)) {
                $json = (string) file_get_contents($path);
            } elseif (($decoded = base64_decode($raw, true)) !== false && str_starts_with(trim($decoded), '{')) {
                $json = $decoded;
            } else {
                throw new RuntimeException('credentials not found (not a readable file, JSON or base64 JSON)');
            }
        }

        $data = json_decode($json, true);

        if (! is_array($data) || empty($data['client_email']) || empty($data['private_key'])) {
            throw new RuntimeException('credentials invalid (client_email / private_key missing)');
        }

        return $data;
    }

    /** @param  array<string, mixed>  $credentials */
    private function accessToken(array $credentials): string
    {
        $key = $this->cacheKey($credentials);
        $cached = Cache::get($key);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $tokenUri = (string) ($credentials['token_uri'] ?? 'https://oauth2.googleapis.com/token');
        $response = Http::asForm()->acceptJson()->timeout(5)->connectTimeout(3)->post($tokenUri, [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $this->jwt($credentials, $tokenUri),
        ]);

        $token = $response->json('access_token');

        if (! $response->successful() || ! is_string($token) || $token === '') {
            throw new RuntimeException('token request failed ('.$response->status().')');
        }

        Cache::put($key, $token, self::TOKEN_TTL_SECONDS);

        return $token;
    }

    /** @param  array<string, mixed>  $credentials */
    private function jwt(array $credentials, string $audience): string
    {
        $now = time();
        $segments = [
            self::b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])),
            self::b64(json_encode([
                'iss' => $credentials['client_email'],
                'scope' => self::SCOPE,
                'aud' => $audience,
                'iat' => $now,
                'exp' => $now + 3600,
            ])),
        ];

        $key = openssl_pkey_get_private((string) $credentials['private_key']);

        if ($key === false || ! openssl_sign(implode('.', $segments), $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('could not sign the service-account assertion');
        }

        $segments[] = self::b64($signature);

        return implode('.', $segments);
    }

    /** @param  array<string, mixed>  $credentials */
    private function cacheKey(array $credentials): string
    {
        return 'push:fcm:access-token:'.sha1((string) $credentials['client_email']);
    }

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
