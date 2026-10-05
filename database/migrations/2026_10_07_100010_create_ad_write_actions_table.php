<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per human write intent on an ad platform (Phase B, write-api 2.1): proposal, confirmation, execution and
     * outcome. open_business_key is unique and only set while a Run holds its target (a Stop never takes it).
     */
    public function up(): void
    {
        if (Schema::hasTable('ad_write_actions')) {
            return;
        }

        Schema::create('ad_write_actions', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('type', 30);
            $table->string('state', 24);
            $table->string('platform', 20);
            $table->foreignId('ad_account_id')->nullable()->constrained('ad_accounts')->nullOnDelete();
            $table->string('account_name')->nullable();
            $table->string('target_level', 10);
            $table->string('target_external_id', 100);
            $table->string('target_name', 500)->nullable();
            $table->string('target_key', 191)->index();
            $table->string('from_status', 40)->nullable();
            $table->string('to_status', 10)->nullable();
            $table->json('params');
            $table->json('diff');
            $table->char('diff_hash', 64);
            $table->json('expected')->nullable();
            $table->json('limits_checked')->nullable();
            $table->json('notes')->nullable();
            $table->text('reason')->nullable();
            $table->string('source', 20)->default('ui');
            $table->string('source_ref', 100)->nullable();
            $table->string('actor_type', 10)->default('user');
            $table->foreignId('proposed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('confirmed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->string('confirmed_role', 30)->nullable();
            $table->string('idempotency_key', 100);
            $table->char('request_hash', 64)->nullable();
            $table->string('open_business_key', 191)->nullable()->unique();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('executing_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('retry_at')->nullable();
            $table->timestamp('restart_lock_until')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->json('outcome')->nullable();
            $table->string('error_code', 60)->nullable();
            $table->text('error_message')->nullable();
            $table->foreignId('rollback_of_id')->nullable()->constrained('ad_write_actions')->nullOnDelete();
            $table->foreignId('rolled_back_by_id')->nullable()->constrained('ad_write_actions')->nullOnDelete();
            $table->foreignId('superseded_by_id')->nullable()->constrained('ad_write_actions')->nullOnDelete();
            $table->string('request_id', 64)->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamps();

            $table->unique(['proposed_by_id', 'idempotency_key'], 'ad_write_actions_idem_unique');
            $table->index(['ad_account_id', 'created_at'], 'ad_write_actions_account_created_index');
            $table->index(['state', 'updated_at'], 'ad_write_actions_state_updated_index');
            $table->index(['target_key', 'state'], 'ad_write_actions_target_state_index');
            $table->index(['confirmed_by_id', 'confirmed_at'], 'ad_write_actions_confirmer_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_write_actions');
    }
};
