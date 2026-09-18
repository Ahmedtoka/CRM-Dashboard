<?php

use App\Shopify\Customers\PhoneNormalizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            if (! Schema::hasColumn('customers', 'normalized_phone')) {
                $table->string('normalized_phone')->nullable()->index()->after('phone');
            }
            if (! Schema::hasColumn('customers', 'tags')) {
                $table->json('tags')->nullable()->after('shopify_customer_id');
            }
            if (! Schema::hasColumn('customers', 'accepts_marketing')) {
                $table->boolean('accepts_marketing')->default(false)->after('tags');
            }
            if (! Schema::hasColumn('customers', 'shopify_orders_count')) {
                $table->unsignedInteger('shopify_orders_count')->default(0)->after('accepts_marketing');
            }
            if (! Schema::hasColumn('customers', 'shopify_total_spent')) {
                $table->decimal('shopify_total_spent', 12, 2)->default(0)->after('shopify_orders_count');
            }
            if (! Schema::hasColumn('customers', 'shopify_updated_at')) {
                $table->timestamp('shopify_updated_at')->nullable()->after('shopify_total_spent');
            }
            if (! Schema::hasColumn('customers', 'is_repeat')) {
                $table->boolean('is_repeat')->default(false)->index()->after('shopify_updated_at');
            }
            if (! Schema::hasColumn('customers', 'has_open_order')) {
                $table->boolean('has_open_order')->default(false)->index()->after('is_repeat');
            }
            if (! Schema::hasColumn('customers', 'has_return')) {
                $table->boolean('has_return')->default(false)->index()->after('has_open_order');
            }
            if (! Schema::hasColumn('customers', 'has_stuck_order')) {
                $table->boolean('has_stuck_order')->default(false)->index()->after('has_return');
            }
        });

        // Blank strings and duplicates would break the unique index below
        // (final fix wave): data-only normalisation, skipped wherever the index
        // already exists. 2026_09_14_300000 repeats it for databases where this
        // migration already ran without the index.
        if (! $this->indexExists('customers', 'customers_shopify_customer_id_unique')) {
            DB::table('customers')
                ->whereNotNull('shopify_customer_id')
                ->whereRaw("TRIM(shopify_customer_id) = ''")
                ->update(['shopify_customer_id' => null]);

            DB::table('customers')
                ->whereNotNull('shopify_customer_id')
                ->groupBy('shopify_customer_id')
                ->havingRaw('COUNT(*) > 1')
                ->pluck('shopify_customer_id')
                ->each(fn ($shopifyId) => DB::table('customers')
                    ->where('shopify_customer_id', $shopifyId)
                    ->where('id', '!=', DB::table('customers')->where('shopify_customer_id', $shopifyId)->min('id'))
                    ->update(['shopify_customer_id' => null]));
        }

        // `email` and `shopify_customer_id` already exist on this table;
        // add the indexes the spec calls for without re-adding the columns.
        Schema::table('customers', function (Blueprint $table) {
            if (! $this->indexExists('customers', 'customers_email_index')) {
                $table->index('email');
            }
            if (! $this->indexExists('customers', 'customers_shopify_customer_id_unique')) {
                $table->unique('shopify_customer_id');
            }
        });

        // Backfill normalized_phone for pre-existing customers, chunked so
        // this stays safe on large tables.
        DB::table('customers')
            ->whereNotNull('phone')
            ->orderBy('id')
            ->chunkById(500, function ($customers) {
                foreach ($customers as $customer) {
                    DB::table('customers')
                        ->where('id', $customer->id)
                        ->update(['normalized_phone' => PhoneNormalizer::toE164($customer->phone)]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            if ($this->indexExists('customers', 'customers_shopify_customer_id_unique')) {
                $table->dropUnique('customers_shopify_customer_id_unique');
            }
            if ($this->indexExists('customers', 'customers_email_index')) {
                $table->dropIndex('customers_email_index');
            }

            $columns = array_filter([
                'normalized_phone', 'tags', 'accepts_marketing', 'shopify_orders_count',
                'shopify_total_spent', 'shopify_updated_at', 'is_repeat', 'has_open_order',
                'has_return', 'has_stuck_order',
            ], fn (string $column) => Schema::hasColumn('customers', $column));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }

    private function indexExists(string $table, string $index): bool
    {
        return collect(Schema::getIndexes($table))->contains(fn ($i) => $i['name'] === $index);
    }
};
