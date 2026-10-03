<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('message_attachments', 'thumb_path')) {
            Schema::table('message_attachments', function (Blueprint $t) {
                $t->string('thumb_path')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('message_attachments', 'thumb_path')) {
            Schema::table('message_attachments', function (Blueprint $t) {
                $t->dropColumn('thumb_path');
            });
        }
    }
};
