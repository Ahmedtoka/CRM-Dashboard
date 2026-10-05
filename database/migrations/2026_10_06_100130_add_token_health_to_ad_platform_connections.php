<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var array<string, callable(Blueprint): mixed> */
    private function columns(): array
    {
        return [
            'token_valid' => fn (Blueprint $t) => $t->boolean('token_valid')->nullable(),
            'token_type' => fn (Blueprint $t) => $t->string('token_type', 20)->nullable(),
            'token_scopes' => fn (Blueprint $t) => $t->json('token_scopes')->nullable(),
            'token_expires_at' => fn (Blueprint $t) => $t->timestamp('token_expires_at')->nullable(),
            'data_access_expires_at' => fn (Blueprint $t) => $t->timestamp('data_access_expires_at')->nullable(),
            'token_checked_at' => fn (Blueprint $t) => $t->timestamp('token_checked_at')->nullable(),
            'read_only' => fn (Blueprint $t) => $t->boolean('read_only')->default(false),
        ];
    }

    public function up(): void
    {
        foreach ($this->columns() as $name => $add) {
            if (! Schema::hasColumn('ad_platform_connections', $name)) {
                Schema::table('ad_platform_connections', fn (Blueprint $t) => $add($t));
            }
        }
    }

    public function down(): void
    {
        foreach (array_keys($this->columns()) as $name) {
            if (Schema::hasColumn('ad_platform_connections', $name)) {
                Schema::table('ad_platform_connections', fn (Blueprint $t) => $t->dropColumn($name));
            }
        }
    }
};
