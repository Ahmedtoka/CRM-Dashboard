<?php

use Illuminate\Database\Migrations\Migration;

/**
 * One-time backfill of legacy `messages.attachments` JSON into the new
 * `message_attachments` table (spec §1, Dashboard Experience Task 1). Safe to
 * run more than once — App\Media\LegacyAttachmentBackfill skips messages that
 * already have rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(\App\Media\LegacyAttachmentBackfill::class)->run();
    }

    public function down(): void
    {
        // No-op: this is a one-time data backfill, not a schema change.
    }
};
