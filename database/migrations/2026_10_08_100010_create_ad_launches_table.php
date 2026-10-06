<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Launch approvals (control room S1): one launch = one material into one ad set; N ads = files x captions, each an
     * ad_publications row. The history outlives its material, account and ad set (null on delete).
     */
    public function up(): void
    {
        if (Schema::hasTable('ad_launches')) {
            return;
        }

        Schema::create('ad_launches', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 26)->unique();
            $table->foreignId('ad_material_id')->nullable()->constrained('ad_materials')->nullOnDelete();
            $table->foreignId('ad_account_id')->nullable()->constrained('ad_accounts')->nullOnDelete();
            $table->foreignId('ad_set_id')->nullable()->constrained('ad_sets')->nullOnDelete();
            $table->string('campaign_external_id')->nullable();
            $table->string('campaign_name', 500)->nullable();
            $table->string('adset_external_id')->nullable();
            $table->string('adset_name', 500)->nullable();
            $table->json('identity')->nullable();
            $table->text('link')->nullable();
            $table->json('file_ids');
            $table->json('captions');
            $table->json('original')->nullable();
            $table->string('state', 24)->default('draft');
            $table->string('hold_from_state', 24)->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->json('checks')->nullable();
            $table->string('checks_hash', 64)->nullable();
            $table->foreignId('prepared_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewer_buyer_id')->nullable()->constrained('media_buyers')->nullOnDelete();
            $table->foreignId('forwarded_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('decision_code', 32)->nullable();
            $table->text('decision_reason')->nullable();
            $table->boolean('self_approved')->default(false);
            $table->text('last_error')->nullable();
            foreach (['submitted_at', 'forwarded_at', 'awaiting_at', 'decided_at', 'approved_at', 'expires_at', 'expiring_notified_at', 'live_at', 'stopped_at', 'retired_at'] as $column) {
                $table->timestamp($column)->nullable();
            }
            $table->timestamps();
            $table->index(['state', 'expires_at']);
            $table->index(['ad_account_id', 'state']);
            $table->index(['reviewer_buyer_id', 'state']);
            $table->index(['prepared_by_id', 'state']);
            $table->index(['ad_material_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_launches');
    }
};
