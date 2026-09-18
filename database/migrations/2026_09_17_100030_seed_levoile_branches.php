<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Data-only and idempotent: inserts every branch from the brand website JSON
 * (docs/bot/levoile-branches.json, copied to database/data/levoile-branches.json)
 * mapped to its App\Bot\Flows\BranchFinder area key and default aliases.
 *
 * Dedup key is `name` + `address` (not `name` + `area_key`, which the source
 * data violates — e.g. two "Abbas El Akkad" stores at different addresses
 * inside Nasr City): this keeps the migration safe to re-run without
 * clobbering owner edits while still inserting every distinct branch. A row
 * that already exists (matched by name + address) is left untouched.
 */
return new class extends Migration
{
    /** @var array<string, array{0: string, 1: string}> website `area_en` -> [BranchFinder area_key, canonical area_ar] */
    private const AREA_MAP = [
        'Heliopolis' => ['heliopolis', 'مصر الجديدة'],
        'Nasr City' => ['nasr_city', 'مدينة نصر'],
        '5th Settlement' => ['fifth_settlement', 'التجمع الخامس'],
        'El Rehab' => ['rehab', 'الرحاب'],
        'Madinaty' => ['madinaty', 'مدينتي'],
        'El Mokkatam' => ['mokattam', 'المقطم'],
        'Maadi' => ['maadi', 'المعادي'],
        'El Mohandessin' => ['mohandessin', 'المهندسين'],
        '6th of October' => ['october_zayed', 'أكتوبر والشيخ زايد'],
        'Alexandria' => ['alexandria', 'الإسكندرية'],
        'Mansoura' => ['mansoura', 'المنصورة'],
        'Zagazig' => ['zagazig', 'الزقازيق'],
    ];

    /** @var array<string, list<string>> default alias phrases per area_key, normalized at match time by BranchFinder */
    private const ALIASES = [
        'heliopolis' => ['مصر الجديدة', 'مصر الجديده', 'هليوبوليس', 'heliopolis', 'masr el gedida', 'الميرغني', 'المرغني', 'الحجاز', 'روكسي', 'الكوربة'],
        'nasr_city' => ['مدينة نصر', 'مدينه نصر', 'nasr city', 'nasr', 'عباس العقاد', 'مكرم عبيد', 'النزهة', 'شارع النزهة', 'سيتي ستارز', 'city stars', 'جنينة مول', 'الحي السابع', 'الحي العاشر'],
        'fifth_settlement' => ['التجمع', 'التجمع الخامس', 'القاهرة الجديدة', 'new cairo', 'tagamoa', 'جاليريا', 'بوينت 90', 'point 90', 'الرباط', 'شارع التسعين', '90'],
        'rehab' => ['الرحاب', 'rehab', 'ذا يارد', 'the yard'],
        'madinaty' => ['مدينتي', 'madinaty', 'اوبن اير'],
        'mokattam' => ['المقطم', 'mokattam', 'الهضبة الوسطى'],
        'maadi' => ['المعادي', 'maadi', 'المعادي الجديدة', 'دجلة', 'زهراء المعادي'],
        'mohandessin' => ['المهندسين', 'mohandessin', 'الدقي', 'العجوزة', 'agouza', 'dokki', 'الجيزة', 'giza'],
        'october_zayed' => ['اكتوبر', '6 اكتوبر', 'السادس من اكتوبر', 'october', 'الشيخ زايد', 'زايد', 'zayed', 'مول مصر', 'mall of egypt', 'مول العرب', 'mall of arabia', 'سرايا'],
        'alexandria' => ['اسكندرية', 'الاسكندرية', 'اسكندريه', 'alex', 'alexandria', 'سموحة', 'ميامي', 'سان ستيفانو', 'الابراهيمية'],
        'mansoura' => ['المنصورة', 'المنصوره', 'mansoura', 'الدقهلية'],
        'zagazig' => ['الزقازيق', 'zagazig', 'الشرقية'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('branches')) {
            return;
        }

        $path = database_path('data/levoile-branches.json');

        if (! is_file($path)) {
            return;
        }

        $rows = json_decode((string) file_get_contents($path), true);

        if (! is_array($rows)) {
            return;
        }

        $now = now();
        $sort = 0;

        foreach ($rows as $row) {
            $sort += 10;
            $mapped = self::AREA_MAP[(string) ($row['area_en'] ?? '')] ?? null;

            if ($mapped === null) {
                continue;
            }

            [$areaKey, $areaAr] = $mapped;
            $name = (string) ($row['name'] ?? '');
            $address = (string) ($row['address'] ?? '');

            if (DB::table('branches')->where('name', $name)->where('address', $address)->exists()) {
                continue;
            }

            DB::table('branches')->insert([
                'governorate' => (string) ($row['governorate'] ?? ''),
                'area_key' => $areaKey,
                'area_ar' => $areaAr,
                'area_en' => (string) ($row['area_en'] ?? ''),
                'name' => $name,
                'address' => $address,
                'phone' => $row['phone'] ?? null,
                'map_url' => $row['map_url'] ?? null,
                'hours' => null,
                'aliases' => json_encode(self::ALIASES[$areaKey] ?? [], JSON_UNESCAPED_UNICODE),
                'is_active' => true,
                'sort' => $sort,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Data-only: nothing to reverse.
    }
};
