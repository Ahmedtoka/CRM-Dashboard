<?php

namespace App\Bot\Learning;

/**
 * What one `bot:learn` run actually did. The command puts it in the container
 * so the caller in the same process — the "تشغيل التعلم الآن" button — can tell
 * a written report from a quiet day from a failed analyst, instead of reading
 * the exit code alone and toasting success for all three.
 */
final class LearningOutcome
{
    public const CREATED = 'created';

    public const SKIPPED = 'skipped';

    public const FAILED = 'failed';

    public function __construct(
        public readonly string $status,
        public readonly string $date,
        public readonly ?int $reportId = null,
        public readonly int $conversations = 0,
        public readonly int $suggestions = 0,
        public readonly ?string $message = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'date' => $this->date,
            'report_id' => $this->reportId,
            'conversations' => $this->conversations,
            'suggestions' => $this->suggestions,
            'message' => $this->message,
        ];
    }
}
