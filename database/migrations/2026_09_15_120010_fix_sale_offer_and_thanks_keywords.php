<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Data-only and idempotent (fix round 1, review of 4c72cb2, issues 1 and 4):
 *
 * - sale_offer.keywords: bare "عرض"/"العرض" collided with the size script's
 *   "بالطول و العرض" ("العرض كام؟" wrongly handed over). Removed, and the
 *   "في عرض"/"فيه عرض"/"عليه عرض"/"نازل عليه" phrases added.
 * - thanks.keywords: "تحفه" collided with product compliments ("الفستان ده تحفه").
 *   Removed.
 *
 * Each row is only updated while its keywords still equal the exact list
 * seeded by 2026_09_15_100020/110020 -- an owner edit is left in place.
 */
return new class extends Migration
{
    private const OLD_SALE_OFFER_KEYWORDS = ['sale', 'سيل', 'عرض', 'العرض', 'عروض', 'اوفر', 'offer', 'تخفيضات'];

    private const NEW_SALE_OFFER_KEYWORDS = ['sale', 'سيل', 'عروض', 'اوفر', 'offer', 'تخفيضات', 'في عرض', 'فيه عرض', 'عليه عرض', 'نازل عليه'];

    private const OLD_THANKS_KEYWORDS = ['شكرا', 'متشكره', 'مرسي', 'تسلمي', 'thank', 'thanks', 'تحفه'];

    private const NEW_THANKS_KEYWORDS = ['شكرا', 'متشكره', 'مرسي', 'تسلمي', 'thank', 'thanks'];

    public function up(): void
    {
        if (! Schema::hasTable('bot_intents')) {
            return;
        }

        $this->updateIfUnchanged('sale_offer', self::OLD_SALE_OFFER_KEYWORDS, self::NEW_SALE_OFFER_KEYWORDS);
        $this->updateIfUnchanged('thanks', self::OLD_THANKS_KEYWORDS, self::NEW_THANKS_KEYWORDS);
    }

    /** @param  list<string>  $old  @param  list<string>  $new */
    private function updateIfUnchanged(string $key, array $old, array $new): void
    {
        $row = DB::table('bot_intents')->where('key', $key)->first();

        if ($row === null || json_decode((string) $row->keywords, true) !== $old) {
            return;
        }

        DB::table('bot_intents')->where('id', $row->id)->update([
            'keywords' => json_encode($new, JSON_UNESCAPED_UNICODE),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Data fix: left in place (the owner may have edited it since).
    }
};
