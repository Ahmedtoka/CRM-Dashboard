<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Range indexes for analytics queries (15k messages/day design volume).
 * Each index is guarded so the migration is safe to re-run on MariaDB 10.4.
 */
return new class extends Migration
{
    /** @var array<string, array<int, array<int, string>>> */
    private array $indexes = [
        'messages' => [['created_at'], ['direction', 'sender_type', 'created_at']],
        'orders' => [['created_at']],
        'comments' => [['created_at']],
        'activity_logs' => [['created_at']],
    ];

    public function up(): void
    {
        foreach ($this->indexes as $table => $indexes) {
            foreach ($indexes as $columns) {
                $name = $this->name($table, $columns);

                if (Schema::hasIndex($table, $name) || Schema::hasIndex($table, $columns)) {
                    continue;
                }

                Schema::table($table, fn (Blueprint $t) => $t->index($columns, $name));
            }
        }
    }

    public function down(): void
    {
        foreach ($this->indexes as $table => $indexes) {
            foreach ($indexes as $columns) {
                $name = $this->name($table, $columns);

                if (Schema::hasIndex($table, $name)) {
                    Schema::table($table, fn (Blueprint $t) => $t->dropIndex($name));
                }
            }
        }
    }

    private function name(string $table, array $columns): string
    {
        return $table.'_'.implode('_', $columns).'_index';
    }
};
