<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Control room final review (B4, B-m6): lookups the new screens make without a leading index.
 * - ads(external_id): approval gate, launch monitor, alerts and funnel join ads by external id alone (the unique key
 *   leads with ad_account_id).
 * - conversations(is_test, last_message_at): Today, the funnel and the chat signals count
 *   real chats by time ((is_test, created_at) already exists).
 * Each index is guarded (table, columns, name, same columns) so it is safe to re-run on MariaDB 10.4; down() drops
 * only these.
 */
return new class extends Migration
{
    /** @var array<string, array{0: string, 1: list<string>}> name => [table, columns] */
    private const INDEXES = [
        'ads_external_id_idx' => ['ads', ['external_id']],
        'conversations_test_last_message_idx' => ['conversations', ['is_test', 'last_message_at']],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $name => [$table, $columns]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumns($table, $columns)
                || Schema::hasIndex($table, $name) || Schema::hasIndex($table, $columns)) {
                continue;
            }
            Schema::table($table, fn (Blueprint $t) => $t->index($columns, $name));
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $name => [$table]) {
            if (Schema::hasTable($table) && Schema::hasIndex($table, $name)) {
                Schema::table($table, fn (Blueprint $t) => $t->dropIndex($name));
            }
        }
    }
};
