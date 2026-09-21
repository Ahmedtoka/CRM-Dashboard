<?php

namespace App\Channels\Cards;

/**
 * Rich outbound cards a bot message may carry (`messages.cards`, the owner's flows 5 and 6,
 * 2026-09-19). Two shapes:
 *
 *   {type: "generic", cards: [{title, subtitle, text, image_url, url, buttons}]} — a carousel (the branch
 *                                                   cards; the product cards carry `image_url` and `url`)
 *   {type: "button",  buttons: [...]}                               — the message text with link buttons
 *
 * A button is {type: "web_url", title, url}, {type: "phone", title, phone} (phone in +20… form) or
 * {type: "postback", title, payload} (a tap comes back as an inbound message with that payload).
 * A card's `image_url` must be a public https image; its `url` opens when the card itself is tapped.
 * A card's `text` is its full plain-text version, sent where cards do not exist (WhatsApp, the
 * text fallback). The message body always holds the plain-text fallback of the whole message.
 * Each channel adapter decides how much of this it can show (see the Messenger, Instagram and
 * WhatsApp adapters); Messenger's limits are kept here.
 */
final class OutboundCards
{
    public const MAX_CARDS = 10;

    public const MAX_BUTTONS = 3;

    public const TITLE_MAX = 80;

    public const SUBTITLE_MAX = 80;

    public const BUTTON_TITLE_MAX = 20;

    /** Messenger's button-template text limit. */
    public const BUTTON_TEXT_MAX = 640;

    /**
     * @param  list<array{title:string, subtitle?:?string, text?:?string, buttons?:list<array<string, string>>}>  $cards
     * @return array{type:'generic', cards:list<array<string, mixed>>}
     */
    public static function generic(array $cards): array
    {
        return ['type' => 'generic', 'cards' => array_values(array_slice(array_map(fn (array $card) => [
            'title' => mb_substr(trim((string) ($card['title'] ?? '')), 0, self::TITLE_MAX),
            'subtitle' => filled($card['subtitle'] ?? null) ? mb_substr(trim((string) $card['subtitle']), 0, self::SUBTITLE_MAX) : null,
            'text' => filled($card['text'] ?? null) ? (string) $card['text'] : null,
            'image_url' => self::httpsUrl($card['image_url'] ?? null),
            'url' => self::httpsUrl($card['url'] ?? null),
            'buttons' => self::buttons((array) ($card['buttons'] ?? [])),
        ], $cards), 0, self::MAX_CARDS))];
    }

    /** @return array{type:'button', buttons:list<array<string, string>>} */
    public static function button(array $buttons): array
    {
        return ['type' => 'button', 'buttons' => self::buttons($buttons)];
    }

    public static function webUrl(string $title, string $url): array
    {
        return ['type' => 'web_url', 'title' => $title, 'url' => $url];
    }

    public static function postback(string $title, string $payload): array
    {
        return ['type' => 'postback', 'title' => $title, 'payload' => $payload];
    }

    public static function call(string $title, string $phone): ?array
    {
        $e164 = self::phone($phone);

        return $e164 !== null ? ['type' => 'phone', 'title' => $title, 'phone' => $e164] : null;
    }

    /** An Egyptian number as +20…: "01094538159" / "201094538159" / "+20 109 453 8159" → "+201094538159". */
    public static function phone(string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', strtr($phone, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9'])) ?? '';

        return match (true) {
            $digits === '' => null,
            str_starts_with($digits, '20') && strlen($digits) >= 11 => '+'.$digits,
            str_starts_with($digits, '0') => '+20'.substr($digits, 1),
            default => '+20'.$digits,
        };
    }

    /** Meta and WhatsApp fetch card images themselves: only a public https URL is kept. */
    private static function httpsUrl(mixed $url): ?string
    {
        return is_string($url) && str_starts_with($url, 'https://') ? $url : null;
    }

    /** A usable cards value (a known type with something to show), else null. */
    public static function valid(mixed $cards): ?array
    {
        if (! is_array($cards)) {
            return null;
        }

        return match ($cards['type'] ?? null) {
            'generic' => is_array($cards['cards'] ?? null) && $cards['cards'] !== [] ? $cards : null,
            'button' => is_array($cards['buttons'] ?? null) && $cards['buttons'] !== [] ? $cards : null,
            default => null,
        };
    }

    /** The web_url buttons as plain lines under a text ("🛍️ تسوقي من الموقع:\nhttps://…"). */
    public static function withLinkLines(string $text, array $cards): string
    {
        $lines = [];

        foreach ((array) ($cards['buttons'] ?? []) as $b) {
            if (($b['type'] ?? null) === 'web_url' && filled($b['url'] ?? null) && ! str_contains($text, (string) $b['url'])) {
                $lines[] = rtrim((string) $b['title'], ' :').":\n".$b['url'];
            }
        }

        return $lines === [] ? $text : rtrim($text)."\n\n".implode("\n\n", $lines);
    }

    /** One plain text per card (WhatsApp, the fallback): the card's own text, else its title and subtitle and buttons. */
    public static function cardText(array $card): string
    {
        if (filled($card['text'] ?? null)) {
            return (string) $card['text'];
        }

        $lines = array_filter([(string) ($card['title'] ?? ''), (string) ($card['subtitle'] ?? '')], 'filled');

        foreach ((array) ($card['buttons'] ?? []) as $b) {
            $lines[] = match ($b['type'] ?? null) {
                'web_url' => $b['url'] ?? '',
                'phone' => $b['phone'] ?? '',
                default => '',
            };
        }

        return implode("\n", array_filter($lines, 'filled'));
    }

    /** @return list<array<string, string>> web_url/phone/postback buttons with a title, at most 3, titles cut to 20 */
    private static function buttons(array $buttons): array
    {
        $out = [];

        foreach ($buttons as $b) {
            if (! is_array($b) || ! filled($b['title'] ?? null)) {
                continue;
            }

            $title = mb_substr(trim((string) $b['title']), 0, self::BUTTON_TITLE_MAX);

            if (($b['type'] ?? null) === 'web_url' && filled($b['url'] ?? null)) {
                $out[] = ['type' => 'web_url', 'title' => $title, 'url' => (string) $b['url']];
            } elseif (($b['type'] ?? null) === 'phone' && filled($b['phone'] ?? null)) {
                $out[] = ['type' => 'phone', 'title' => $title, 'phone' => (string) $b['phone']];
            } elseif (($b['type'] ?? null) === 'postback' && filled($b['payload'] ?? null)) {
                $out[] = ['type' => 'postback', 'title' => $title, 'payload' => (string) $b['payload']];
            }
        }

        return array_slice($out, 0, self::MAX_BUTTONS);
    }
}
