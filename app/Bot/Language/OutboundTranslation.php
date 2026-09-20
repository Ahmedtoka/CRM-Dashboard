<?php

namespace App\Bot\Language;

use App\Models\Conversation;

/**
 * The one place a bot message is turned into the conversation's language
 * (design 2026-09-21 §1-2): the body, every button title, and every card title,
 * subtitle, text and button title. Called from OutboundService::sendBot (and from
 * the flow sandbox, so a simulated English run says exactly what a real one would).
 *
 * Only Arabic is translated: a reply the agent model already wrote in English, a
 * link, a price or a product name in Latin letters passes through untouched.
 */
final class OutboundTranslation
{
    public function __construct(
        private readonly BotTranslator $translator,
        private readonly ConversationLanguage $language,
    ) {}

    /**
     * @param  list<array{title:string, payload:string}>  $buttons
     * @return array{0:string, 1:array, 2:?array} body, buttons, cards
     */
    public function apply(Conversation $c, string $body, array $buttons = [], ?array $cards = null): array
    {
        $locale = $this->language->of($c);

        if ($locale === LanguageDetector::AR) {
            return [$body, $buttons, $cards];
        }

        // One collected list, so a whole message costs one engine call.
        $texts = [$body];
        $short = [false];
        $slots = [['body']];

        foreach ($buttons as $i => $button) {
            $texts[] = (string) ($button['title'] ?? '');
            $short[] = true;
            $slots[] = ['button', $i];
        }

        foreach ((array) ($cards['cards'] ?? []) as $i => $card) {
            foreach (['title' => true, 'subtitle' => true, 'text' => false] as $key => $isShort) {
                if (filled($card[$key] ?? null)) {
                    $texts[] = (string) $card[$key];
                    // A card title is not a button: it may run to 80 characters.
                    $short[] = false;
                    $slots[] = ['card', $i, $key];
                }
            }

            foreach ((array) ($card['buttons'] ?? []) as $j => $cardButton) {
                if (filled($cardButton['title'] ?? null)) {
                    $texts[] = (string) $cardButton['title'];
                    $short[] = true;
                    $slots[] = ['card_button', $i, $j];
                }
            }
        }

        foreach ((array) ($cards['buttons'] ?? []) as $i => $button) {
            if (filled($button['title'] ?? null)) {
                $texts[] = (string) $button['title'];
                $short[] = true;
                $slots[] = ['cards_button', $i];
            }
        }

        $done = $this->translator->many($texts, $locale, 'outbound', $short);

        foreach ($slots as $k => $slot) {
            $value = $done[$k] ?? $texts[$k];

            match ($slot[0]) {
                'body' => $body = $value,
                'button' => $buttons[$slot[1]]['title'] = $value,
                'card' => $cards['cards'][$slot[1]][$slot[2]] = $value,
                'card_button' => $cards['cards'][$slot[1]]['buttons'][$slot[2]]['title'] = $value,
                'cards_button' => $cards['buttons'][$slot[1]]['title'] = $value,
                default => null,
            };
        }

        return [$body, $buttons, $cards];
    }
}
