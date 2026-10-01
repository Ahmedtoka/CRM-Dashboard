<?php

use App\Bot\Language\TranslationsCommand;
use App\Models\BotTranslation;
use App\Support\Emoji;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 2026-10-01 §6: no stored customer-facing text keeps an emoji. Done in PHP, row by row
 * (MariaDB's utf8mb4_unicode_ci ignores emoji in comparisons, so SQL REPLACE/WHERE cannot be trusted).
 * Idempotent; down() is a no-op on purpose (emoji are not restored).
 * Past messages and notes are history and stay as they were sent.
 */
return new class extends Migration
{
    /** table => [text columns, json columns] */
    private const TARGETS = [
        'bot_knowledge_entries' => [['title', 'body'], []],
        'bot_settings' => [['system_prompt', 'outside_hours_message'], ['size_chart']],
        'bot_rules' => [['private_reply'], ['public_replies']],
        'quick_replies' => [['title', 'body'], []],
        'quick_reply_categories' => [['name'], []],
        'bot_flows' => [[], ['definition']],
        'bot_flow_versions' => [[], ['definition']],
        'bot_intents' => [['label_ar', 'label_en'], []],
        'support_cases' => [['summary'], []],
    ];

    /**
     * The shipped defaults whose wording changed beyond losing the emoji (2026-10-01: «تمام [check mark] هيتم…»
     * became «تمام، هيتم…»), keyed by the stripped old text. A stored text that still equals the
     * old default takes the new one; anything the owner reworded is only stripped.
     */
    private const REWORDED = [
        '{time_greeting} يا فندم يومك حلو ان شاء الله مع حضرتك ميار من Le Voile' => '{time_greeting} يا فندم يومك حلو ان شاء الله، مع حضرتك ميار من Le Voile',
        'الشحن خارج مصر :
- الاوردر بيتم عن طريق الويب سايت
- تكلفه الشحن بيتم تحدديها حسب وزن الشحنة
- مدة التوصيل من 7 الي 10 ايام
وده لينك الويب سايت https://levoilestores.com/
Thanks for choosing Le Voile
—
International Shipping (Outside Egypt): Orders can be placed through our website. Shipping cost is calculated based on the weight of the package. Delivery time is between 7 to 10 days. Here\'s the website link https://levoilestores.com/ Thanks for choosing Le Voile' => 'الشحن خارج مصر :
- الاوردر بيتم عن طريق الويب سايت
- تكلفه الشحن بيتم تحدديها حسب وزن الشحنة
- مدة التوصيل من 7 الي 10 ايام
وده لينك الويب سايت: https://levoilestores.com/
Thanks for choosing Le Voile
—
International Shipping (Outside Egypt): Orders can be placed through our website. Shipping cost is calculated based on the weight of the package. Delivery time is between 7 to 10 days. Here\'s the website link: https://levoilestores.com/ Thanks for choosing Le Voile',
        'شكرا لاهتمامك و إبداء رأيك أحنا بنسمع لكل عميل و هنوصل ملاحظتك' => 'شكرا لاهتمامك و إبداء رأيك، أحنا بنسمع لكل عميل و هنوصل ملاحظتك',
        'Hello beautiful Thank you for your interest in joining our team. Kindly send your pics (without filter) and IG account to this email careers@levoilestores.com and if your profile is accepted, one of our marketing team will contact you. Good luck dear' => 'Hello beautiful. Thank you for your interest in joining our team. Kindly send your pics (without filter) and IG account to this email careers@levoilestores.com and if your profile is accepted, one of our marketing team will contact you. Good luck dear',
        'طلب حضرتك متسجل برقم #{case_id} وهيتم التواصل مع حضرتك تحب نحولك لموظف؟' => 'طلب حضرتك متسجل برقم #{case_id} وهيتم التواصل مع حضرتك، تحب نحولك لموظف؟',
        'معلش مش واضحة ليا اختاري من دول:' => 'معلش مش واضحة ليا، اختاري من دول:',
        'معلش، لسه مش قادرة أفهم قصدك تحبي أوصلك لموظف يساعدك؟' => 'معلش، لسه مش قادرة أفهم قصدك، تحبي أوصلك لموظف يساعدك؟',
        'لسه فاكرين طلبك تحبي نكمل من حيث ما وقفنا؟' => 'لسه فاكرين طلبك، تحبي نكمل من حيث ما وقفنا؟',
        'أقدر أساعدك في' => 'أقدر أساعدك في حاجة من دول:',
        'مش لاقية أوردر بالبيانات دي ممكن تتأكدي من الرقم؟' => 'مش لاقية أوردر بالبيانات دي، ممكن تتأكدي من الرقم؟',
        'أكيد ممكن تقوليلي باختصار محتاجة إيه؟ عشان أوصّلك للشخص المناسب على طول' => 'أكيد، ممكن تقوليلي باختصار محتاجة إيه؟ عشان أوصّلك للشخص المناسب على طول',
        'تمام هيتم تحويلك لموظف خدمة العملاء خلال دقايق' => 'تمام، هيتم تحويلك لموظف خدمة العملاء خلال دقايق',
        'تمام سجلت طلبك، وهيتم تحويلك لموظف خدمة العملاء أول ما نفتح {next_opening}' => 'تمام، سجلت طلبك، وهيتم تحويلك لموظف خدمة العملاء أول ما نفتح {next_opening}',
        'تمام هيتم تحويلك لموظف خدمة العملاء، هيرد عليكي في أقرب وقت' => 'تمام، هيتم تحويلك لموظف خدمة العملاء، هيرد عليكي في أقرب وقت',
        'رسايلك وصلت للفريق وهيتم الرد على حضرتك في أقرب وقت' => 'رسايلك وصلت للفريق، وهيتم الرد على حضرتك في أقرب وقت',
        'رسايلك وصلت للفريق وهيتم الرد على حضرتك أول ما نفتح {next_opening}' => 'رسايلك وصلت للفريق، وهيتم الرد على حضرتك أول ما نفتح {next_opening}',
        'لسه بدور ثواني كمان' => 'لسه بدور، ثواني كمان',
        'دي أحدث الموديلات المتاحة عندنا اضغطي «التفاصيل والمقاسات» على أي موديل يعجبك' => 'دي أحدث الموديلات المتاحة عندنا، اضغطي «التفاصيل والمقاسات» على أي موديل يعجبك',
        'تمام هيتم تحويلك لموظفة خدمة العملاء. رقم تذكرتك #{ticket}، و{ahead}، وهنكون معاكي خلال حوالي {eta_minutes}' => 'تمام، هيتم تحويلك لموظفة خدمة العملاء. رقم تذكرتك #{ticket}، و{ahead}، وهنكون معاكي خلال حوالي {eta_minutes}',
        'شكراً لرسالتك إحنا خارج مواعيد العمل دلوقتي. رقم تذكرتك #{ticket} وهنكلمك أول ما نفتح الساعة {opening} بالترتيب. من فضلك ما تبعتيش رسايل تانية عشان الدور ما يتأثرش.' => 'شكراً لرسالتك، إحنا خارج مواعيد العمل دلوقتي. رقم تذكرتك #{ticket} وهنكلمك أول ما نفتح الساعة {opening} بالترتيب. من فضلك ما تبعتيش رسايل تانية عشان الدور ما يتأثرش.',
        'آسفين على التأخير كل الموظفات مشغولات دلوقتي، هتتحولي أول ما حد يفضى.' => 'آسفين على التأخير، كل الموظفات مشغولات دلوقتي، هتتحولي أول ما حد يفضى.',
        'دورك جه الموظفة {name} هترد عليكي دلوقتي.' => 'دورك جه، الموظفة {name} هترد عليكي دلوقتي.',
        'اتقفلت المحادثة مؤقتاً أول ما ترجعي ابعتي أي رسالة وهنرجّعك لنفس الموظفة بأولوية.' => 'اتقفلت المحادثة مؤقتاً، أول ما ترجعي ابعتي أي رسالة وهنرجّعك لنفس الموظفة بأولوية.',
        'أهلاً بيكي تاني بنرجّعك لنفس الموظفة بأولوية، رقم تذكرتك #{ticket} وهنكون معاكي خلال حوالي {eta_minutes}.' => 'أهلاً بيكي تاني، بنرجّعك لنفس الموظفة بأولوية، رقم تذكرتك #{ticket} وهنكون معاكي خلال حوالي {eta_minutes}.',
        'هنكمّل معاكي مع موظفة تانية بأولوية ثواني.' => 'هنكمّل معاكي مع موظفة تانية بأولوية، ثواني.',
        'فتحنالك طلب رقم {case_id} وهيتم التواصل معاكي خلال يوم عمل.' => 'فتحنالك طلب رقم {case_id}، وهيتم التواصل معاكي خلال يوم عمل.',
        'تم حل طلبك رقم {case_id} شكراً لصبرك' => 'تم حل طلبك رقم {case_id}، شكراً لصبرك',
        'رقم تذكرتك #{ticket} الفريق بيبدأ دلوقتي وهنكون معاكي في أقرب وقت، خليكي معانا' => 'رقم تذكرتك #{ticket}، الفريق بيبدأ دلوقتي وهنكون معاكي في أقرب وقت، خليكي معانا',
        'لسه معاكي رقم تذكرتك #{ticket}، و{ahead} {eta_sentence}' => 'لسه معاكي، رقم تذكرتك #{ticket}، و{ahead} {eta_sentence}',
        'معلش على التأخير زميلتنا {agent} معاكي حالاً' => 'معلش على التأخير، زميلتنا {agent} معاكي حالاً',
        'أهلاً يا {customer_first_name} أوردر #{order_number} — تحبي تلغيه ولا تعدلي فيه؟' => 'أهلاً يا {customer_first_name}، أوردر #{order_number} — تحبي تلغيه ولا تعدلي فيه؟',
        'تمام سجلت طلب إلغاء أوردر #{order_number}، والفريق هيأكد معاكي الإلغاء في أقرب وقت' => 'تمام، سجلت طلب إلغاء أوردر #{order_number}، والفريق هيأكد معاكي الإلغاء في أقرب وقت',
        'تمام سجلت طلب تعديل أوردر #{order_number}، والفريق هيأكد معاكي التعديل' => 'تمام، سجلت طلب تعديل أوردر #{order_number}، والفريق هيأكد معاكي التعديل',
        'آسفين جدًا لده الشكوى بخصوص إيه؟' => 'آسفين جدًا لده، الشكوى بخصوص إيه؟',
        'تمام سجلت الشكوى رقم #{case_id}، والفريق هيتواصل معاكي في أقرب وقت' => 'تمام، سجلت الشكوى رقم #{case_id}، والفريق هيتواصل معاكي في أقرب وقت',
        'أهلاً يا {customer_first_name} لقيت أوردر #{order_number} — تحبي ترجعي ولا تبدلي؟' => 'أهلاً يا {customer_first_name}، لقيت أوردر #{order_number} — تحبي ترجعي ولا تبدلي؟',
        'تمام تم تقديم طلب المرتجع بنجاح، ورقم طلبك هو نفس رقم الأوردر #{order_number}. هنتواصل معاكي أول ما المندوب يتحرك لاستلام المرتجع' => 'تمام، تم تقديم طلب المرتجع بنجاح، ورقم طلبك هو نفس رقم الأوردر #{order_number}. هنتواصل معاكي أول ما المندوب يتحرك لاستلام المرتجع',
        'تمام تم تسجيل طلب الاستبدال بـ «{exchange_product_title}». هنتواصل معاكي لتأكيد الاستبدال والإرسال' => 'تمام، تم تسجيل طلب الاستبدال بـ «{exchange_product_title}». هنتواصل معاكي لتأكيد الاستبدال والإرسال',
        'الأوردر ده عدّى على استلامه أكتر من 14 يوم والمرتجع والاستبدال عندنا خلال 14 يوم من الاستلام بس.
لو تحبي، أحوّلك لحد من الفريق يساعدك.' => 'الأوردر ده عدّى على استلامه أكتر من 14 يوم، والمرتجع والاستبدال عندنا خلال 14 يوم من الاستلام بس.
لو تحبي، أحوّلك لحد من الفريق يساعدك.',
        'أهلاً يا {customer_first_name} أوردر #{order_number} (اتطلب يوم {order_date} — {order_items})
الحالة: {order_status}
متوقع يوصل: {order_eta}
تتبع الشحنة: {order_tracking}' => 'أهلاً يا {customer_first_name}، أوردر #{order_number} (اتطلب يوم {order_date} — {order_items})
الحالة: {order_status}
متوقع يوصل: {order_eta}
تتبع الشحنة: {order_tracking}',
        'العفو لو احتجتي أي حاجة أنا موجودة' => 'العفو، لو احتجتي أي حاجة أنا موجودة',
        'سجلت طلب متابعة للأوردر #{order_number} الفريق هيتابع مع شركة الشحن ويرد عليكي في أقرب وقت' => 'سجلت طلب متابعة للأوردر #{order_number}، الفريق هيتابع مع شركة الشحن ويرد عليكي في أقرب وقت',
        'الأوردر لسه في معاده متوقع يوصل من {order_eta}، ولو اتأخر عن كده ابعتيلي وهتابعه فورًا' => 'الأوردر لسه في معاده، متوقع يوصل من {order_eta}، ولو اتأخر عن كده ابعتيلي وهتابعه فورًا',
        'للأسف مش لاقية الأوردر تحبي أحولك لحد من الفريق يتابعه معاكي؟' => 'للأسف مش لاقية الأوردر، تحبي أحولك لحد من الفريق يتابعه معاكي؟',
        'أهلاً بيكي في متجرنا بنبيع ملابس حريمي بتصميمات مختارة، وبنوصل لكل محافظات مصر.' => 'أهلاً بيكي في متجرنا، بنبيع ملابس حريمي بتصميمات مختارة، وبنوصل لكل محافظات مصر.',
        'أهلاً بيكي في Le Voile Le Voile براند ملابس محتشمة للستات: طرح، إسدالات، فساتين وأكتر.
تقدري تطلبي أونلاين من الويب سايت https://levoilestores.com/ أو تزورينا في فروعنا في مصر.' => 'أهلاً بيكي في Le Voile، براند ملابس محتشمة للستات: طرح، إسدالات، فساتين وأكتر.
تقدري تطلبي أونلاين من الويب سايت https://levoilestores.com/ أو تزورينا في فروعنا في مصر.',
        'الاوردر بيوصل القاهرة / الجيزة / الاسكندريه خلال 3-5 ايام عمل، وباقي المحافظات خلال 5-7 ايام عمل غير محسوب الاجازات الرسميه والاسبوعيه.
مصاريف الشحن بتتحسب حسب المحافظة وبتظهر لحضرتك قبل تأكيد الأوردر على الويب سايت.
الشحن خارج مصر عن طريق الويب سايت بس، وتكلفته حسب وزن الشحنة، ومدة التوصيل من 7 لـ 10 أيام.' => 'الاوردر بيوصل القاهرة / الجيزة / الاسكندريه خلال 3-5 ايام عمل، وباقي المحافظات خلال 5-7 ايام عمل، غير محسوب الاجازات الرسميه والاسبوعيه.
مصاريف الشحن بتتحسب حسب المحافظة وبتظهر لحضرتك قبل تأكيد الأوردر على الويب سايت.
الشحن خارج مصر عن طريق الويب سايت بس، وتكلفته حسب وزن الشحنة، ومدة التوصيل من 7 لـ 10 أيام.',
        'طريقة الغسيل بتكون يدوي بمية باردة وبمسحوق خفيف زي الجل، ومن غير فرك أو عصر وتفاصيل الخامة والعناية موضحة على الويب سايت في صفحة كل منتج.' => 'طريقة الغسيل بتكون يدوي بمية باردة وبمسحوق خفيف زي الجل، ومن غير فرك أو عصر، وتفاصيل الخامة والعناية موضحة على الويب سايت في صفحة كل منتج.',
        'أهلًا بيكي يا {الاسم_الأول} نورتينا! أقدر أساعدك في إيه؟' => 'أهلًا بيكي يا {الاسم_الأول}، نورتينا! أقدر أساعدك في إيه؟',
        'آسفين جدًا على التأخير في الرد أنا معاكي دلوقتي وهخلّص طلبك على طول.' => 'آسفين جدًا على التأخير في الرد، أنا معاكي دلوقتي وهخلّص طلبك على طول.',
        'شكرًا لذوقك يا {الاسم_الأول} لو احتجتي أي حاجة إحنا موجودين.' => 'شكرًا لذوقك يا {الاسم_الأول}، لو احتجتي أي حاجة إحنا موجودين.',
    ];

    /** table => column => the word a name gets when it was nothing but emoji. */
    private const EMPTY_FALLBACK = [
        'quick_replies' => ['title' => 'رد سريع'],
        'quick_reply_categories' => ['name' => 'تصنيف'],
    ];

    public function up(): void
    {
        $changed = [];
        foreach (self::TARGETS as $table => [$texts, $jsons]) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $texts = array_values(array_filter($texts, fn ($c) => Schema::hasColumn($table, $c)));
            $jsons = array_values(array_filter($jsons, fn ($c) => Schema::hasColumn($table, $c)));
            if ($texts === [] && $jsons === []) {
                continue;
            }
            $changed[$table] = 0;
            DB::table($table)->orderBy('id')->select(array_merge(['id'], $texts, $jsons))
                ->chunkById(200, function ($rows) use ($table, $texts, $jsons, &$changed) {
                    foreach ($rows as $row) {
                        $update = [];
                        foreach ($texts as $col) {
                            $v = $row->{$col};
                            if (is_string($v)) {
                                $new = self::clean($table === 'bot_settings' && $col === 'system_prompt' ? self::promptWording($v) : $v);
                                if ($new === '' && isset(self::EMPTY_FALLBACK[$table][$col])) {
                                    // A name that was nothing but emoji gets a neutral word, never ''.
                                    $new = self::EMPTY_FALLBACK[$table][$col];
                                }
                                if ($new !== $v) {
                                    $update[$col] = $new;
                                }
                            }
                        }
                        foreach ($jsons as $col) {
                            // Decoded first: Eloquent's json cast stores emoji escaped (as \u surrogate pairs),
                            // so the raw column text never shows them.
                            $v = $row->{$col};
                            $decoded = is_string($v) ? json_decode($v, true) : null;
                            if ($decoded === null) {
                                continue;
                            }
                            $clean = self::clean($decoded);
                            if ($table === 'bot_rules' && $col === 'public_replies' && is_array($clean)) {
                                // A reply that was nothing but emoji is dropped from the list; when every
                                // reply would go, the row is left as it is (the comment bot falls back to
                                // its generic line at send time) and logged for the owner to reword.
                                $kept = array_values(array_filter($clean, fn ($r) => ! is_string($r) || trim($r) !== ''));
                                if ($kept === [] && $clean !== []) {
                                    Log::warning('migration.strip_emoji.public_replies_all_emoji', ['bot_rule_id' => $row->id]);

                                    continue;
                                }
                                $clean = $kept;
                            }
                            if ($clean !== $decoded) {
                                $update[$col] = json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                            }
                        }
                        if ($update !== []) {
                            DB::table($table)->where('id', $row->id)->update($update);
                            $changed[$table]++;
                        }
                    }
                });
        }

        if (Schema::hasTable('bot_translations')) {
            // R12: a machine translation that carries an emoji is dropped (it regenerates on
            // demand); a human one is cleaned in place. Emoji in the Arabic source were masked
            // as ⟦n⟧, so the other machine rows hold none: rows of sources that changed simply
            // stop matching, and the reviewed English of the new sources is seeded below.
            $changed['bot_translations.auto_deleted'] = 0;
            DB::table('bot_translations')->orderBy('id')->select(['id', 'text', 'origin'])->chunkById(200, function ($rows) use (&$changed) {
                foreach ($rows as $row) {
                    if (! Emoji::contains((string) $row->text)) {
                        continue;
                    }
                    if ($row->origin === BotTranslation::ORIGIN_AUTO) {
                        DB::table('bot_translations')->where('id', $row->id)->delete();
                        $changed['bot_translations.auto_deleted']++;
                    } else {
                        DB::table('bot_translations')->where('id', $row->id)->update(['text' => Emoji::strip((string) $row->text)]);
                    }
                }
            });
            $changed['bot_translations.seeded'] = self::seedEnglish();
        }

        Log::info('migration.strip_emoji', $changed);
    }

    /**
     * The reviewed English of the reworded texts (database/seeders/data/bot_translations_en.php),
     * as 2026_09_21_200040 seeds it: only sources that have no row yet, so a human row stays.
     */
    private static function seedEnglish(): int
    {
        $path = TranslationsCommand::path('en');
        if (! is_file($path)) {
            return 0;
        }

        $now = now();
        $rows = [];
        foreach ((array) require $path as $source => $text) {
            $source = (string) $source;
            $text = trim((string) $text);
            if ($source === '' || $text === '') {
                continue;
            }
            $rows[] = [
                'source_hash' => BotTranslation::hash($source), 'source_text' => $source, 'locale' => 'en', 'text' => $text,
                'origin' => BotTranslation::ORIGIN_AUTO, 'context' => 'seed', 'created_at' => $now, 'updated_at' => $now,
            ];
        }

        $inserted = 0;
        foreach (array_chunk($rows, 200) as $chunk) {
            $existing = DB::table('bot_translations')->where('locale', 'en')->whereIn('source_hash', array_column($chunk, 'source_hash'))->pluck('source_hash')->all();
            $new = array_values(array_filter($chunk, fn (array $row) => ! in_array($row['source_hash'], $existing, true)));
            if ($new !== []) {
                DB::table('bot_translations')->insert($new);
                $inserted += count($new);
            }
        }

        return $inserted;
    }

    /** Emoji::strip on every string leaf, then the new wording of an untouched default. */
    private static function clean(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(fn ($v) => self::clean($v), $value);
        }
        if (! is_string($value)) {
            return $value;
        }
        $stripped = Emoji::strip($value);

        return self::REWORDED[$stripped] ?? $stripped;
    }

    private static function promptWording(string $prompt): string
    {
        return str_replace(['وإيموجي واحد بالكتير', 'و ايموجي واحد بالكتير', 'وايموجي واحد بالكتير'], 'ومن غير أي إيموجي', $prompt);
    }

    public function down(): void
    {
        // Emoji are not restored.
    }
};
