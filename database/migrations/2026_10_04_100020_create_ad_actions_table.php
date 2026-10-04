<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** One row per Stop / Run attempt on a platform (success or error): who, where, what, why. */
    public function up(): void
    {
        if (Schema::hasTable('ad_actions')) {
            return;
        }

        Schema::create('ad_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('platform', 20);
            $table->foreignId('ad_account_id')->nullable()->constrained('ad_accounts')->nullOnDelete();
            $table->string('account_name')->nullable();
            $table->string('level', 10);
            $table->string('external_id');
            $table->string('name', 500)->nullable();
            $table->string('from_status', 40)->nullable();
            $table->string('to_status', 20);
            $table->text('reason')->nullable();
            $table->string('result', 20);
            $table->text('error')->nullable();
            $table->timestamps();
            $table->index(['ad_account_id', 'created_at'], 'ad_actions_account_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_actions');
    }
};
