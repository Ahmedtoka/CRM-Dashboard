<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('external_id');
            $table->string('parent_external_id')->nullable();
            $table->text('body');
            $table->string('status', 30)->default('new');
            $table->string('intent', 30)->nullable();
            $table->text('public_reply')->nullable();
            $table->timestamp('public_replied_at')->nullable();
            $table->string('replied_by_type', 30)->nullable();
            $table->unsignedBigInteger('replied_by_id')->nullable();
            $table->timestamp('private_reply_sent_at')->nullable();
            $table->foreignId('conversation_id')->nullable()->constrained('conversations')->nullOnDelete();
            $table->timestamps();

            $table->unique('external_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comments');
    }
};
