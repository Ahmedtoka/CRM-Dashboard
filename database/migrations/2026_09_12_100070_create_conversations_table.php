<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('channel_account_id')->constrained()->cascadeOnDelete();
            $table->string('platform', 30);
            $table->string('status', 30)->default('open');
            $table->string('handler', 30)->default('bot');
            $table->boolean('needs_human')->default(false);
            $table->string('source', 30)->default('direct');
            $table->unsignedBigInteger('source_comment_id')->nullable();
            $table->foreignId('first_responder_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('last_responder_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('locked_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('locked_until')->nullable();
            $table->unsignedInteger('unread_count')->default(0);
            $table->timestamp('last_message_at')->nullable();
            $table->timestamp('last_customer_message_at')->nullable();
            $table->timestamp('first_response_at')->nullable();
            $table->timestamp('handover_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'last_message_at']);
            $table->index(['handler', 'needs_human']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
