<?php

namespace App\Bot\Learning;

use App\Bot\Flow\Concerns\CallsClaudeJson;
use DomainException;

/**
 * One Claude call over a day of conversation notes (design §6, learning v2 §2). The prompt is
 * deliberately narrow: only facts a human agent or an existing script
 * already stated, never an invented price, policy or promise. Anything the
 * model returns is still run through `SuggestionValidator` before it is
 * stored, and through it again before it is applied.
 */
class ClaudeLearningAnalyst implements LearningAnalyst
{
    use CallsClaudeJson;

    public const MAX_SUGGESTIONS = 15;

    /**
     * Bug fix (2026-09-17): a 4000-token cap truncated the response before it
     * finished (`stop_reason: max_tokens`), so the JSON never parsed and the
     * command saved an empty report. Shown to the owner via `LearnCommand`.
     */
    public const TRUNCATED_MESSAGE = 'الرد اتقطع قبل ما يخلص — جرب تاني أو قلل عدد المحادثات';

    public const SYSTEM_PROMPT = <<<'PROMPT'
        You review one day of Le Voile customer-service conversations (Egyptian Arabic) handled by a bot and human agents. You get short notes taken from each conversation, grouped by kind (unanswered, wrong_answer, agent_knowledge, new_phrasing, flow_friction), each with its conversation_id, a summary, a quote and, for agent_knowledge, the human agent's answer. Write a short Arabic summary for the owner (what customers asked most, where the bot failed or handed over, what agents answered that the bot could not), at most 800 characters. Then propose at most 8 concrete improvements, each grounded in the conversations, as JSON suggestions of these types only: script_text (target = existing script key, proposed.body = full new text), new_faq (proposed = {key: snake_case, title, body, keywords[]}), intent_keywords (target = existing intent key, proposed.add = new phrasings seen), flow_step (target = flow_key.step_id, proposed = {text?, options?: [{index, title}]}). Use only facts stated by human agents or existing scripts; never invent prices, policies or promises. Each suggestion has reason (Arabic, at most 200 characters) and evidence (conversation_ids + at most 2 short quotes, at most 120 characters each).
        PROMPT;

    public function __construct(
        private readonly ?string $apiKey,
        private readonly string $model,
        private readonly int $timeout = 60,
    ) {}

    /** @throws DomainException when the output cap truncated the response or the JSON never parsed */
    public function analyze(array $notes, array $catalog): array
    {
        $result = $this->claudeJson(
            $this->apiKey,
            $this->model,
            $this->timeout,
            12000,
            self::SYSTEM_PROMPT,
            [['role' => 'user', 'content' => $this->userMessage($notes, $catalog)]],
            self::schema(),
        );

        if ($result['stop_reason'] === 'max_tokens' || $result['json'] === null) {
            throw new DomainException(self::TRUNCATED_MESSAGE);
        }

        $json = $result['json'];

        return [
            'summary' => is_string($json['summary'] ?? null) ? $json['summary'] : '',
            'stats' => is_array($json['stats'] ?? null) ? $json['stats'] : [],
            'suggestions' => array_slice(array_values(array_filter((array) ($json['suggestions'] ?? []), 'is_array')), 0, self::MAX_SUGGESTIONS),
            'model' => $this->model,
            'input_tokens' => $result['input_tokens'],
            'output_tokens' => $result['output_tokens'],
        ];
    }

    private function userMessage(array $notes, array $catalog): string
    {
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

        return "NOTES FROM THE DAY'S CONVERSATIONS (kind => notes):\n".json_encode($notes, $flags)
            ."\n\nCURRENT SCRIPTS (key => text):\n".json_encode($catalog['scripts'] ?? [], $flags)
            ."\n\nCURRENT INTENTS (key => keywords):\n".json_encode($catalog['intents'] ?? [], $flags)
            ."\n\nCURRENT FLOW STEPS (flow_key => step_id => {text, options}):\n".json_encode($catalog['flows'] ?? [], $flags);
    }

    /**
     * `proposed` and `evidence` are free-form objects on purpose: their shape
     * differs per suggestion type and `SuggestionValidator` is what enforces it.
     *
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'summary' => ['type' => 'string'],
                'stats' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'conversations' => ['type' => 'integer'],
                        'handovers' => ['type' => 'integer'],
                        'cases' => ['type' => 'integer'],
                        'top_intents' => ['type' => 'array', 'items' => ['type' => 'string']],
                    ],
                    'required' => ['conversations', 'handovers', 'cases', 'top_intents'],
                ],
                'suggestions' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'type' => ['type' => 'string', 'enum' => ['script_text', 'new_faq', 'intent_keywords', 'flow_step']],
                            'target' => ['type' => ['string', 'null']],
                            'proposed' => ['type' => 'object'],
                            'reason' => ['type' => 'string'],
                            'evidence' => [
                                'type' => 'object',
                                'additionalProperties' => false,
                                'properties' => [
                                    'conversation_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
                                    'quote' => ['type' => 'string'],
                                ],
                                'required' => ['conversation_ids', 'quote'],
                            ],
                        ],
                        'required' => ['type', 'target', 'proposed', 'reason', 'evidence'],
                    ],
                ],
            ],
            'required' => ['summary', 'stats', 'suggestions'],
        ];
    }
}
