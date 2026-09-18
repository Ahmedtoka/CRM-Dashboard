<?php

namespace App\Bot\Flows;

use App\Bot\ArabicNormalizer;

/** Offline interpreter: only recognises the exit words, everything else is unknown. */
class FakeFlowAnswerInterpreter implements FlowAnswerInterpreter
{
    /** Normalized forms of القائمة / منيو / الغاء. */
    private const EXIT_WORDS = ['القائمه', 'منيو', 'الغاء'];

    public function __construct(private readonly ArabicNormalizer $normalizer) {}

    public function interpret(array $step, string $text, array $history): FlowAnswer
    {
        $normalized = $this->normalizer->normalize($text);

        foreach (self::EXIT_WORDS as $word) {
            if (str_contains($normalized, $word)) {
                return new FlowAnswer('exit');
            }
        }

        return FlowAnswer::unknown();
    }
}
