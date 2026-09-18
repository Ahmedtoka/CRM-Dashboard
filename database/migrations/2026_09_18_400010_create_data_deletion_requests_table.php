<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Meta Data Deletion Callback requests (POST /webhooks/facebook/data-deletion).
 * `confirmation_code` is what the person sees on /data-deletion?code=…; the row
 * never holds anything but the platform-scoped id Meta sent us.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('data_deletion_requests')) {
            return;
        }

        Schema::create('data_deletion_requests', function (Blueprint $table) {
            $table->id();
            $table->string('confirmation_code', 40)->unique();
            $table->string('platform', 20);
            $table->string('external_user_id');
            $table->string('status', 20)->default('pending'); // pending | completed | not_found
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['platform', 'external_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_deletion_requests');
    }
};
