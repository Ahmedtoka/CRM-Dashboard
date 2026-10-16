<?php

namespace App\Push;

use App\Enums\Platform;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Support\Str;

/**
 * Turns a staff notification into the push text of spec §6, in the user's language
 * (lang/{ar,en}/push.php). Privacy rules: the customer's first name only, phone-like
 * digit runs masked everywhere (the same rule as the note.mention excerpt), the body
 * at most 80 characters, and the data payload carries ids only, all as strings.
 */
class PushMessageFactory
{
    public const BODY_LIMIT = 80;

    /** Data keys copied from the notification when present (ids only — never text). */
    private const DATA_KEYS = ['conversation_id', 'entry_id', 'case_id'];

    /** The configured push types (type => [channel, priority]); anything else never pushes. */
    public static function pushable(string $type): bool
    {
        return array_key_exists($type, (array) config('crm.push.types', []));
    }

    public function make(UserNotification $notification, User $user): ?PushMessage
    {
        $type = (string) $notification->type;

        if (! self::pushable($type)) {
            return null;
        }

        $data = is_array($notification->data) ? $notification->data : [];
        $locale = $this->locale($user);
        $key = 'push.'.$type;
        $params = $this->params($type, $data, $locale);
        $title = self::mask((string) trans($key.'.title', $params, $locale));
        $body = self::limit(self::mask((string) trans($key.'.body', $params, $locale)));

        $payload = ['type' => $type, 'notification_id' => (string) $notification->id];
        foreach (self::DATA_KEYS as $k) {
            if (isset($data[$k]) && is_scalar($data[$k]) && (string) $data[$k] !== '') {
                $payload[$k] = (string) $data[$k];
            }
        }

        $config = (array) config('crm.push.types')[$type];

        return new PushMessage(
            title: $title,
            body: $body,
            data: $payload,
            channel: (string) ($config['channel'] ?? 'general'),
            priority: ($config['priority'] ?? 'normal') === 'high' ? 'high' : 'normal',
            collapseKey: isset($payload['conversation_id']) ? 'conversation-'.$payload['conversation_id'] : $type,
        );
    }

    /** The «تجربة إشعار» push of devices/test and crm:push-test. */
    public function test(User $user): PushMessage
    {
        $locale = $this->locale($user);

        return new PushMessage(
            title: (string) trans('push.test.title', [], $locale),
            body: (string) trans('push.test.body', [], $locale),
            data: ['type' => 'push.test'],
            channel: 'general',
            priority: 'high',
            collapseKey: 'push.test',
        );
    }

    /**
     * Masks any run of 7+ digits (ASCII or Arabic-Indic) with `+`, spaces or dashes in
     * between — the rule ConversationActions applies to the note.mention excerpt.
     */
    public static function mask(string $text): string
    {
        return (string) preg_replace_callback('/[+0-9\x{0660}-\x{0669}][+\-\s0-9\x{0660}-\x{0669}]*[0-9\x{0660}-\x{0669}]/u', function (array $m) {
            return preg_match_all('/[0-9\x{0660}-\x{0669}]/u', $m[0]) >= 7 ? '•••' : $m[0];
        }, $text);
    }

    /** ≤ 80 characters in total, the ellipsis included. */
    public static function limit(string $text): string
    {
        return Str::limit($text, self::BODY_LIMIT - 3);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    private function params(string $type, array $data, string $locale): array
    {
        $platform = Platform::tryFrom((string) ($data['platform'] ?? ''));
        $caseType = (string) ($data['type'] ?? '');
        $caseLabelKey = 'cases.types.'.$caseType;
        $caseLabel = $caseType !== '' && trans($caseLabelKey, [], $locale) !== $caseLabelKey ? (string) trans($caseLabelKey, [], $locale) : $caseType;

        return [
            'ticket' => self::scalar($data['ticket'] ?? ''),
            'name' => $type === 'queue.break_overrun'
                ? self::firstName($data['name'] ?? null, (string) trans('push.someone', [], $locale))
                : self::firstName($data['customer_name'] ?? null, (string) trans('push.customer', [], $locale)),
            'platform' => $platform?->label() ?? '',
            'by' => self::firstName($data['by'] ?? null, (string) trans('push.someone', [], $locale)),
            'agent' => self::firstName($data['agent_name'] ?? null, (string) trans('push.someone', [], $locale)),
            'excerpt' => self::scalar($data['excerpt'] ?? ''),
            'minutes' => self::scalar($data['minutes'] ?? ''),
            'id' => self::scalar($data['case_id'] ?? ''),
            'type' => $caseLabel,
        ];
    }

    /** The first word of a name, masked; the fallback when nothing readable is left (e.g. a WhatsApp name that is a number). */
    private static function firstName(mixed $name, string $fallback): string
    {
        $first = Str::of(self::mask(self::scalar($name)))->trim()->explode(' ')->first();
        $first = trim((string) $first);

        return $first === '' || $first === '•••' ? $fallback : Str::limit($first, 30, '');
    }

    private static function scalar(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private function locale(User $user): string
    {
        return in_array($user->locale, ['ar', 'en'], true) ? $user->locale : (string) config('app.locale', 'ar');
    }
}
