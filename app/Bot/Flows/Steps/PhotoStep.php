<?php

namespace App\Bot\Flows\Steps;

use App\Enums\AttachmentType;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Support\Collection;

/**
 * Waits for an image: saves the burst's image attachment ids in `field`
 * (`['legacy']` when only the old `messages.attachments` json has one). A
 * reply without an image re-asks once, then continues with `field_missing`.
 */
final class PhotoStep extends BaseStep
{
    /** Whether a single message carries an image: a media attachment, or a legacy `attachments` json entry. */
    public static function hasImage(Message $m): bool
    {
        if ($m->mediaAttachments()->where('type', AttachmentType::Image->value)->exists()) {
            return true;
        }

        return collect((array) $m->attachments)->contains(fn ($a) => is_array($a) && ($a['type'] ?? null) === 'image');
    }

    public function answer(Conversation $c, array $state, array $step, string $text, Collection $burst): ?StepOutcome
    {
        $ids = [];
        $legacy = false;

        foreach ($burst as $m) {
            /** @var Message $m */
            array_push($ids, ...$m->mediaAttachments()->where('type', AttachmentType::Image->value)->pluck('id')->map(fn ($id) => (int) $id)->all());
            $legacy = $legacy || collect((array) $m->attachments)->contains(fn ($a) => is_array($a) && ($a['type'] ?? null) === 'image');
        }

        if ($ids === [] && ! $legacy) {
            return null;
        }

        $field = (string) $step['field'];

        return StepOutcome::continue([$field => $ids !== [] ? $ids : ['legacy'], $field.'_missing' => null]);
    }

    public function unresolved(Conversation $c, array $state, array $step, string $text): StepOutcome
    {
        if ($state['retries'] >= 1) {
            return StepOutcome::continue([(string) $step['field'].'_missing' => true]);
        }

        return StepOutcome::wait([$this->prompt($state, $step)], $state['retries'] + 1);
    }
}
