<?php

namespace App\Ads\Audit;

use App\Ads\Platforms\SecretScrubber;
use App\Models\AdAccount;
use App\Models\AdsAuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** Writes rows to the append-only ads_audit_log. Every string is scrubbed of credentials first. */
final class AdsAudit
{
    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     * @param  array<string, mixed>  $meta
     */
    public static function record(string $action, ?Model $subject = null, ?array $before = null, ?array $after = null, array $meta = [], ?User $actor = null): AdsAuditLog
    {
        $actor ??= auth()->user();
        $actor = $actor instanceof User ? $actor : null;

        $type = match (true) {
            $actor !== null => 'user',
            app()->runningInConsole() => 'cli',
            default => 'system',
        };

        $request = app()->bound('request') ? request() : null;
        if ($request !== null && $request->ip() !== null) { // a real HTTP request, not a bare console input
            $meta += array_filter([
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'request_id' => $request->headers->get('X-Request-Id') ?? $request->attributes->get('request_id'),
            ], fn ($v) => $v !== null);
        }

        $accountId = $subject instanceof AdAccount ? $subject->id : $subject?->getAttribute('ad_account_id');

        return AdsAuditLog::create([
            'at' => now(),
            'actor_type' => $type,
            'actor_user_id' => $actor?->id,
            'actor_role' => $actor === null ? null : ($actor->role?->value ?? (string) $actor->role),
            'action' => $action,
            'subject_type' => $subject === null ? null : class_basename($subject),
            'subject_id' => $subject?->getKey(),
            'ad_account_id' => $accountId,
            'before' => $before === null ? null : self::scrub($before),
            'after' => $after === null ? null : self::scrub($after),
            'meta' => self::scrub($meta),
        ]);
    }

    /** First 8 hex chars of sha256: the only way a token is ever identified in logs and audit rows. */
    public static function fingerprint(string $secret): string
    {
        return substr(hash('sha256', $secret), 0, 8);
    }

    /**
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    private static function scrub(array $data): array
    {
        $out = [];
        foreach ($data as $k => $v) {
            if (is_string($k) && preg_match('/token|secret|password|credential|authorization|api_key/i', $k)) {
                $out[$k] = '***';

                continue;
            }
            $out[$k] = match (true) {
                is_string($v) => SecretScrubber::scrub($v),
                is_array($v) => self::scrub($v),
                default => $v,
            };
        }

        return $out;
    }
}
