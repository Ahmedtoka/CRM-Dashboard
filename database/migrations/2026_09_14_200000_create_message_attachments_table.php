<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Media attachments (Dashboard Experience Task 1, spec §1). One row per image,
 * voice note, video, document or sticker on a message — inbound (downloaded
 * from the platform) or outbound (uploaded before sending).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('message_attachments')) {
            return;
        }

        Schema::create('message_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 20);
            $table->string('disk', 30)->default('media');
            $table->string('path')->nullable();
            $table->string('mime', 150)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('original_name')->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->text('remote_url')->nullable();
            $table->string('remote_id')->nullable();
            $table->string('status', 20)->default('pending');
            $table->string('error')->nullable();
            $table->timestamps();
            $table->index('message_id');
            $table->index(['uploaded_by', 'message_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_attachments');
    }
};
