<?php

namespace App\Shopify\App;

use Carbon\CarbonImmutable;

/**
 * The answer of POST /admin/oauth/access_token (token exchange or refresh). An expiring offline
 * token lives one hour (`expires_in`), its refresh token 90 days (`refresh_token_expires_in`);
 * a non-expiring answer has neither.
 */
final class TokenSet
{
    /**
     * @param  list<string>|null  $scopes  null when the answer carried no `scope`
     */
    public function __construct(
        public readonly string $accessToken,
        public readonly ?CarbonImmutable $accessTokenExpiresAt,
        public readonly ?string $refreshToken,
        public readonly ?CarbonImmutable $refreshTokenExpiresAt,
        public readonly ?array $scopes,
    ) {}

    /** @param  array<string, mixed>  $json */
    public static function fromResponse(array $json): self
    {
        $now = CarbonImmutable::now();
        $scope = $json['scope'] ?? null;

        return new self(
            (string) $json['access_token'],
            is_numeric($json['expires_in'] ?? null) ? $now->addSeconds((int) $json['expires_in']) : null,
            filled($json['refresh_token'] ?? null) ? (string) $json['refresh_token'] : null,
            is_numeric($json['refresh_token_expires_in'] ?? null) ? $now->addSeconds((int) $json['refresh_token_expires_in']) : null,
            is_string($scope) ? array_values(array_filter(array_map('trim', explode(',', $scope)))) : null,
        );
    }
}
