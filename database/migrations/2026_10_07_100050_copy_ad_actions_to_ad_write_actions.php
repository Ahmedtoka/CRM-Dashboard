<?php

use App\Ads\Control\Write\Canonical;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /** Codes the slice-1 endpoint stored in ad_actions.error when it refused BEFORE any platform call. */
    private const REFUSALS = ['level_not_allowed', 'account_not_writable', 'sandbox_only', 'fake_writer_in_production',
        'connection_needs_reconnect', 'connection_read_only', 'writes_disabled'];

    /**
     * B1: one history table. Every ad_actions row is copied into ad_write_actions as a legacy row (source=legacy,
     * idempotency_key legacy:ad_actions:{id}). Raw inserts (no model hooks), deterministic and idempotent on re-run.
     * ad_actions itself is never touched: it is frozen (read-only) and kept, never dropped.
     */
    public function up(): void
    {
        if (! Schema::hasTable('ad_actions') || ! Schema::hasTable('ad_write_actions') || ! Schema::hasTable('ad_write_steps')) {
            return;
        }

        DB::table('ad_actions')->orderBy('id')->chunkById(500, function ($rows) {
            $keys = $rows->map(fn ($r) => 'legacy:ad_actions:'.$r->id)->all();
            $done = DB::table('ad_write_actions')->where('source', 'legacy')->whereIn('idempotency_key', $keys)->pluck('idempotency_key')->flip();

            foreach ($rows as $r) {
                $key = 'legacy:ad_actions:'.$r->id;
                if (isset($done[$key])) {
                    continue;
                }
                $this->copy($r, $key);
            }
        });

        Log::info('ads.legacy_copy', [
            'ad_actions' => DB::table('ad_actions')->count(),
            'copied_legacy' => DB::table('ad_write_actions')->where('source', 'legacy')->count(),
        ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('ad_write_actions')) {
            return;
        }
        $ids = DB::table('ad_write_actions')->where('source', 'legacy')->pluck('id');
        foreach ($ids->chunk(500) as $chunk) {
            DB::table('ad_write_steps')->whereIn('ad_write_action_id', $chunk->all())->delete();
            DB::table('ad_write_actions')->whereIn('id', $chunk->all())->delete();
        }
    }

    private function copy(object $r, string $key): void
    {
        $to = in_array(strtoupper((string) $r->to_status), ['ACTIVE', 'ENABLE'], true) ? 'active' : 'paused';
        $ok = $r->result === 'ok';
        $error = $r->error !== null ? (string) $r->error : null;
        $code = $ok ? null : (in_array($error, self::REFUSALS, true) ? $error : 'platform_rejected');
        $params = ['to' => $to];
        $diff = [['path' => 'status', 'before' => $r->from_status, 'after' => $to === 'active' ? 'ACTIVE' : 'PAUSED']];

        $id = DB::table('ad_write_actions')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'type' => 'set_status',
            'state' => $ok ? 'succeeded' : 'failed',
            'platform' => $r->platform,
            'ad_account_id' => $r->ad_account_id,
            'account_name' => $r->account_name,
            'target_level' => $r->level,
            'target_external_id' => mb_substr((string) $r->external_id, 0, 100),
            'target_name' => $r->name,
            'target_key' => ($r->ad_account_id ?? 0).':'.$r->level.':'.$r->external_id,
            'from_status' => $r->from_status,
            'to_status' => $to,
            'params' => json_encode($params),
            'diff' => json_encode($diff),
            'diff_hash' => Canonical::hash([
                'type' => 'set_status', 'account_id' => $r->ad_account_id, 'level' => $r->level, 'external_id' => (string) $r->external_id,
                'params' => $params, 'diff' => $diff,
            ]),
            'reason' => $r->reason,
            'source' => 'legacy',
            'source_ref' => 'ad_actions:'.$r->id,
            'actor_type' => 'user',
            'proposed_by_id' => $r->user_id,
            'confirmed_by_id' => $r->user_id,
            'confirmed_at' => $r->created_at,
            'idempotency_key' => $key,
            'open_business_key' => null,
            'finished_at' => $r->created_at,
            'attempts' => $code !== null && $code !== 'platform_rejected' ? 0 : 1,
            'error_code' => $code,
            'error_message' => $ok ? null : $error,
            'created_at' => $r->created_at,
            'updated_at' => $r->updated_at,
        ]);

        // One step per platform mutation: a row refused before any call (a known refusal code) has none.
        if ($code === null || $code === 'platform_rejected') {
            DB::table('ad_write_steps')->insert([
                'ad_write_action_id' => $id,
                'seq' => 1,
                'op' => 'set_status',
                'level' => $r->level,
                'target_external_id' => mb_substr((string) $r->external_id, 0, 100),
                'before' => json_encode(['status' => $r->from_status]),
                'after' => json_encode(['status' => $to === 'active' ? 'ACTIVE' : 'PAUSED']),
                'state' => $ok ? 'succeeded' : 'failed',
                'request_sent_at' => null, // unknown for legacy rows
                'attempts' => 1,
                'error_code' => $code,
                'error_message' => $ok ? null : $error,
                'created_at' => $r->created_at,
                'updated_at' => $r->updated_at,
            ]);
        }
    }
};
