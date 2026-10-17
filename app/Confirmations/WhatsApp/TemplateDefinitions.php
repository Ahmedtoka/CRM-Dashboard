<?php

namespace App\Confirmations\WhatsApp;

use LogicException;

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

    /**
     * The copy, with each variable written by its TemplateParams::BODY name ({address});
     * definition() numbers them {{1}}..{{n}} in BODY order. The payment line is the
     * variable alone: its wording (COD, or «اتأكدنا من الدفع») comes from the sender.
     */
    private const BODIES = [
        self::CONFIRM => "أهلاً {first_name}،\nاستلمنا أوردرك رقم {order_number}:\n{items}\nالإجمالي: {total}\nالعنوان: {address}\n{payment}\n\nياريت تأكدي الأوردر عشان نبدأ نجهزه. لو محتاجة تعدلي حاجة أو تلغي الأوردر اختاري من الزراير اللي تحت.",
        self::REMINDER => "أهلاً {first_name}،\nلسه مستنيين تأكيدك لأوردر رقم {order_number}:\n{items}\nالإجمالي: {total}\nالعنوان: {address}\n{payment}\n\nمن فضلك اختاري من الزراير اللي تحت عشان نكمل الأوردر.",
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
                ['type' => 'BODY', 'text' => self::numbered(self::BODIES[$name]), 'example' => ['body_text' => [self::examples()]]],
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

    /**
     * {name} -> {{n}}, n being the name's place in TemplateParams::BODY. Every BODY variable
     * must appear exactly once, so the copy can never drift from what the sender fills in.
     */
    private static function numbered(string $body): string
    {
        $used = [];
        $text = preg_replace_callback('/\{([a-z_]+)\}/', function (array $m) use (&$used) {
            $index = array_search($m[1], TemplateParams::BODY, true);

            if ($index === false) {
                throw new LogicException("Template variable {{$m[1]}} is not in TemplateParams::BODY.");
            }

            $used[] = $m[1];

            return '{{'.($index + 1).'}}';
        }, $body);

        if (count($used) !== count(array_unique($used)) || array_diff(TemplateParams::BODY, $used) !== []) {
            throw new LogicException('The template copy must use every TemplateParams::BODY variable exactly once.');
        }

        return (string) $text;
    }
}
