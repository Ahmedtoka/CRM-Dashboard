<?php

namespace App\Bot\Flows;

use App\Models\BotFlow;

/**
 * How a flow is named inside a sentence (design 2026-09-21 §6): «نرجع لـطلب المرتجع 🌸»,
 * «تحبي نسيب طلب المرتجع ونتابع متابعة الأوردر؟». The flow's own title is a menu label
 * («المرتجع والاستبدال»), which does not read well after «نرجع لـ», so the built-in flows
 * carry a phrase of their own here; anything the owner adds later falls back to its title.
 *
 * And the buttons those sentences come with, all inside Messenger's 20 characters.
 */
final class FlowLabels
{
    public const AGENT_BUTTON = ['title' => 'كلم موظف', 'payload' => 'handover:not_understood'];

    public const MENU_BUTTON = ['title' => 'القائمة الرئيسية', 'payload' => 'menu:main_menu'];

    public const YES_BUTTON = ['title' => 'أيوه', 'payload' => 'yes'];

    public const NO_BUTTON = ['title' => 'لأ', 'payload' => 'no'];

    /** §6.2: [أيوه] [لأ نكمل] — «لأ نكمل اللي إحنا فيه» is 21 characters, one over the limit. */
    public const KEEP_BUTTON = ['title' => 'لأ نكمل', 'payload' => 'no'];

    public const RESUME_BUTTON = ['title' => 'نكمل', 'payload' => 'resume:continue'];

    public const RESTART_BUTTON = ['title' => 'ابدأ من جديد', 'payload' => 'resume:restart'];

    /** @var array<string, string> */
    private const LABELS = [
        'return_exchange' => 'طلب المرتجع',
        'order_tracking' => 'متابعة الأوردر',
        'cancel_edit' => 'طلب الإلغاء أو التعديل',
        'complaint' => 'الشكوى',
        'branches' => 'الفروع',
        'products' => 'الموديلات والأسعار',
        'main_menu' => 'القائمة الرئيسية',
    ];

    public static function of(?string $flowKey): string
    {
        if ($flowKey === null || $flowKey === '') {
            return self::LABELS['main_menu'];
        }

        return self::LABELS[$flowKey]
            ?? (string) (BotFlow::query()->where('key', $flowKey)->value('title_ar') ?: $flowKey);
    }
}
