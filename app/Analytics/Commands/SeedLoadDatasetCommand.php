<?php

namespace App\Analytics\Commands;

use App\Enums\ConversationPriority;
use App\Enums\ConversationStatus;
use App\Enums\Handler;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\Platform;
use App\Enums\SenderType;
use App\Models\ChannelAccount;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
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
    protected $signature = 'crm:seed-load-dataset {--conversations=50000} {--messages-per=10}';

    protected $description = 'Bulk-insert a synthetic conversations/messages dataset for staging load tests';

    private const CHUNK = 500;

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

        $conversationsCount = max(0, (int) $this->option('conversations'));
        $messagesPer = max(0, (int) $this->option('messages-per'));

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
            $now = now();

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
                    'last_message_at' => $now,
                    'last_customer_message_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
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

                for ($m = 0; $m < $messagesPer; $m++) {
                    $direction = $m % 2 === 0 ? MessageDirection::In : MessageDirection::Out;
                    $senderType = $direction === MessageDirection::In ? SenderType::Customer : SenderType::User;
                    $status = $direction === MessageDirection::In ? MessageStatus::Received : MessageStatus::Delivered;

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
                        'sent_at' => $now,
                        'delivered_at' => $direction === MessageDirection::Out ? $now : null,
                        'read_at' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }

            foreach (array_chunk($messageRows, self::CHUNK) as $chunk) {
                DB::table('messages')->insert($chunk);
            }

            $customersInserted += $batchSize;
            $identitiesInserted += $batchSize;
            $conversationsInserted += $batchSize;
            $messagesInserted += count($messageRows);

            $this->info("Progress: seeded {$conversationsInserted}/{$conversationsCount} conversations so far");
        }

        // Counts are printed on their own lines (each holding exactly one number) so
        // scripted callers can grep a single figure without parsing a combined sentence.
        $this->info("customers={$customersInserted}");
        $this->info("identities={$identitiesInserted}");
        $this->info("conversations={$conversationsInserted}");
        $this->info("messages={$messagesInserted}");

        return self::SUCCESS;
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
