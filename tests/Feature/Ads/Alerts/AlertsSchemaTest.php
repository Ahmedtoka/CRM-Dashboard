<?php

use App\Models\AdsAlert;
use App\Models\AdsAlertEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Schema;

it('creates the alerts tables with the lifecycle columns', function () {
    expect(Schema::hasColumns('ads_alerts', [
        'kind', 'rule_id', 'entity_level', 'entity_id', 'ad_account_id', 'ad_id', 'product_id', 'buyer_id', 'family', 'severity',
        'action', 'state', 'fingerprint', 'dedupe_key', 'sentence_key', 'params', 'evidence', 'money_at_risk_per_day',
        'first_fired_at', 'last_evaluated_at', 'snoozed_until', 'cooldown_until', 'dismiss_reason', 'dismiss_note',
        'resolved_reason', 'write_action_id', 'closed_by_id', 'closed_at', 'seen_at', 'seen_by_id', 'notified_at',
    ]))->toBeTrue()
        ->and(Schema::hasColumns('ads_alert_events', ['ads_alert_id', 'event', 'user_id', 'data', 'created_at']))->toBeTrue();
});

it('allows one live row per dedupe key and a new row once the old one is closed', function () {
    $a = AdsAlert::factory()->create();

    expect(fn () => AdsAlert::factory()->create(['ad_id' => $a->ad_id, 'rule_id' => $a->rule_id]))
        ->toThrow(UniqueConstraintViolationException::class);

    $a->update(['dedupe_key' => null, 'state' => AdsAlert::RESOLVED, 'closed_at' => now()]);

    expect(AdsAlert::factory()->create(['ad_id' => $a->ad_id, 'rule_id' => $a->rule_id])->fingerprint)->toBe($a->fingerprint);
});

it('casts json and keeps events', function () {
    $a = AdsAlert::factory()->create(['params' => ['spend' => 1200], 'evidence' => ['window' => ['from' => '2026-09-22']]]);
    AdsAlertEvent::create(['ads_alert_id' => $a->id, 'event' => 'fired', 'data' => ['severity' => 'high']]);

    expect($a->fresh()->params)->toBe(['spend' => 1200])
        ->and($a->events()->sole()->data)->toBe(['severity' => 'high'])
        ->and($a->isLive())->toBeTrue();
});

it('rolls back and forward, and a second up is a no-op', function () {
    $migration = require database_path('migrations/2026_10_08_500010_create_ads_alerts_tables.php');
    $migration->down();
    expect(Schema::hasTable('ads_alerts'))->toBeFalse()->and(Schema::hasTable('ads_alert_events'))->toBeFalse();

    $migration->up();
    $migration->up();
    expect(Schema::hasTable('ads_alerts'))->toBeTrue()->and(Schema::hasTable('ads_alert_events'))->toBeTrue();
});
