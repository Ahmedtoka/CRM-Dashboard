<?php

namespace App\Maintenance;

use App\Ads\Attribution\AttributionBackup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The data side of `crm:fresh-start` (spec 2026-10-06 F2): which tables are emptied, which are kept, and the wipe.
 *
 * Rows are removed with chunked DELETEs (never TRUNCATE) so MariaDB foreign keys keep working, children before
 * parents, after the nullable links that form cycles are set to NULL. Every step is safe to repeat: a second run on
 * empty tables deletes nothing. Tables that do not exist (yet, or any more) are skipped.
 */
final class FreshStart
{
    /**
     * Emptied, in this order (children first). Grouped as F2 lists them.
     *
     * @var list<string>
     */
    public const WIPE = [
        // Ads data: write actions/steps, alerts, launches, publications, material links, metrics, sync logs, audit.
        'ad_write_steps', 'ads_alert_events', 'ads_alerts', 'ad_publications', 'ad_launches', 'ad_write_actions', 'ad_actions',
        'ad_material_ads', 'ad_daily_metrics', 'ad_account_daily', 'ad_spend_snapshots', 'ads_sync_runs', 'ads_api_usage', 'ads_audit_log',
        // Conversation outcomes, cases and the queue (entries carry the ratings; days/shifts are the windows).
        'conversation_outcomes', 'support_cases', 'queue_decisions', 'queue_attendance_events', 'queue_entries', 'shift_members', 'shifts', 'queue_days',
        // Shopify orders.
        'shipment_events', 'shipments', 'fulfillments', 'refunds', 'order_items', 'orders',
        // Conversations and everything hanging off them.
        'message_attachments', 'messages', 'conversation_notes', 'conversation_tag', 'conversation_participants', 'conversation_ad_referrals',
        'bot_learning_notes', 'bot_runs', 'activity_logs', 'conversations',
        // Customers.
        'customer_merge_suggestions', 'customer_addresses', 'customer_identities', 'customers',
        // Ads structure (after orders/alerts that point at it).
        'ads', 'ad_sets', 'ad_campaigns',
        // Reports and notifications.
        'user_notifications', 'analytics_daily', 'latency_samples',
        // Owner ruling 2026-10-06 (wipe all data): comments before their posts, suggestions before their reports,
        // steps before their test sessions; logs and health state.
        'comments', 'posts', 'bot_suggestions', 'bot_learning_reports', 'bot_test_session_steps', 'bot_test_sessions',
        'quick_reply_usages', 'webhook_events', 'shopify_sync_runs', 'ads_health_state',
    ];

    /**
     * Never touched.
     *
     * @var list<string>
     */
    public const KEEP = [
        'users', 'user_platforms', 'password_reset_tokens', 'personal_access_tokens',
        'bot_settings', 'queue_settings', 'ads_settings', 'shopify_integrations', 'shopify_webhook_subscriptions',
        'bot_flows', 'bot_flow_versions', 'bot_intents', 'bot_rules', 'bot_knowledge_entries', 'bot_translations', 'bot_test_links',
        'channel_accounts',
        'ad_platform_connections', 'ad_accounts', 'media_buyers', 'ad_account_assignments', 'buyer_targets',
        'products', 'product_variants',
        'ad_materials', 'ad_material_files', 'ad_material_captions', 'ad_material_collections', 'ad_material_collection',
        'quick_replies', 'quick_reply_attachments', 'quick_reply_categories',
        'tags', 'cities', 'shipping_zones', 'shipping_zone_regions', 'shipping_rates', 'branches',
        'data_deletion_requests',
        'migrations', 'cache', 'cache_locks', 'sessions', 'jobs', 'job_batches', 'failed_jobs',
        // Active staff logins/presence.
        'user_sessions',
    ];

    /** Nullable links that form cycles (or point inside their own table): set to NULL before any delete. */
    private const UNLINK = [
        'conversations' => ['queue_entry_id'],
        'queue_entries' => ['support_case_id', 'open_case_id', 'reopened_from_entry_id'],
        'support_cases' => ['queue_entry_id'],
        'ad_write_actions' => ['superseded_by_id', 'rolled_back_by_id', 'rollback_of_id'],
    ];

    /** History markers of kept ad accounts that describe the wiped history. */
    private const AD_ACCOUNT_RESET = ['complete_from', 'chat_complete_from', 'last_synced_at'];

    /** @var array<string, bool> */
    private array $hasId = [];

    /**
     * Row counts of the WIPE and KEEP tables that exist (plus attribution backup tables).
     *
     * @return array<string, int>
     */
    public function counts(): array
    {
        $counts = [];
        foreach ([...self::WIPE, ...self::KEEP, ...$this->attributionBackups()] as $table) {
            if (Schema::hasTable($table)) {
                $counts[$table] = (int) DB::table($table)->count();
            }
        }

        return $counts;
    }

    /**
     * Empties every WIPE table, removes the media files of deleted messages, drops the attribution backups of the
     * deleted orders and resets the kept ad accounts' history markers.
     *
     * @param  (callable(string, int): void)|null  $progress  called after each table with the rows deleted
     * @return array{deleted: array<string, int>, files: int, dropped: list<string>}
     */
    public function wipe(?callable $progress = null): array
    {
        $chunk = max(1, (int) config('crm.fresh_start.chunk', 1000));

        foreach (self::UNLINK as $table => $columns) {
            $columns = array_values(array_filter($columns, fn ($c) => Schema::hasTable($table) && Schema::hasColumn($table, $c)));
            foreach ($columns as $column) {
                DB::table($table)->whereNotNull($column)->update([$column => null]);
            }
        }

        $files = Schema::hasTable('message_attachments') ? $this->deleteMessageMedia($chunk) : 0;

        $deleted = [];
        foreach (self::WIPE as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $deleted[$table] = $this->deleteAll($table, $chunk);
            if ($progress !== null) {
                $progress($table, $deleted[$table]);
            }
        }

        $dropped = [];
        foreach ($this->attributionBackups() as $table) {
            Schema::dropIfExists($table);
            $dropped[] = $table;
        }

        if (Schema::hasTable('ad_accounts')) {
            $reset = array_values(array_filter(self::AD_ACCOUNT_RESET, fn ($c) => Schema::hasColumn('ad_accounts', $c)));
            if ($reset !== []) {
                DB::table('ad_accounts')->update(array_fill_keys($reset, null));
            }
        }

        // Usage counters of the kept saved replies describe the wiped conversations.
        if (Schema::hasTable('quick_replies')) {
            $columns = array_intersect_key(['use_count' => 0, 'last_used_at' => null], array_flip(array_filter(['use_count', 'last_used_at'], fn ($c) => Schema::hasColumn('quick_replies', $c))));
            if ($columns !== []) {
                DB::table('quick_replies')->update($columns);
            }
        }

        return ['deleted' => $deleted, 'files' => $files, 'dropped' => $dropped];
    }

    /** @return list<string> */
    public function attributionBackups(): array
    {
        return array_values(array_filter(
            array_map(fn ($t) => (string) ($t['name'] ?? ''), Schema::getTables()),
            fn ($name) => preg_match(AttributionBackup::PATTERN, $name) === 1,
        ));
    }

    private function deleteAll(string $table, int $chunk): int
    {
        $this->hasId[$table] ??= Schema::hasColumn($table, 'id');
        $total = 0;

        if ($this->hasId[$table]) {
            while (($ids = DB::table($table)->orderBy('id')->limit($chunk)->pluck('id'))->isNotEmpty()) {
                $total += DB::table($table)->whereIn('id', $ids->all())->delete();
            }

            return $total;
        }

        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $quoted = DB::getQueryGrammar()->wrapTable($table);
            do {
                $n = DB::affectingStatement("DELETE FROM {$quoted} LIMIT {$chunk}");
                $total += $n;
            } while ($n > 0);

            return $total;
        }

        return DB::table($table)->delete();
    }

    /**
     * Deletes the stored files (and thumbnails) of every message attachment. A path that a kept saved reply or
     * material file also uses on the same disk is left alone.
     */
    private function deleteMessageMedia(int $chunk): int
    {
        $kept = [];
        foreach (['quick_reply_attachments' => ['path'], 'ad_material_files' => ['path', 'thumb_path']] as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach (DB::table($table)->get(['disk', ...$columns]) as $row) {
                foreach ($columns as $column) {
                    if ($row->{$column} !== null) {
                        $kept[$row->disk."\0".$row->{$column}] = true;
                    }
                }
            }
        }

        $deleted = 0;
        DB::table('message_attachments')->select(['id', 'disk', 'path', 'thumb_path'])
            ->where(fn ($q) => $q->whereNotNull('path')->orWhereNotNull('thumb_path'))
            ->orderBy('id')
            ->chunkById($chunk, function ($rows) use (&$deleted, $kept) {
                foreach ($rows as $row) {
                    $disk = (string) ($row->disk ?: config('crm.media.disk', 'media'));
                    foreach ([$row->path, $row->thumb_path] as $path) {
                        if ($path === null || $path === '' || isset($kept[$disk."\0".$path])) {
                            continue;
                        }
                        try {
                            $storage = Storage::disk($disk);
                            if ($storage->exists($path) && $storage->delete($path)) {
                                $deleted++;
                            }
                        } catch (Throwable) {
                            // A missing disk or file must not stop the wipe; the row goes either way.
                        }
                    }
                }
            });

        return $deleted;
    }
}
