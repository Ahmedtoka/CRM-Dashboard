<?php

namespace App\Support;

use App\Enums\QuickReplyScope;
use App\Models\QuickReply;
use App\Models\Tag;
use App\Models\User;

/**
 * One-click starter content for the empty Tags and Saved replies screens
 * ("أضيفي أمثلة جاهزة"). Idempotent: an example whose name / shared shortcut
 * already exists is skipped, so a second click never duplicates or overwrites.
 */
class StarterExamples
{
    /** @var array<string, string> name => colour */
    public const TAGS = [
        'مرتجع' => '#F59E0B',
        'شكوى' => '#DC2626',
        'VIP' => '#7C3AED',
        'متابعة' => '#2563EB',
        'أوردر متأخر' => '#EA580C',
    ];

    /** @var list<array{shortcut: string, title: string, body: string}> short Egyptian-Arabic replies for a women's clothing store */
    public const QUICK_REPLIES = [
        ['shortcut' => 'اهلا', 'title' => 'ترحيب', 'body' => 'أهلًا بيكي يا {الاسم_الأول} 🌸 نورتينا! أقدر أساعدك في إيه؟'],
        ['shortcut' => 'رقم_الاوردر', 'title' => 'طلب رقم الأوردر', 'body' => 'ممكن تبعتيلي رقم الأوردر أو رقم الموبايل اللي اتعمل بيه الطلب عشان أتابعه معاكي؟'],
        ['shortcut' => 'التوصيل', 'title' => 'متابعة ميعاد التوصيل', 'body' => 'بتابع مع شركة الشحن حالًا وهرجعلك بميعاد التوصيل في أقرب وقت 🚚'],
        ['shortcut' => 'اعتذار', 'title' => 'اعتذار عن التأخير', 'body' => 'آسفين جدًا على التأخير في الرد 🙏 أنا معاكي دلوقتي وهخلّص طلبك على طول.'],
        ['shortcut' => 'شكرا', 'title' => 'شكر', 'body' => 'شكرًا لذوقك يا {الاسم_الأول} 💕 لو احتجتي أي حاجة إحنا موجودين.'],
    ];

    /** @return int how many tags were created */
    public function addTags(): int
    {
        $created = 0;

        foreach (self::TAGS as $name => $color) {
            $created += Tag::firstOrCreate(['name' => $name], ['color' => $color])->wasRecentlyCreated ? 1 : 0;
        }

        return $created;
    }

    /** @return int how many shared replies were created */
    public function addQuickReplies(User $by): int
    {
        $created = 0;

        foreach (self::QUICK_REPLIES as $reply) {
            $exists = QuickReply::query()
                ->where('scope', QuickReplyScope::Shared->value)
                ->whereIn('shortcut', [$reply['shortcut'], '/'.$reply['shortcut']])
                ->exists();

            if ($exists) {
                continue;
            }

            QuickReply::create($reply + [
                'scope' => QuickReplyScope::Shared->value,
                'user_id' => null,
                'created_by' => $by->id,
                'platforms' => [],
            ]);
            $created++;
        }

        return $created;
    }
}
