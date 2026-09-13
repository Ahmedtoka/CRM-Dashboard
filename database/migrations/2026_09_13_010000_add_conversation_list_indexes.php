<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inbox list indexes: the conversation list is cursor-paginated by last_message_at/id
 * (optionally per platform) and the "waiting" filter orders by last_customer_message_at.
 * Each index is guarded so the migration is safe to re-run on MariaDB 10.4.
 */
return new class extends Migration
{
    /** @var array<int, array<int, string>> */
    private array $indexes = [
        ['platform', 'last_message_at', 'id'],
        ['last_message_at', 'id'],
        ['status', 'last_customer_message_at'],
    ];

    public function up(): void
    {
        foreach ($this->indexes as $columns) {
            $name = $this->name($columns);

            if (Schema::hasIndex('conversations', $name) || Schema::hasIndex('conversations', $columns)) {
                continue;
            }

            Schema::table('conversations', fn (Blueprint $t) => $t->index($columns, $name));
        }
    }

    public function down(): void
    {
        foreach ($this->indexes as $columns) {
            $name = $this->name($columns);

            if (Schema::hasIndex('conversations', $name)) {
                Schema::table('conversations', fn (Blueprint $t) => $t->dropIndex($name));
            }
        }
    }

    private function name(array $columns): string
    {
        return 'conversations_'.implode('_', $columns).'_index';
    }
};
