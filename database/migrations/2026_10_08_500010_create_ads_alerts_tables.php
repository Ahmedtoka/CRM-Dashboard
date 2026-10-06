<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The decisions feed (control room S5): one row per finding of a rule on an entity. `fingerprint` is permanent;
 * `dedupe_key` equals it only while the row is open or snoozed (unique), so a closed fingerprint can fire again after its
 * cooldown. Events are the lifecycle trail; evidence refreshes are not events.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ads_alerts')) {
            Schema::create('ads_alerts', function (Blueprint $t) {
                $t->id();
                $t->string('kind', 16)->default('alert');
                $t->string('rule_id', 48);
                $t->string('entity_level', 10);
                $t->unsignedBigInteger('entity_id');
                $t->foreignId('ad_account_id')->nullable()->constrained('ad_accounts')->cascadeOnDelete();
                $t->foreignId('ad_id')->nullable()->constrained('ads')->cascadeOnDelete();
                $t->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
                $t->foreignId('buyer_id')->nullable()->constrained('media_buyers')->nullOnDelete();
                $t->string('family', 12)->nullable();
                $t->string('severity', 10);
                $t->string('action', 24);
                $t->string('state', 12)->default('open');
                $t->string('fingerprint', 191);
                $t->string('dedupe_key', 191)->nullable()->unique();
                $t->string('sentence_key', 80);
                $t->json('params');
                $t->json('evidence');
                $t->decimal('money_at_risk_per_day', 14, 2)->default(0);
                $t->timestamp('first_fired_at')->nullable();
                $t->timestamp('last_evaluated_at')->nullable();
                $t->timestamp('snoozed_until')->nullable();
                $t->timestamp('cooldown_until')->nullable();
                $t->string('dismiss_reason', 24)->nullable();
                $t->string('dismiss_note', 500)->nullable();
                $t->string('resolved_reason', 40)->nullable();
                $t->foreignId('write_action_id')->nullable()->constrained('ad_write_actions')->nullOnDelete();
                $t->foreignId('closed_by_id')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamp('closed_at')->nullable();
                $t->timestamp('seen_at')->nullable();
                $t->foreignId('seen_by_id')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamp('notified_at')->nullable();
                $t->timestamps();
                $t->index(['ad_account_id', 'state']);
                $t->index(['ad_id', 'state']);
                $t->index(['fingerprint', 'closed_at']);
                $t->index(['state', 'severity']);
                $t->index(['buyer_id', 'state']);
            });
        }

        if (! Schema::hasTable('ads_alert_events')) {
            Schema::create('ads_alert_events', function (Blueprint $t) {
                $t->id();
                $t->foreignId('ads_alert_id')->constrained('ads_alerts')->cascadeOnDelete();
                $t->string('event', 16);
                $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $t->json('data')->nullable();
                $t->timestamp('created_at')->nullable()->useCurrent();
                $t->index(['ads_alert_id', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ads_alert_events');
        Schema::dropIfExists('ads_alerts');
    }
};
