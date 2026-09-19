<?php

namespace App\Bot\Flows\Sandbox;

/** What one sandbox run would have sent (messages) and done (events). */
final class SandboxLog
{
    /** @var list<array{text:string, buttons:list<array{title:string, payload:string}>, cards:array|null}> */
    public array $messages = [];

    /** @var list<array{type:string, label:string, data:array}> */
    public array $events = [];

    /**
     * @param  list<array{title:string, payload:string}>  $buttons
     * @param  array|null  $cards  rich cards (App\Channels\Cards\OutboundCards), rendered by the sandbox chat
     */
    public function message(string $text, array $buttons = [], ?array $cards = null): void
    {
        $this->messages[] = [
            'text' => $text,
            'buttons' => array_values(array_map(
                fn (array $b) => ['title' => (string) ($b['title'] ?? ''), 'payload' => (string) ($b['payload'] ?? '')],
                $buttons,
            )),
            'cards' => $cards,
        ];
    }

    public function event(string $type, string $label, array $data = []): void
    {
        $this->events[] = ['type' => $type, 'label' => $label, 'data' => $data];
    }
}
