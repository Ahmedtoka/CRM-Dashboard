<?php

namespace App\Bot\Learning;

/**
 * The offline reviewer (no AI driver or no API key): it writes no notes, so a
 * finished conversation is still marked reviewed without ever calling Claude.
 * A test can hand it notes to return and read back what it was asked.
 */
class FakeConversationReviewer implements ConversationReviewer
{
    /** @var list<array{conversation_id:int, lines: list<string>, intents: list<string>, flows: list<string>}> */
    public array $reviewed = [];

    /** @param  list<array{kind:string, summary:string, quote:string, agent_answer?:string}>  $notes */
    public function __construct(private array $notes = [], private string $model = 'fake', private int $inputTokens = 0, private int $outputTokens = 0) {}

    public function review(array $transcript): array
    {
        $this->reviewed[] = $transcript;

        return [
            'notes' => $this->notes,
            'model' => $this->model,
            'input_tokens' => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
        ];
    }
}
