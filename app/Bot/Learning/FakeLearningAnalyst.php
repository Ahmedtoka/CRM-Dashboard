<?php

namespace App\Bot\Learning;

/**
 * The offline analyst (no AI driver or no API key): it counts the day's notes
 * and proposes nothing, so `bot:learn` still writes a report the owner can
 * open without ever calling Claude.
 */
class FakeLearningAnalyst implements LearningAnalyst
{
    public function analyze(array $notes, array $catalog): array
    {
        $count = array_sum(array_map('count', $notes));

        return [
            'summary' => "التحليل الآلي مش شغال دلوقتي (مفيش مفتاح Claude)، فده عدّ الملاحظات بس ({$count}) من غير اقتراحات.",
            'stats' => ['top_intents' => []],
            'suggestions' => [],
            'model' => 'fake',
            'input_tokens' => 0,
            'output_tokens' => 0,
        ];
    }
}
