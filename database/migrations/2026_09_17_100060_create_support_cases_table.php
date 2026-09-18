<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('support_cases')) {
            return;
        }

        Schema::create('support_cases', function (Blueprint $t) {
            $t->id();
            $t->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $t->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $t->string('platform', 30);
            $t->string('type', 30);
            $t->string('status', 20)->default('new');
            $t->string('priority', 10)->default('medium');
            $t->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $t->string('order_number', 40)->nullable()->index();
            $t->json('data')->nullable();
            $t->json('photo_attachment_ids')->nullable();
            $t->json('policy_notes')->nullable();
            $t->text('summary')->nullable();
            $t->foreignId('assigned_to_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('closed_at')->nullable();
            $t->foreignId('closed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();

            $t->index(['type', 'status']);
            $t->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_cases');
    }
};
