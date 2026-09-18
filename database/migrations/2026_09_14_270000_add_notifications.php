<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'preferences')) {
            Schema::table('users', fn (Blueprint $t) => $t->json('preferences')->nullable()->after('color'));
        }
        if (! Schema::hasTable('user_notifications')) {
            Schema::create('user_notifications', function (Blueprint $t) {
                $t->id();
                $t->foreignId('user_id')->constrained()->cascadeOnDelete();
                $t->string('type', 40);
                $t->json('data')->nullable();
                $t->timestamp('read_at')->nullable();
                $t->timestamp('created_at')->nullable();
                $t->index(['user_id', 'read_at', 'id']);
            });
        }
        if (! collect(Schema::getIndexes('conversations'))->contains(fn ($i) => $i['name'] === 'conversations_unread_count_index')) {
            Schema::table('conversations', fn (Blueprint $t) => $t->index('unread_count'));
        }
    }

    public function down(): void
    {
        if (collect(Schema::getIndexes('conversations'))->contains(fn ($i) => $i['name'] === 'conversations_unread_count_index')) {
            Schema::table('conversations', fn (Blueprint $t) => $t->dropIndex(['unread_count']));
        }
        Schema::dropIfExists('user_notifications');
        if (Schema::hasColumn('users', 'preferences')) {
            Schema::table('users', fn (Blueprint $t) => $t->dropColumn('preferences'));
        }
    }
};
