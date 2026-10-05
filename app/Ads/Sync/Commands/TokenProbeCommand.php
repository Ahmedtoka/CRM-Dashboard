<?php

namespace App\Ads\Sync\Commands;

use App\Ads\AdsSettings;
use App\Ads\Audit\AdsAudit;
use App\Ads\Platforms\Meta\TokenInspector;
use App\Ads\Platforms\SecretScrubber;
use App\Ads\Sync\ConnectionHealth;
use App\Inbox\UserNotifier;
use App\Models\AdPlatformConnection;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Client\RequestException;
use Throwable;

/**
 * Daily GET-only health probe of every Meta connection token: validity, scopes, expiry (A7b). Stores the answer on the
 * connection (never the token). A token without ads_management makes the connection read-only (the CRM refuses writes on
 * it); an expiry or data-access expiry within 7 days notifies the admins once a day.
 */
class TokenProbeCommand extends Command
{
    protected $signature = 'ads:token-probe';

    protected $description = 'Check each Meta token (valid, scopes, expiry) and keep connection read-only and expiry notices in step';

    public const EXPIRY_WARN_DAYS = 7;

    public function handle(TokenInspector $inspector, AdsSettings $settings, UserNotifier $notifier): int
    {
        $connections = AdPlatformConnection::query()->where('platform', 'meta')->where('status', '<>', 'disabled')->orderBy('id')->get();
        if ($connections->isEmpty()) {
            $this->line('No Meta connections.');

            return self::SUCCESS;
        }

        foreach ($connections as $c) {
            $label = "#{$c->id} {$c->name}";
            try {
                $token = (string) ($c->credentials['access_token'] ?? '');
            } catch (DecryptException) {
                $this->warn("{$label}: credentials unreadable, re-enter the token");

                continue;
            }
            if ($token === '') {
                $this->warn("{$label}: no access token stored");

                continue;
            }

            try {
                $info = $inspector->inspect($token);
            } catch (Throwable $e) {
                if ($e instanceof RequestException && (int) $e->response->json('error.code') === 190) {
                    AdPlatformConnection::whereKey($c->id)->update(['token_valid' => false, 'token_checked_at' => now()]);
                    ConnectionHealth::markNeedsReconnect($c, 'Meta rejected the token (190)');
                    $this->warn("{$label} [".AdsAudit::fingerprint($token).']: rejected by Meta, needs reconnect');
                } else {
                    $this->warn("{$label}: probe failed ".mb_substr(SecretScrubber::scrub($e->getMessage(), [$token, (string) config('crm.meta.app_secret')]), 0, 200));
                }

                continue;
            }

            $valid = $info['valid'] ?? true;
            AdPlatformConnection::whereKey($c->id)->update([
                'token_valid' => $valid,
                'token_type' => $info['type'],
                'token_scopes' => json_encode($info['scopes']),
                'token_expires_at' => $info['expires_at'],
                'data_access_expires_at' => $info['data_access_expires_at'],
                'token_checked_at' => now(),
            ]);
            $c->refresh();
            $this->line("{$label} [".AdsAudit::fingerprint($token).']: '.($valid ? 'valid' : 'INVALID').', scopes '.implode(',', $info['scopes']));

            if (! $valid) {
                ConnectionHealth::markNeedsReconnect($c, 'Meta reports the token as not valid');

                continue;
            }

            $this->syncReadOnly($c, in_array('ads_management', $info['scopes'], true), $notifier);
            $this->warnExpiry($c, $settings, $notifier);
        }

        return self::SUCCESS;
    }

    /** CAS on read_only: only the call that flips it audits and notifies. */
    private function syncReadOnly(AdPlatformConnection $c, bool $canManage, UserNotifier $notifier): void
    {
        if (! $canManage) {
            $flipped = AdPlatformConnection::whereKey($c->id)->where(fn ($q) => $q->where('read_only', false)->orWhereNull('read_only'))->update(['read_only' => true]) === 1;
            if ($flipped) {
                $notifier->notifyAdmins('ads.token_scope_missing', ['connection' => $c->name, 'connection_id' => $c->id]);
                AdsAudit::record('connection.read_only', $c, ['read_only' => false], ['read_only' => true], ['scopes' => $c->token_scopes]);
            }

            return;
        }

        if (AdPlatformConnection::whereKey($c->id)->where('read_only', true)->update(['read_only' => false]) === 1) {
            AdsAudit::record('connection.read_write', $c, ['read_only' => true], ['read_only' => false], ['scopes' => $c->token_scopes]);
        }
    }

    /** At most one notice a day per connection, tracked by a dated mark in ads_settings (the cache can be wiped). */
    private function warnExpiry(AdPlatformConnection $c, AdsSettings $settings, UserNotifier $notifier): void
    {
        $dates = array_filter([$c->token_expires_at, $c->data_access_expires_at]);
        if ($dates === []) {
            return;
        }
        $first = CarbonImmutable::instance(min($dates));
        if ($first->greaterThan(now()->addDays(self::EXPIRY_WARN_DAYS))) {
            return;
        }

        $today = now('Africa/Cairo')->toDateString();
        $key = "token_expiring_notified:{$c->id}";
        if ($settings->get($key) === $today) {
            return;
        }
        $settings->set($key, $today);
        $notifier->notifyAdmins('ads.token_expiring', [
            'connection' => $c->name, 'connection_id' => $c->id, 'expires_at' => $first->toIso8601String(),
            'days' => max(0, (int) ceil(now()->diffInHours($first, false) / 24)),
        ]);
    }
}
