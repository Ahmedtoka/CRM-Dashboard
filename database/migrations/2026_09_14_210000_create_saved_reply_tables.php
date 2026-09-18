<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('quick_reply_categories')) {
            Schema::create('quick_reply_categories', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->unsignedInteger('sort')->default(0);
                $table->timestamps();
            });
            $now = now();
            foreach (['الترحيب', 'الأسعار والمنتجات', 'المقاسات', 'الشحن والتوصيل', 'الاستبدال والاسترجاع', 'الدفع', 'متابعة الأوردر', 'الشكاوى'] as $i => $name) {
                DB::table('quick_reply_categories')->insert(['name' => $name, 'sort' => ($i + 1) * 10, 'created_at' => $now, 'updated_at' => $now]);
            }
        }

        Schema::table('quick_replies', function (Blueprint $table) {
            if (! Schema::hasColumn('quick_replies', 'scope')) {
                $table->string('scope', 20)->default('shared')->index()->after('platforms');
            }
            if (! Schema::hasColumn('quick_replies', 'user_id')) {
                $table->foreignId('user_id')->nullable()->after('scope')->constrained('users')->cascadeOnDelete();
            }
            if (! Schema::hasColumn('quick_replies', 'category_id')) {
                $table->foreignId('category_id')->nullable()->after('user_id')->constrained('quick_reply_categories')->nullOnDelete();
            }
            if (! Schema::hasColumn('quick_replies', 'use_count')) {
                $table->unsignedInteger('use_count')->default(0)->after('category_id');
            }
            if (! Schema::hasColumn('quick_replies', 'last_used_at')) {
                $table->timestamp('last_used_at')->nullable()->after('use_count');
            }
        });

        if (! Schema::hasTable('quick_reply_attachments')) {
            Schema::create('quick_reply_attachments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('quick_reply_id')->constrained()->cascadeOnDelete();
                $table->string('disk', 30)->default('media');
                $table->string('path');
                $table->string('type', 20);
                $table->string('mime', 150)->nullable();
                $table->unsignedBigInteger('size_bytes')->nullable();
                $table->string('original_name')->nullable();
                $table->unsignedInteger('width')->nullable();
                $table->unsignedInteger('height')->nullable();
                $table->unsignedInteger('sort')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('quick_reply_usages')) {
            Schema::create('quick_reply_usages', function (Blueprint $table) {
                $table->id();
                $table->foreignId('quick_reply_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
                $table->string('platform', 30);
                $table->timestamp('used_at');
                $table->index(['quick_reply_id', 'used_at']);
                $table->index(['user_id', 'used_at']);
                $table->index('used_at');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('quick_reply_usages');
        Schema::dropIfExists('quick_reply_attachments');
        Schema::table('quick_replies', function (Blueprint $table) {
            foreach (['user_id', 'category_id'] as $fk) {
                if (Schema::hasColumn('quick_replies', $fk)) {
                    $table->dropConstrainedForeignId($fk);
                }
            }
            $table->dropColumn(array_values(array_filter(['scope', 'use_count', 'last_used_at'], fn ($c) => Schema::hasColumn('quick_replies', $c))));
        });
        Schema::dropIfExists('quick_reply_categories');
    }
};
