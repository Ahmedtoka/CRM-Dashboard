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
 * Firebase Cloud Messaging HTTP v1 (spec D8). Authenticates with the service account
 * from `crm.push.fcm.credentials` (a JSON key file kept outside the web root): a JWT
 * signed RS256 with openssl is exchanged for an OAuth2 access token, cached 50 minutes
 * (Google issues them for 60). No SDK dependency.
 *
 * A token FCM reports as gone (404 / UNREGISTERED, or INVALID_ARGUMENT about the
 * token) comes back as `invalidToken` so PushManager disables the device.
 */
final class FcmPushSender implements PushSender
{
    public const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    private const TOKEN_TTL_SECONDS = 3000;

    public function send(DeviceToken $device, PushMessage $message): PushResult
    {
        $project = (string) config('crm.push.fcm.project_id');

        try {
            $credentials = $this->credentials();
            $accessToken = $this->accessToken($credentials);
        } catch (RuntimeException|ConnectionException $e) {
            return PushResult::failed('fcm_auth: '.$e->getMessage());
        }

        if ($project === '') {
            $project = (string) ($credentials['project_id'] ?? '');
        }

        if ($project === '') {
            return PushResult::failed('fcm_not_configured');
        }

        try {
            $response = Http::withToken($accessToken)->acceptJson()->timeout(5)->connectTimeout(3)
                ->post("https://fcm.googleapis.com/v1/projects/{$project}/messages:send", ['message' => $this->payload($device, $message)]);
        } catch (ConnectionException $e) {
            return PushResult::failed('fcm_connection');
        }

        if ($response->successful()) {
            return PushResult::sent();
        }

        if ($response->status() === 401) {
            Cache::forget($this->cacheKey($credentials));
        }

        return PushResult::failed('fcm_'.$response->status().($this->errorCode($response) ? ':'.$this->errorCode($response) : ''), $this->isInvalidToken($response));
    }

    /** @return array<string, mixed> */
    private function payload(DeviceToken $device, PushMessage $message): array
    {
        $high = $message->priority === 'high';
        $android = [
            'priority' => $high ? 'HIGH' : 'NORMAL',
            'notification' => ['channel_id' => $message->channel],
        ];

        if ($message->collapseKey !== null) {
            $android['collapse_key'] = $message->collapseKey;
        }

        return [
            'token' => $device->token,
            'notification' => ['title' => $message->title, 'body' => $message->body],
            'data' => array_map('strval', $message->data),
            'android' => $android,
            'apns' => ['headers' => ['apns-priority' => $high ? '10' : '5']],
        ];
    }

    private function isInvalidToken(Response $response): bool
    {
        $code = $this->errorCode($response);

        return $response->status() === 404
            || $code === 'UNREGISTERED'
            || ($response->status() === 400 && ($code === 'INVALID_ARGUMENT' || $response->json('error.status') === 'INVALID_ARGUMENT'));
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

    /** @return array{client_email: string, private_key: string, token_uri?: string, project_id?: string} */
    private function credentials(): array
    {
        $path = (string) config('crm.push.fcm.credentials');

        if ($path === '' || ! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('credentials file missing');
        }

        $json = json_decode((string) file_get_contents($path), true);

        if (! is_array($json) || empty($json['client_email']) || empty($json['private_key'])) {
            throw new RuntimeException('credentials file invalid');
        }

        return $json;
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
