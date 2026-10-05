<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ads_audit_log')) {
            return;
        }

        Schema::create('ads_audit_log', function (Blueprint $table) {
            $table->id();
            $table->dateTime('at', 3);
            $table->string('actor_type', 10); // user | system | cli
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_role', 30)->nullable();
            $table->string('action', 80)->index();
            $table->string('subject_type', 60)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->foreignId('ad_account_id')->nullable()->constrained('ad_accounts')->nullOnDelete();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->json('meta')->nullable();
            $table->index(['ad_account_id', 'at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ads_audit_log');
    }
};
