<?php

use App\Console\Commands\FreshStartCommand;
use App\Maintenance\FreshStart;
use App\Models\ActivityLog;
use App\Models\Ad;
use App\Models\AdAccount;
use App\Models\AdCampaign;
use App\Models\AdDailyMetric;
use App\Models\AdMaterial;
use App\Models\AdMaterialFile;
use App\Models\AdsAlert;
use App\Models\AdSet;
use App\Models\AdsSyncRun;
use App\Models\AdWriteAction;
use App\Models\AnalyticsDaily;
use App\Models\BotRun;
use App\Models\ChannelAccount;
use App\Models\City;
use App\Models\Conversation;
use App\Models\ConversationNote;
use App\Models\ConversationParticipant;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\CustomerIdentity;
use App\Models\CustomerMergeSuggestion;
use App\Models\Fulfillment;
use App\Models\MediaBuyer;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\QueueEntry;
use App\Models\QuickReply;
use App\Models\QuickReplyAttachment;
use App\Models\Refund;
use App\Models\Shift;
use App\Models\ShiftMember;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Models\SupportCase;
use App\Models\Tag;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Queue::fake();
    Storage::fake('media');
    Storage::fake('public');
    $this->dir = storage_path('framework/testing/fresh-start-'.getmypid().'-'.random_int(1000, 999999));
    config(['crm.fresh_start.backup_dir' => $this->dir, 'crm.data_floor' => '2026-10-01', 'crm.fresh_start.chunk' => 2]);
});

afterEach(function () {
    File::deleteDirectory($this->dir);
});

/** At least one row in (nearly) every WIPE table and in the main KEEP tables. */
function fsSeed(): array
{
    $now = now();
    $user = User::factory()->create();
    $channel = ChannelAccount::factory()->create();
    $tag = Tag::factory()->create();
    $product = Product::factory()->create();
    $variant = ProductVariant::factory()->create(['product_id' => $product->id]);
    City::factory()->create();
    $reply = QuickReply::factory()->create();
    $replyFile = QuickReplyAttachment::factory()->create(['quick_reply_id' => $reply->id]);
    Storage::disk('media')->put($replyFile->path, 'reply');

    $buyer = MediaBuyer::factory()->create();
    $account = AdAccount::factory()->meta()->create(['complete_from' => '2026-09-01', 'last_synced_at' => $now]);
    $campaign = AdCampaign::factory()->create(['ad_account_id' => $account->id]);
    $set = AdSet::factory()->create(['ad_campaign_id' => $campaign->id]);
    $ad = Ad::factory()->create(['ad_account_id' => $account->id, 'ad_campaign_id' => $campaign->id, 'ad_set_id' => $set->id]);
    AdDailyMetric::factory()->create(['ad_id' => $ad->id, 'ad_account_id' => $account->id]);
    $material = AdMaterial::factory()->create(['media_buyer_id' => $buyer->id, 'product_id' => $product->id]);
    $materialFile = AdMaterialFile::factory()->create(['ad_material_id' => $material->id]);
    DB::table('ad_material_ads')->insert(['ad_id' => $ad->id, 'ad_material_id' => $material->id]);
    DB::table('ad_account_daily')->insert(['ad_account_id' => $account->id, 'date' => '2026-09-20']);
    DB::table('ad_spend_snapshots')->insert(['ad_account_id' => $account->id, 'date' => '2026-09-20', 'hour' => 3, 'captured_at' => $now]);
    DB::table('ads_api_usage')->insert(['header' => 'x', 'recorded_at' => $now, 'ad_account_id' => $account->id]);
    DB::table('ads_audit_log')->insert(['at' => $now, 'actor_type' => 'system', 'action' => 'ads.test', 'ad_account_id' => $account->id]);
    AdsSyncRun::factory()->create(['ad_account_id' => $account->id]);
    $write = AdWriteAction::factory()->create(['ad_account_id' => $account->id]);
    $write2 = AdWriteAction::factory()->create(['ad_account_id' => $account->id, 'rollback_of_id' => $write->id]);
    $write->update(['rolled_back_by_id' => $write2->id]);
    DB::table('ad_write_steps')->insert(['ad_write_action_id' => $write->id, 'seq' => 1, 'op' => 'pause', 'level' => 'ad', 'target_external_id' => 'x', 'state' => 'done']);
    $alert = AdsAlert::factory()->create(['ad_account_id' => $account->id, 'ad_id' => $ad->id]);
    DB::table('ads_alert_events')->insert(['ads_alert_id' => $alert->id, 'event' => 'opened']);
    $launch = DB::table('ad_launches')->insertGetId(['public_id' => 'L1', 'file_ids' => '[]', 'captions' => '[]', 'ad_material_id' => $material->id]);
    DB::table('ad_publications')->insert(['platform' => 'meta', 'campaign_external_id' => 'c', 'adset_external_id' => 's', 'headline' => 'h', 'primary_text' => 't', 'cta' => 'SHOP', 'ad_name' => 'n', 'link' => 'l', 'url_tags' => 'u', 'ad_launch_id' => $launch, 'ad_material_id' => $material->id, 'ad_material_file_id' => $materialFile->id]);
    DB::table('ad_actions')->insert(['platform' => 'meta', 'level' => 'ad', 'external_id' => 'x', 'to_status' => 'PAUSED', 'result' => 'ok', 'ad_account_id' => $account->id]);

    $customer = Customer::factory()->create();
    CustomerAddress::factory()->create(['customer_id' => $customer->id]);
    CustomerIdentity::factory()->create(['customer_id' => $customer->id]);
    CustomerMergeSuggestion::factory()->create(['customer_id' => $customer->id]);
    $conversation = Conversation::factory()->create(['customer_id' => $customer->id, 'channel_account_id' => $channel->id]);
    $message = Message::factory()->create(['conversation_id' => $conversation->id]);
    $attachment = MessageAttachment::factory()->stored()->create(['message_id' => $message->id, 'thumb_path' => 'thumbs/t1.jpg']);
    Storage::disk('media')->put($attachment->path, 'png');
    Storage::disk('media')->put('thumbs/t1.jpg', 'jpg');
    ConversationNote::factory()->create(['conversation_id' => $conversation->id]);
    ConversationParticipant::factory()->create(['conversation_id' => $conversation->id]);
    DB::table('conversation_tag')->insert(['conversation_id' => $conversation->id, 'tag_id' => $tag->id]);
    DB::table('conversation_ad_referrals')->insert(['conversation_id' => $conversation->id, 'ad_external_id' => 'x', 'referred_at' => $now]);
    DB::table('bot_learning_notes')->insert(['conversation_id' => $conversation->id, 'last_message_id' => $message->id, 'notes' => 'n']);
    BotRun::factory()->create(['conversation_id' => $conversation->id]);
    ActivityLog::factory()->create(['conversation_id' => $conversation->id]);

    $order = Order::factory()->create(['customer_id' => $customer->id, 'conversation_id' => $conversation->id, 'ad_id' => $ad->id]);
    OrderItem::factory()->create(['order_id' => $order->id, 'variant_id' => $variant->id]);
    Fulfillment::factory()->create(['order_id' => $order->id]);
    Refund::factory()->create(['order_id' => $order->id]);
    $shipment = Shipment::factory()->create(['order_id' => $order->id]);
    ShipmentEvent::factory()->create(['shipment_id' => $shipment->id]);

    $shift = Shift::factory()->create();
    ShiftMember::factory()->create(['shift_id' => $shift->id, 'user_id' => $user->id]);
    $entry = QueueEntry::factory()->create(['conversation_id' => $conversation->id, 'customer_id' => $customer->id]);
    $case = SupportCase::factory()->create(['conversation_id' => $conversation->id, 'queue_entry_id' => $entry->id, 'order_id' => $order->id]);
    $entry->update(['support_case_id' => $case->id]);
    $conversation->update(['queue_entry_id' => $entry->id]);
    DB::table('conversation_outcomes')->insert(['conversation_id' => $conversation->id, 'episode_key' => 'e', 'outcome' => 'ordered', 'source' => 'agent', 'set_at' => $now, 'order_id' => $order->id, 'queue_entry_id' => $entry->id]);
    DB::table('queue_days')->insert(['date' => '2026-09-20']);
    DB::table('queue_decisions')->insert(['trigger' => 't', 'lines' => '[]', 'shift_id' => $shift->id]);
    DB::table('queue_attendance_events')->insert(['user_id' => $user->id, 'shift_id' => $shift->id, 'business_date' => '2026-09-20', 'event' => 'in', 'at' => $now]);
    DB::table('latency_samples')->insert(['kind' => 'k', 'ref' => 'r', 'started_at' => $now, 'ended_at' => $now, 'duration_ms' => 5]);
    UserNotification::factory()->create(['user_id' => $user->id]);
    AnalyticsDaily::factory()->create(['user_id' => $user->id]);

    return compact('account', 'material', 'materialFile', 'replyFile', 'attachment');
}

function fsAttributionBackupTable(): void
{
    Schema::create('orders_ad_attr_backup_20260920101010', function ($t) {
        $t->unsignedBigInteger('id')->primary();
        $t->unsignedBigInteger('ad_id')->nullable();
    });
    DB::table('orders_ad_attr_backup_20260920101010')->insert(['id' => 1, 'ad_id' => 2]);
}

function fsRun($test, array $options = [])
{
    return $test->artisan('crm:fresh-start', $options + ['--confirm' => FreshStartCommand::PHRASE]);
}

it('classifies every table in the schema as WIPE or KEEP', function () {
    $tables = collect(Schema::getTables())->pluck('name')
        ->reject(fn ($t) => str_starts_with($t, 'sqlite_'))->values();

    expect(array_intersect(FreshStart::WIPE, FreshStart::KEEP))->toBe([])
        ->and($tables->diff([...FreshStart::WIPE, ...FreshStart::KEEP])->values()->all())->toBe([]);
});

it('empties every WIPE table, keeps every KEEP table and prints the next steps without running them', function () {
    $w = fsSeed();
    fsAttributionBackupTable();
    $keepBefore = collect(FreshStart::KEEP)->filter(fn ($t) => Schema::hasTable($t))->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()]);
    $wipeSeeded = collect(FreshStart::WIPE)->filter(fn ($t) => Schema::hasTable($t) && DB::table($t)->count() > 0)->values();

    fsRun($this)
        ->expectsOutputToContain('Backup OK')
        ->expectsOutputToContain('php artisan ads:backfill --from=2026-10-01 --queue')
        ->expectsOutputToContain('php artisan shopify:reconcile-counts --from=2026-10-01 --fix')
        ->assertSuccessful();

    expect($wipeSeeded->count())->toBeGreaterThanOrEqual(40);
    foreach (FreshStart::WIPE as $table) {
        if (Schema::hasTable($table)) {
            expect(DB::table($table)->count())->toBe(0, "{$table} should be empty");
        }
    }
    foreach ($keepBefore as $table => $n) {
        expect(DB::table($table)->count())->toBe($n, "{$table} should keep its rows");
    }
    expect(Schema::hasTable('orders_ad_attr_backup_20260920101010'))->toBeFalse()
        ->and(Storage::disk('media')->exists($w['attachment']->path))->toBeFalse()
        ->and(Storage::disk('media')->exists('thumbs/t1.jpg'))->toBeFalse()
        ->and(Storage::disk('media')->exists($w['replyFile']->path))->toBeTrue()
        ->and(AdMaterial::whereKey($w['material']->id)->exists())->toBeTrue()
        ->and(AdMaterialFile::whereKey($w['materialFile']->id)->exists())->toBeTrue()
        ->and($w['account']->fresh()->complete_from)->toBeNull()
        ->and(AdsSyncRun::count())->toBe(0)
        ->and(Queue::pushedJobs())->toBe([]);
    expect(File::glob($this->dir.'/fresh-start-*.gz'))->toHaveCount(1);
});

it('prints the per-table counts before and after', function () {
    Customer::factory()->count(3)->create();
    Tag::factory()->create();

    fsRun($this)
        ->expectsOutputToContain('Rows before → after')
        ->expectsTable(['Table', 'Rows'], collect(FreshStart::WIPE)->filter(fn ($t) => Schema::hasTable($t))->map(fn ($t) => [$t, $t === 'customers' ? 3 : 0])->values()->all())
        ->assertSuccessful();
});

it('refuses to wipe when the backup fails', function () {
    config(['crm.fresh_start.backup_driver' => 'mysqldump', 'crm.fresh_start.mysqldump_binary' => base_path('no-such-mysqldump')]);
    $customer = Customer::factory()->create();
    Conversation::factory()->create(['customer_id' => $customer->id]);

    fsRun($this)->expectsOutputToContain('No backup, no wipe')->assertFailed();

    expect(Customer::count())->toBe(1)->and(Conversation::count())->toBe(1);
});

it('requires the typed confirmation phrase', function () {
    Customer::factory()->create();

    $this->artisan('crm:fresh-start')
        ->expectsQuestion('Everything listed above will be deleted. Type «'.FreshStartCommand::PHRASE.'» to continue', 'yes')
        ->expectsOutputToContain('does not match')
        ->assertFailed();
    expect(Customer::count())->toBe(1);

    $this->artisan('crm:fresh-start', ['--confirm' => 'امسح'])->assertFailed();
    expect(Customer::count())->toBe(1);

    $this->artisan('crm:fresh-start', ['--no-interaction' => true])->expectsOutputToContain('--confirm=')->assertFailed();
    expect(Customer::count())->toBe(1);

    $this->artisan('crm:fresh-start')
        ->expectsQuestion('Everything listed above will be deleted. Type «'.FreshStartCommand::PHRASE.'» to continue', FreshStartCommand::PHRASE)
        ->assertSuccessful();
    expect(Customer::count())->toBe(0);
});

it('refuses --force alone in production and accepts it with an existing backup file', function () {
    $this->app->detectEnvironment(fn () => 'production');
    Customer::factory()->create();

    $this->artisan('crm:fresh-start', ['--force' => true])->expectsOutputToContain('--i-have-a-backup')->assertFailed();
    $this->artisan('crm:fresh-start', ['--force' => true, '--i-have-a-backup' => $this->dir.'/missing.sql.gz'])->assertFailed();
    expect(Customer::count())->toBe(1);

    File::ensureDirectoryExists($this->dir);
    file_put_contents($this->dir.'/owner.sql.gz', gzencode('-- MariaDB dump'));
    $this->artisan('crm:fresh-start', ['--force' => true, '--i-have-a-backup' => $this->dir.'/owner.sql.gz'])->assertSuccessful();
    expect(Customer::count())->toBe(0)
        ->and(File::glob($this->dir.'/fresh-start-*.gz'))->toHaveCount(1); // its own backup is still taken first
});

it('accepts --force outside production', function () {
    Customer::factory()->create();

    $this->artisan('crm:fresh-start', ['--force' => true])->assertSuccessful();

    expect(Customer::count())->toBe(0);
});

it('is idempotent: a second run succeeds and deletes nothing more', function () {
    fsSeed();
    $keep = collect(FreshStart::KEEP)->filter(fn ($t) => Schema::hasTable($t))->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()]);

    fsRun($this)->assertSuccessful();
    fsRun($this)->assertSuccessful();

    foreach (FreshStart::WIPE as $table) {
        if (Schema::hasTable($table)) {
            expect(DB::table($table)->count())->toBe(0);
        }
    }
    foreach ($keep as $table => $n) {
        expect(DB::table($table)->count())->toBe($n);
    }
});
