<?php

namespace App\Channels\Adapters\Concerns;

use App\Channels\Cards\OutboundCards;
use App\Channels\Data\SendResult;
use App\Models\ChannelAccount;
use App\Models\CustomerIdentity;

/**
 * Rich cards on Messenger and Instagram (the owner's flows 5 and 6, 2026-09-19):
 * a `generic` cards value is sent as a generic-template carousel (at most 10 cards),
 * a `button` value as a button template holding the message text. Quick replies ride
 * along. A template the platform refuses falls back to the plain-text message (the
 * body, with link lines for a button template), so the customer always gets it.
 * Instagram has no call button: its cards keep web_url buttons only (the phone is in
 * the subtitle already).
 */
trait SendsMetaCards
{
    /** Instagram: false (no phone_number buttons). */
    abstract protected function supportsCallButtons(): bool;

    /** The plain sendText path (no cards). */
    abstract protected function sendPlainText(ChannelAccount $account, CustomerIdentity $to, string $text, array $options): SendResult;

    abstract protected function postMessage(ChannelAccount $account, array $payload): SendResult;

    protected function sendCards(ChannelAccount $account, CustomerIdentity $to, string $text, array $cards, array $options): SendResult
    {
        $attachment = $this->templateAttachment($text, $cards);

        if ($attachment === null) {
            return $this->sendPlainText($account, $to, $this->fallbackText($text, $cards), $options);
        }

        $payload = [
            'recipient' => ['id' => $to->external_id],
            'message' => ['attachment' => $attachment],
            'messaging_type' => isset($options['tag']) ? 'MESSAGE_TAG' : 'RESPONSE',
        ];

        if (isset($options['tag'])) {
            $payload['tag'] = $options['tag'];
        }

        if (! empty($options['quick_replies'])) {
            $payload['message']['quick_replies'] = $this->metaQuickReplies($options['quick_replies']);
        }

        $result = $this->postMessage($account, $payload);

        // Expired credentials break every send alike: report them as they are.
        if ($result->success || $result->authError) {
            return $result;
        }

        return $this->sendPlainText($account, $to, $this->fallbackText($text, $cards), $options);
    }

    /** @return list<array{content_type:string, title:string, payload:string}> */
    protected function metaQuickReplies(array $buttons): array
    {
        return array_map(fn (array $b) => [
            'content_type' => 'text',
            'title' => mb_substr((string) $b['title'], 0, 20),
            'payload' => mb_substr((string) $b['payload'], 0, 1000),
        ], array_slice($buttons, 0, 13));
    }

    private function fallbackText(string $text, array $cards): string
    {
        return ($cards['type'] ?? null) === 'button' ? OutboundCards::withLinkLines($text, $cards) : $text;
    }

    /** The template attachment, or null when nothing fits a template (the plain text goes instead). */
    private function templateAttachment(string $text, array $cards): ?array
    {
        if (($cards['type'] ?? null) === 'button') {
            $buttons = $this->metaButtons((array) $cards['buttons']);

            if ($buttons === [] || trim($text) === '' || mb_strlen($text) > OutboundCards::BUTTON_TEXT_MAX) {
                return null;
            }

            return ['type' => 'template', 'payload' => ['template_type' => 'button', 'text' => $text, 'buttons' => $buttons]];
        }

        $elements = [];

        foreach (array_slice((array) ($cards['cards'] ?? []), 0, OutboundCards::MAX_CARDS) as $card) {
            $element = ['title' => mb_substr((string) ($card['title'] ?? ''), 0, OutboundCards::TITLE_MAX)];

            if (filled($card['subtitle'] ?? null)) {
                $element['subtitle'] = mb_substr((string) $card['subtitle'], 0, OutboundCards::SUBTITLE_MAX);
            }

            $buttons = $this->metaButtons((array) ($card['buttons'] ?? []));

            if ($buttons !== []) {
                $element['buttons'] = $buttons;
            }

            if ($element['title'] !== '' && (isset($element['subtitle']) || isset($element['buttons']))) {
                $elements[] = $element;
            }
        }

        return $elements === [] ? null : ['type' => 'template', 'payload' => ['template_type' => 'generic', 'elements' => $elements]];
    }

    /** @return list<array<string, string>> */
    private function metaButtons(array $buttons): array
    {
        $out = [];

        foreach ($buttons as $b) {
            $title = mb_substr((string) ($b['title'] ?? ''), 0, OutboundCards::BUTTON_TITLE_MAX);

            if (($b['type'] ?? null) === 'web_url' && filled($b['url'] ?? null)) {
                $out[] = ['type' => 'web_url', 'url' => (string) $b['url'], 'title' => $title];
            } elseif (($b['type'] ?? null) === 'phone' && filled($b['phone'] ?? null) && $this->supportsCallButtons()) {
                $out[] = ['type' => 'phone_number', 'title' => $title, 'payload' => (string) $b['phone']];
            }
        }

        return array_slice($out, 0, OutboundCards::MAX_BUTTONS);
    }
}
