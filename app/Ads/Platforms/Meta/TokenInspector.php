<?php

namespace App\Ads\Platforms\Meta;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * Asks Meta (GET only) what a stored user token can do: debug_token with the app token when the app secret is set,
 * else me/permissions with the token itself. Shared by ads:token-probe and ads:doctor. Never stores or prints the token.
 */
class TokenInspector
{
    public function __construct(private readonly MetaAdsApi $api) {}

    /**
     * @return array{source: string, valid: ?bool, type: ?string, scopes: list<string>, expires_at: ?CarbonImmutable, data_access_expires_at: ?CarbonImmutable}
     *
     * @throws RequestException|ConnectionException on a failed call
     */
    public function inspect(string $token): array
    {
        if ($this->usesAppToken()) {
            $appId = (string) config('crm.meta.app_id');
            $secret = (string) config('crm.meta.app_secret');
            $data = (array) Http::withToken($appId.'|'.$secret)->acceptJson()->timeout(20)->connectTimeout(10)
                ->get($this->api->url('debug_token'), ['input_token' => $token])->throw()->json('data', []);
            $scopes = array_map('strval', (array) ($data['scopes'] ?? []));
            sort($scopes);

            return [
                'source' => 'debug_token',
                'valid' => (bool) ($data['is_valid'] ?? false),
                'type' => isset($data['type']) ? (string) $data['type'] : null,
                'scopes' => array_values($scopes),
                'expires_at' => $this->at($data['expires_at'] ?? null),
                'data_access_expires_at' => $this->at($data['data_access_expires_at'] ?? null),
            ];
        }

        $granted = (array) Http::withToken($token)->acceptJson()->timeout(20)->connectTimeout(10)
            ->get($this->api->url('me/permissions'))->throw()->json('data', []);
        $scopes = [];
        foreach ($granted as $p) {
            if (is_array($p) && ($p['status'] ?? '') === 'granted') {
                $scopes[] = (string) ($p['permission'] ?? '');
            }
        }
        sort($scopes);

        return ['source' => 'permissions', 'valid' => null, 'type' => null, 'scopes' => $scopes, 'expires_at' => null, 'data_access_expires_at' => null];
    }

    /** True when inspect() authenticates with the app token (debug_token); false when it uses the user token itself. */
    public function usesAppToken(): bool
    {
        return (string) config('crm.meta.app_id') !== '' && (string) config('crm.meta.app_secret') !== '';
    }

    /** Meta sends 0 (or nothing) for "never expires". */
    private function at(mixed $ts): ?CarbonImmutable
    {
        $n = (int) $ts;

        return $n > 0 ? CarbonImmutable::createFromTimestamp($n) : null;
    }
}
