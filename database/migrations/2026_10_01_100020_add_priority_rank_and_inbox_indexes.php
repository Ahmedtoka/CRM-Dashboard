<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * UI overhaul Task 4a: a generated `priority_rank` the queue ordering (and its cursor) can page on,
 * plus the indexes the inbox list/detail queries need on a 300k-conversation table
 * (EXPLAIN notes in docs/perf/inbox-t04.md and the task report). Guarded and reversible.
 */
return new class extends Migration
{
    /** @var list<array{0: string, 1: string, 2: list<string>}> table, index name, columns */
    private const INDEXES = [
        // The queue ordering: needs_human = 1, then priority_rank, oldest customer message, id.
        ['conversations', 'conv_queue_rank_idx', ['needs_human', 'priority_rank', 'last_customer_message_at', 'id']],
        // assignee=<id> (R3: an unassigned row belongs to its last responder) and "mine". assignee_id sits
        // between the two so `last_responder_id = ? AND assignee_id IS NULL` is covered and still
        // ordered by last_message_at (the brief's (last_responder_id, last_message_at) needed a row
        // lookup per candidate to test assignee_id: 1.8 s for 12.5k rows on the load dataset).
        ['conversations', 'conv_last_responder_idx', ['last_responder_id', 'assignee_id', 'last_message_at']],
        // assignee=<id>, first branch: her assigned conversations, newest first (ConversationQuery::paginate).
        ['conversations', 'conv_assignee_idx', ['assignee_id', 'last_message_at']],
        // status=waiting: walk last_customer_message_at in order and stop after a page.
        ['conversations', 'conv_waiting_order_idx', ['last_customer_message_at', 'id']],
        // Detail: newest 100 notes.
        ['conversation_notes', 'conversation_notes_conversation_id_id_index', ['conversation_id', 'id']],
        // Media tab and the attachment eager load.
        ['message_attachments', 'message_attachments_message_id_type_index', ['message_id', 'type']],
        // Search fallback: phone and name prefix.
        ['customers', 'customers_phone_index', ['phone']],
        ['customers', 'customers_name_index', ['name']],
    ];

    private const FULLTEXT = 'customers_name_fulltext';

    private const PHONE_REVERSED_INDEX = 'customers_phone_reversed_index';

    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $t) {
            if (! Schema::hasColumn('conversations', 'priority_rank')) {
                // VIRTUAL (not STORED): sqlite cannot ADD a stored generated column; MariaDB indexes virtual ones.
                $t->unsignedTinyInteger('priority_rank')->nullable()
                    ->virtualAs("CASE priority_level WHEN 'high' THEN 0 WHEN 'medium' THEN 1 WHEN 'low' THEN 2 ELSE 3 END");
            }
        });

        foreach (self::INDEXES as [$table, $name, $columns]) {
            if (! $this->hasIndexOn($table, $columns)) {
                Schema::table($table, fn (Blueprint $t) => $t->index($columns, $name));
            }
        }

        if (! $this->isMysql()) {
            return; // sqlite keeps the substring LIKE search.
        }

        // FULLTEXT name search (word prefix, Arabic included).
        if (! $this->hasIndexNamed('customers', self::FULLTEXT)) {
            Schema::table('customers', fn (Blueprint $t) => $t->fullText('name', self::FULLTEXT));
        }

        // Phone suffix search ("last digits") as an index prefix range on the reversed phone,
        // instead of a LIKE '%digits' scan of every customer.
        if (! Schema::hasColumn('customers', 'phone_reversed')) {
            Schema::table('customers', function (Blueprint $t) {
                $t->string('phone_reversed')->nullable()->virtualAs('REVERSE(phone)');
                $t->index('phone_reversed', self::PHONE_REVERSED_INDEX);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('customers', 'phone_reversed')) {
            Schema::table('customers', function (Blueprint $t) {
                $t->dropIndex(self::PHONE_REVERSED_INDEX);
                $t->dropColumn('phone_reversed');
            });
        }

        if ($this->hasIndexNamed('customers', self::FULLTEXT)) {
            Schema::table('customers', fn (Blueprint $t) => $t->dropFullText(self::FULLTEXT));
        }

        foreach (array_reverse(self::INDEXES) as [$table, $name, $columns]) {
            if (! $this->hasIndexNamed($table, $name)) {
                continue;
            }

            // MariaDB silently drops a foreign key's own index once a wider index starts with the
            // same column, and refuses to drop the wider one later: give the key its index back first.
            $lead = $columns[0];
            if ($this->isMysql() && $this->isForeignKeyColumn($table, $lead) && ! $this->hasOtherIndexLeadingWith($table, $lead, $name)) {
                Schema::table($table, fn (Blueprint $t) => $t->index([$lead], "{$table}_{$lead}_foreign"));
            }

            Schema::table($table, fn (Blueprint $t) => $t->dropIndex($name));
        }

        if (Schema::hasColumn('conversations', 'priority_rank')) {
            Schema::table('conversations', fn (Blueprint $t) => $t->dropColumn('priority_rank'));
        }
    }

    /** @param list<string> $columns */
    private function hasIndexOn(string $table, array $columns): bool
    {
        foreach (Schema::getIndexes($table) as $index) {
            if (array_map('strtolower', $index['columns']) === array_map('strtolower', $columns)) {
                return true;
            }
        }

        return false;
    }

    private function hasIndexNamed(string $table, string $name): bool
    {
        foreach (Schema::getIndexes($table) as $index) {
            if (strtolower($index['name']) === strtolower($name)) {
                return true;
            }
        }

        return false;
    }

    private function isForeignKeyColumn(string $table, string $column): bool
    {
        foreach (Schema::getForeignKeys($table) as $fk) {
            if (array_map('strtolower', $fk['columns']) === [strtolower($column)]) {
                return true;
            }
        }

        return false;
    }

    private function hasOtherIndexLeadingWith(string $table, string $column, string $except): bool
    {
        foreach (Schema::getIndexes($table) as $index) {
            if (strtolower($index['name']) !== strtolower($except) && strtolower($index['columns'][0] ?? '') === strtolower($column)) {
                return true;
            }
        }

        return false;
    }

    private function isMysql(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
};
