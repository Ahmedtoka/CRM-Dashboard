<?php

namespace App\Analytics\Commands;

use App\Enums\ConversationPriority;
use App\Enums\ConversationStatus;
use App\Enums\Handler;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\Platform;
use App\Enums\SenderType;
use App\Enums\UserRole;
use App\Models\ChannelAccount;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Bulk-inserts a synthetic conversations/messages dataset (spec §11.3: 50,000
 * conversations / 500,000 messages) into a dedicated load-test database so
 * crm:latency-report can grade the inbox list/filter/search endpoint at scale.
 *
 * Raw chunked DB::table()->insert() calls only — no Eloquent events, no bot
 * runs, no broadcasts — so seeding a large dataset stays fast and side-effect
 * free.
 */
class SeedLoadDatasetCommand extends Command
{
    protected $signature = 'crm:seed-load-dataset {--conversations=50000} {--messages-per=10}
        {--spread-days=0 : Spread last_message_at deterministically over this many past days (0 = all now)}
        {--moderators=0 : Create N moderator users (load-mod-i@load.test, password "load-password") and give them conversations}
        {--hot=0 : Add one conversation with this many messages, an image attachment row every 12th message and a note every 10th}
        {--notes-pct=0 : Percent of conversations that get one internal note}
        {--tags=0 : Create N tags and attach one to every 5th conversation}';

    protected $description = 'Bulk-insert a synthetic conversations/messages dataset for staging load tests';

    private const CHUNK = 500;

    /** Messages dominate the volume (10 per conversation): bigger statements, fewer commits. */
    private const MESSAGE_CHUNK = 1800; // 18 columns x 1800 rows stays under the SQLite 32,766 bind-variable limit

    public function handle(): int
    {
        $environment = app()->environment();
        $database = (string) config('database.connections.'.config('database.default').'.database');

        if (! in_array($environment, ['local', 'staging'], true) || ! str_contains(strtolower($database), 'load')) {
            $this->error(sprintf(
                'Refusing to seed the load dataset: requires APP_ENV in [local, staging] (got "%s") and a database name containing "load", e.g. social_crm_load (got "%s").',
                $environment,
                $database,
            ));

            return self::FAILURE;
        }

        // App timestamps are UTC; a MySQL session on the OS zone (e.g. Cairo) rejects the local DST gap
        // hour for spread dates, so write them through a UTC session.
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("SET time_zone = '+00:00'");
        }

        $conversationsCount = max(0, (int) $this->option('conversations'));
        $messagesPer = max(0, (int) $this->option('messages-per'));
        $spread = max(0, (int) $this->option('spread-days'));
        $modIds = $this->ensureModerators(max(0, (int) $this->option('moderators')));
        $tagIds = $this->ensureTags(max(0, (int) $this->option('tags')));
        $notesPct = min(100, max(0, (int) $this->option('notes-pct')));
        $hot = max(0, (int) $this->option('hot'));

        $accounts = $this->ensureChannelAccounts();
        $platforms = Platform::cases();
        $statuses = ConversationStatus::cases();

        $customersInserted = 0;
        $identitiesInserted = 0;
        $conversationsInserted = 0;
        $messagesInserted = 0;

        $prefix = 'load-'.Str::random(8).'-';

        for ($offset = 0; $offset < $conversationsCount; $offset += self::CHUNK) {
            $batchSize = min(self::CHUNK, $conversationsCount - $offset);
            DB::beginTransaction();
            $now = now();
            $ats = [];

            $customerRows = [];

            for ($i = 0; $i < $batchSize; $i++) {
                $n = $offset + $i;
                $customerRows[] = [
                    'name' => "Load Customer {$n}",
                    'phone' => '01'.str_pad((string) ($n % 1_000_000_000), 9, '0', STR_PAD_LEFT),
                    'email' => null,
                    'city' => null,
                    'address' => null,
                    'avatar_url' => null,
                    'notes' => null,
                    'orders_count' => $n % 7,
                    'total_spent' => ($n % 50) * 25,
                    // Deterministic distribution (fix round 1, plan-mandated): is_repeat ~30%,
                    // has_open_order ~20%, has_return ~5%, has_stuck_order ~3%, via modulo like
                    // the platform/status/priority distributions above/below.
                    'is_repeat' => $n % 10 < 3,
                    'has_open_order' => $n % 10 < 2,
                    'has_return' => $n % 20 === 0,
                    'has_stuck_order' => $n % 100 < 3,
                    'last_contact_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            $customerStartId = (int) (DB::table('customers')->max('id') ?? 0) + 1;
            DB::table('customers')->insert($customerRows);

            $identityRows = [];
            $conversationRows = [];

            for ($i = 0; $i < $batchSize; $i++) {
                $n = $offset + $i;
                $customerId = $customerStartId + $i;
                $platform = $platforms[$n % count($platforms)];
                $account = $accounts[$platform->value];
                $at = $spread > 0
                    ? $now->copy()->subMinutes(($n * 7919) % ($spread * 1440))
                    : $now;
                $ats[$i] = $at;
                $mod = $modIds !== [] ? $modIds[$n % count($modIds)] : null;

                $identityRows[] = [
                    'customer_id' => $customerId,
                    'platform' => $platform->value,
                    'external_id' => $prefix.'cust-'.$n,
                    'username' => null,
                    'display_name' => "Load Customer {$n}",
                    'avatar_url' => null,
                    'spam_allowlisted' => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                $priority = match (true) {
                    $n % 20 === 0 => ConversationPriority::Spam,
                    $n % 10 === 0 => ConversationPriority::Low,
                    default => ConversationPriority::Normal,
                };

                $conversationRows[] = [
                    'customer_id' => $customerId,
                    'channel_account_id' => $account->id,
                    'platform' => $platform->value,
                    'status' => $statuses[$n % count($statuses)]->value,
                    'priority' => $priority->value,
                    'handler' => $n % 3 === 0 ? Handler::Human->value : Handler::Bot->value,
                    'needs_human' => $n % 3 === 0,
                    'source' => 'direct',
                    'unread_count' => $n % 4,
                    'first_responder_id' => $mod && $n % 3 === 0 ? $mod : null,
                    'last_responder_id' => $mod && $n % 3 === 0 ? $mod : null,
                    'assignee_id' => $mod && $n % 9 === 0 ? $mod : null,
                    'assigned_at' => $mod && $n % 9 === 0 ? $at : null,
                    'last_message_at' => $at,
                    'last_customer_message_at' => $at,
                    'created_at' => $at,
                    'updated_at' => $at,
                ];
            }

            foreach (array_chunk($identityRows, self::CHUNK) as $chunk) {
                DB::table('customer_identities')->insert($chunk);
            }

            $conversationStartId = (int) (DB::table('conversations')->max('id') ?? 0) + 1;

            foreach (array_chunk($conversationRows, self::CHUNK) as $chunk) {
                DB::table('conversations')->insert($chunk);
            }

            $messageRows = [];

            for ($i = 0; $i < $batchSize; $i++) {
                $n = $offset + $i;
                $conversationId = $conversationStartId + $i;
                $platform = $platforms[$n % count($platforms)];
                $at = $ats[$i];

                for ($m = 0; $m < $messagesPer; $m++) {
                    $direction = $m % 2 === 0 ? MessageDirection::In : MessageDirection::Out;
                    $senderType = $direction === MessageDirection::In ? SenderType::Customer : SenderType::User;
                    $status = $direction === MessageDirection::In ? MessageStatus::Received : MessageStatus::Delivered;
                    $msgAt = $at->copy()->subMinutes($messagesPer - $m);

                    $messageRows[] = [
                        'conversation_id' => $conversationId,
                        'platform' => $platform->value,
                        'direction' => $direction->value,
                        'sender_type' => $senderType->value,
                        'user_id' => null,
                        'body' => "Load test message {$n}-{$m}",
                        'attachments' => null,
                        'external_id' => $prefix.'msg-'.$n.'-'.$m,
                        'status' => $status->value,
                        'error' => null,
                        'is_template' => false,
                        'is_spam' => false,
                        'is_low_value' => false,
                        'sent_at' => $msgAt,
                        'delivered_at' => $direction === MessageDirection::Out ? $msgAt : null,
                        'read_at' => null,
                        'created_at' => $msgAt,
                        'updated_at' => $msgAt,
                    ];
                }
            }

            foreach (array_chunk($messageRows, self::MESSAGE_CHUNK) as $chunk) {
                DB::table('messages')->insert($chunk);
            }

            $noteRows = [];
            $tagRows = [];

            for ($i = 0; $i < $batchSize; $i++) {
                $n = $offset + $i;
                $conversationId = $conversationStartId + $i;

                if ($n % 100 < $notesPct) {
                    $noteRows[] = [
                        'conversation_id' => $conversationId,
                        'user_id' => $modIds !== [] ? $modIds[$n % count($modIds)] : null,
                        'body' => "Load note {$n}",
                        'created_at' => $ats[$i],
                        'updated_at' => $ats[$i],
                    ];
                }

                if ($tagIds !== [] && $n % 5 === 0) {
                    $tagRows[] = [
                        'conversation_id' => $conversationId,
                        'tag_id' => $tagIds[$n % count($tagIds)],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }

            foreach (array_chunk($noteRows, self::CHUNK) as $chunk) {
                DB::table('conversation_notes')->insert($chunk);
            }

            foreach (array_chunk($tagRows, self::CHUNK) as $chunk) {
                DB::table('conversation_tag')->insert($chunk);
            }

            DB::commit();

            $customersInserted += $batchSize;
            $identitiesInserted += $batchSize;
            $conversationsInserted += $batchSize;
            $messagesInserted += count($messageRows);

            $this->info("Progress: seeded {$conversationsInserted}/{$conversationsCount} conversations so far");
        }

        $hotId = $hot > 0 ? $this->seedHotConversation($hot, $accounts, $modIds) : null;

        // Counts are printed on their own lines (each holding exactly one number) so
        // scripted callers can grep a single figure without parsing a combined sentence.
        $this->info("customers={$customersInserted}");
        $this->info("identities={$identitiesInserted}");
        $this->info("conversations={$conversationsInserted}");
        $this->info("messages={$messagesInserted}");

        if ($hotId !== null) {
            $this->info("hot={$hotId}");
        }

        return self::SUCCESS;
    }

    /**
     * One conversation with $hot messages (alternating in/out, one minute apart, ending now), an
     * image attachment row on every 12th message and an internal note every 10th.
     *
     * @param  array<string, ChannelAccount>  $accounts
     * @param  list<int>  $modIds
     */
    private function seedHotConversation(int $hot, array $accounts, array $modIds): int
    {
        $now = now();
        $platform = Platform::cases()[0];
        $prefix = 'load-hot-'.Str::random(8).'-';

        $customerId = (int) DB::table('customers')->insertGetId([
            'name' => 'Load Hot Customer', 'phone' => '01000000000', 'orders_count' => 0, 'total_spent' => 0,
            'is_repeat' => false, 'has_open_order' => false, 'has_return' => false, 'has_stuck_order' => false,
            'last_contact_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('customer_identities')->insert([
            'customer_id' => $customerId, 'platform' => $platform->value, 'external_id' => $prefix.'cust',
            'display_name' => 'Load Hot Customer', 'spam_allowlisted' => false, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $conversationId = (int) DB::table('conversations')->insertGetId([
            'customer_id' => $customerId,
            'channel_account_id' => $accounts[$platform->value]->id,
            'platform' => $platform->value,
            'status' => ConversationStatus::Open->value,
            'priority' => ConversationPriority::Normal->value,
            'handler' => Handler::Human->value,
            'needs_human' => true,
            'source' => 'direct',
            'unread_count' => 1,
            'last_message_at' => $now, 'last_customer_message_at' => $now,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        $rows = [];
        for ($k = 0; $k < $hot; $k++) {
            $direction = $k % 2 === 0 ? MessageDirection::In : MessageDirection::Out;
            $at = $now->copy()->subMinutes($hot - 1 - $k);
            $rows[] = [
                'conversation_id' => $conversationId,
                'platform' => $platform->value,
                'direction' => $direction->value,
                'sender_type' => ($direction === MessageDirection::In ? SenderType::Customer : SenderType::User)->value,
                'user_id' => null,
                'body' => "Hot message {$k}",
                'attachments' => null,
                'external_id' => $prefix.'msg-'.$k,
                'status' => ($direction === MessageDirection::In ? MessageStatus::Received : MessageStatus::Delivered)->value,
                'error' => null,
                'is_template' => false, 'is_spam' => false, 'is_low_value' => false,
                'sent_at' => $at,
                'delivered_at' => $direction === MessageDirection::Out ? $at : null,
                'read_at' => null,
                'created_at' => $at, 'updated_at' => $at,
            ];
        }
        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            DB::table('messages')->insert($chunk);
        }

        $ids = DB::table('messages')->where('conversation_id', $conversationId)->orderBy('id')->pluck('id')->all();
        $attachments = [];
        foreach ($ids as $k => $messageId) {
            if ($k % 12 === 11) {
                $attachments[] = [
                    'message_id' => $messageId, 'type' => 'image', 'disk' => 'media', 'path' => "load/{$k}.jpg",
                    'mime' => 'image/jpeg', 'width' => 1080, 'height' => 1350, 'status' => 'stored',
                    'created_at' => $now, 'updated_at' => $now,
                ];
            }
        }
        foreach (array_chunk($attachments, self::CHUNK) as $chunk) {
            DB::table('message_attachments')->insert($chunk);
        }

        $notes = [];
        for ($j = 0; $j < intdiv($hot, 10); $j++) {
            $notes[] = [
                'conversation_id' => $conversationId,
                'user_id' => $modIds !== [] ? $modIds[$j % count($modIds)] : null,
                'body' => "Hot note {$j}", 'created_at' => $now, 'updated_at' => $now,
            ];
        }
        foreach (array_chunk($notes, self::CHUNK) as $chunk) {
            DB::table('conversation_notes')->insert($chunk);
        }

        return $conversationId;
    }

    /** @return list<int> */
    private function ensureModerators(int $count): array
    {
        $ids = [];
        for ($i = 1; $i <= $count; $i++) {
            $user = User::query()->firstOrNew(['email' => "load-mod-{$i}@load.test"]);
            $user->forceFill([
                'name' => "Load Moderator {$i}", 'role' => UserRole::Moderator,
                'is_active' => true, 'password' => Hash::make('load-password'),
            ])->save();
            $ids[] = (int) $user->id;
        }

        return $ids;
    }

    /** @return list<int> */
    private function ensureTags(int $count): array
    {
        $ids = [];
        for ($i = 1; $i <= $count; $i++) {
            $ids[] = (int) (DB::table('tags')->where('name', "load-tag-{$i}")->value('id')
                ?? DB::table('tags')->insertGetId(['name' => "load-tag-{$i}", 'color' => '#64748b', 'created_at' => now(), 'updated_at' => now()]));
        }

        return $ids;
    }

    /**
     * @return array<string, ChannelAccount>
     */
    private function ensureChannelAccounts(): array
    {
        $map = [];

        foreach (Platform::cases() as $platform) {
            $account = ChannelAccount::where('platform', $platform->value)->first();

            if ($account === null) {
                $account = ChannelAccount::create([
                    'platform' => $platform->value,
                    'name' => 'Load test '.$platform->label(),
                    'external_id' => 'loadtest-'.$platform->value,
                    'driver' => 'fake',
                    'status' => 'connected',
                ]);
            }

            $map[$platform->value] = $account;
        }

        return $map;
    }
}
