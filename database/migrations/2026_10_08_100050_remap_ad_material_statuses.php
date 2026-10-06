<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Material status is derived from launches now (spec 3.5): the three manual values map onto the derived ones. */
    private const UP = ['not_started' => 'new', 'activated' => 'live', 'done' => 'retired'];

    private const DOWN = ['new' => 'not_started', 'in_review' => 'not_started', 'live' => 'activated', 'paused' => 'activated', 'retired' => 'done'];

    public function up(): void
    {
        $this->remap(self::UP);
        $this->defaultTo('new'); // final review B-m5: a new row starts as a derived value
    }

    public function down(): void
    {
        $this->remap(self::DOWN);
        $this->defaultTo('not_started');
    }

    private function defaultTo(string $value): void
    {
        if (! Schema::hasTable('ad_materials') || ! Schema::hasColumn('ad_materials', 'status')) {
            return;
        }
        Schema::table('ad_materials', fn (Blueprint $table) => $table->string('status', 20)->default($value)->change());
    }

    /** @param  array<string, string>  $map */
    private function remap(array $map): void
    {
        if (! Schema::hasTable('ad_materials') || ! Schema::hasColumn('ad_materials', 'status')) {
            return;
        }
        DB::transaction(function () use ($map) {
            foreach ($map as $from => $to) {
                DB::table('ad_materials')->where('status', $from)->update(['status' => $to]);
            }
        });
    }
};
