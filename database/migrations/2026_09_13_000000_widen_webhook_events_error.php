<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `webhook_events.error` was a varchar(255); a real adapter/broadcast
     * failure message (e.g. a cURL connection error) can exceed that, which
     * made the failure-handling `$event->update([...'error' => ...])` call
     * in ProcessWebhookEvent itself throw and mask the original exception.
     */
    public function up(): void
    {
        Schema::table('webhook_events', function (Blueprint $table) {
            $table->text('error')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('webhook_events', function (Blueprint $table) {
            $table->string('error')->nullable()->change();
        });
    }
};
