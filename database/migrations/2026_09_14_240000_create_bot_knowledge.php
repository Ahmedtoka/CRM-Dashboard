<?php

use App\Bot\Knowledge\KnowledgeDefaults;
use App\Bot\Knowledge\SizeChart;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bot_knowledge_entries')) {
            Schema::create('bot_knowledge_entries', function (Blueprint $table) {
                $table->id();
                $table->string('key', 60)->unique();
                $table->string('title');
                $table->text('body');
                $table->boolean('is_active')->default(true);
                $table->boolean('is_template')->default(false);
                $table->unsignedInteger('sort')->default(100);
                $table->timestamps();
            });
            $now = now();
            foreach (KnowledgeDefaults::entries() as $e) {
                DB::table('bot_knowledge_entries')->insert($e + ['is_active' => true, 'is_template' => true, 'created_at' => $now, 'updated_at' => $now]);
            }
        }

        Schema::table('bot_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('bot_settings', 'size_chart')) {
                $table->json('size_chart')->nullable();
            }
            if (! Schema::hasColumn('bot_settings', 'size_chart_image_path')) {
                $table->string('size_chart_image_path')->nullable();
            }
            if (! Schema::hasColumn('bot_settings', 'size_chart_image_mime')) {
                $table->string('size_chart_image_mime', 60)->nullable();
            }
        });

        Schema::table('bot_rules', function (Blueprint $table) {
            if (! Schema::hasColumn('bot_rules', 'knowledge_key')) {
                $table->string('knowledge_key', 60)->nullable();
            }
            if (! Schema::hasColumn('bot_rules', 'sends_size_chart')) {
                $table->boolean('sends_size_chart')->default(false);
            }
        });

        DB::table('bot_settings')->whereNull('size_chart')->update(['size_chart' => json_encode(SizeChart::DEFAULT, JSON_UNESCAPED_UNICODE)]);
    }

    public function down(): void
    {
        $this->dropColumnsIfAny('bot_rules', ['knowledge_key', 'sends_size_chart']);
        $this->dropColumnsIfAny('bot_settings', ['size_chart', 'size_chart_image_path', 'size_chart_image_mime']);
        Schema::dropIfExists('bot_knowledge_entries');
    }

    /** @param  list<string>  $columns */
    private function dropColumnsIfAny(string $table, array $columns): void
    {
        $present = array_values(array_filter($columns, fn ($c) => Schema::hasColumn($table, $c)));

        if ($present !== []) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn($present));
        }
    }
};
