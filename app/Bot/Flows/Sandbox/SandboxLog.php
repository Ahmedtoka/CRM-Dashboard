<?php

namespace App\Bot\Flows\Sandbox;

/** What one sandbox run would have sent (messages) and done (events). */
final class SandboxLog
{
    /** @var list<array{text:string, buttons:list<array{title:string, payload:string}>}> */
    public array $messages = [];

    /** @var list<array{type:string, label:string, data:array}> */
    public array $events = [];

    /** @param  list<array{title:string, payload:string}>  $buttons */
    public function message(string $text, array $buttons = []): void
    {
        $this->messages[] = [
            'text' => $text,
            'buttons' => array_values(array_map(
                fn (array $b) => ['title' => (string) ($b['title'] ?? ''), 'payload' => (string) ($b['payload'] ?? '')],
                $buttons,
            )),
        ];
    }

    public function event(string $type, string $label, array $data = []): void
    {
        $this->events[] = ['type' => $type, 'label' => $label, 'data' => $data];
    }
}
