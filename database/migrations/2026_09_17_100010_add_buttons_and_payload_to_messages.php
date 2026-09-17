<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $t) {
            if (! Schema::hasColumn('messages', 'buttons')) {
                $t->json('buttons')->nullable()->after('attachments');
            }
            if (! Schema::hasColumn('messages', 'payload')) {
                $t->string('payload', 191)->nullable()->after('buttons');
            }
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $t) {
            if (Schema::hasColumn('messages', 'payload')) {
                $t->dropColumn('payload');
            }
            if (Schema::hasColumn('messages', 'buttons')) {
                $t->dropColumn('buttons');
            }
        });
    }
};
