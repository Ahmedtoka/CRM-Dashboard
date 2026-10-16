<?php

namespace App\Confirmations\WhatsApp;

/**
 * The two WhatsApp templates of order confirmation (spec 2026-10-16), in the shape
 * `POST /{waba-id}/message_templates` takes. The one place for their copy, buttons
 * and the example values Meta reviews.
 *
 * Body variables, their examples and the buttons all come from TemplateParams (shared
 * with the sender), so the quick-reply payload at index i is the answer BUTTON_ACTIONS[i].
 * The copy is transactional only (no offers, no prices beyond the order's own) so Meta
 * approves it as UTILITY.
 */
final class TemplateDefinitions
{
    public const CONFIRM = 'order_confirm_ar';

    public const REMINDER = 'order_reminder_ar';

    public const LANGUAGE = 'ar';

    /** Answer of each quick-reply button, by index. */
    public const BUTTON_ACTIONS = TemplateParams::BUTTONS;

    private const FOOTER = 'Le Voile';

    private const BODIES = [
        self::CONFIRM => "أهلاً {{1}}،\nاستلمنا أوردرك رقم {{2}}:\n{{3}}\nالإجمالي: {{4}}\n{{5}}\n\nياريت تأكدي الأوردر عشان نبدأ نجهزه. لو محتاجة تعدلي حاجة أو تلغي الأوردر اختاري من الزراير اللي تحت.",
        self::REMINDER => "أهلاً {{1}}،\nلسه مستنيين تأكيدك لأوردر رقم {{2}}:\n{{3}}\nالإجمالي: {{4}}\n{{5}}\n\nمن فضلك اختاري من الزراير اللي تحت عشان نكمل الأوردر.",
    ];

    /** @return array<string, array<string, mixed>> name => message_templates payload */
    public static function all(): array
    {
        return [
            self::CONFIRM => self::definition(self::CONFIRM),
            self::REMINDER => self::definition(self::REMINDER),
        ];
    }

    /** @return array<string, mixed>|null */
    public static function find(string $name): ?array
    {
        return self::all()[$name] ?? null;
    }

    /** @return array<string, mixed> */
    private static function definition(string $name): array
    {
        return [
            'name' => $name,
            'language' => self::LANGUAGE,
            'category' => 'UTILITY',
            'components' => [
                ['type' => 'BODY', 'text' => self::BODIES[$name], 'example' => ['body_text' => [self::examples()]]],
                ['type' => 'FOOTER', 'text' => self::FOOTER],
                ['type' => 'BUTTONS', 'buttons' => array_map(
                    fn (string $action) => ['type' => 'QUICK_REPLY', 'text' => TemplateParams::BUTTON_TITLES[$action]],
                    TemplateParams::BUTTONS,
                )],
            ],
        ];
    }

    /** @return list<string> example values for {{1}}..{{n}}, in TemplateParams::BODY order */
    private static function examples(): array
    {
        return array_map(fn (string $param) => TemplateParams::EXAMPLES[$param], TemplateParams::BODY);
    }
}
