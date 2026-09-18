<?php

namespace App\Bot\Flows;

use App\Models\BotFlow;
use App\Models\BotFlowVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Drafts, publishing and version history for `bot_flows` (flow designer
 * Task 1, design doc §1-2). A flow has at most one `draft` row at a time;
 * publishing copies the draft's definition into `bot_flows.definition`,
 * archives the previously `published` row and marks the draft `published`.
 * Validation (`FlowDefinition::validate` + `validateReferences`) never
 * blocks saving a draft, only publishing and restoring.
 */
final class FlowDrafts
{
    /** The starting definition for a brand-new flow (design doc §2). */
    public const NEW_FLOW_DEFINITION = [
        'start' => 'menu',
        'steps' => [
            'menu' => [
                'type' => 'text',
                'field' => 'answer',
                'text' => 'اكتبي سؤالك هنا',
                'next' => 'end',
            ],
        ],
    ];

    private const KEY_PATTERN = '/^[a-z][a-z0-9_]{2,40}$/';

    private const NOTE_MAX_LENGTH = 200;

    /** The draft definition, or the published one when there is no draft. */
    public function draftFor(BotFlow $flow): array
    {
        $draft = $flow->draft()->first();

        return $draft?->definition ?? $flow->definition;
    }

    /**
     * Creates or updates the single draft row for this flow. Not blocked by
     * validation errors — only the definition's basic shape is enforced.
     *
     * The `BotFlow` row is locked for the duration of the transaction (the
     * same pattern as `QuickReplyController::storeAttachment`) so two
     * concurrent saves can never both miss an existing draft and create two,
     * and the next version number is always computed under that lock.
     */
    public function saveDraft(BotFlow $flow, array $definition, User $by): BotFlowVersion
    {
        if (! is_string($definition['start'] ?? null) || ! is_array($definition['steps'] ?? null)) {
            throw new InvalidArgumentException("Flow definition must have a string 'start' and an array 'steps'.");
        }

        return DB::transaction(function () use ($flow, $definition, $by) {
            BotFlow::query()->whereKey($flow->id)->lockForUpdate()->firstOrFail();

            $draft = $flow->draft()->first();

            if ($draft) {
                $draft->fill(['definition' => $definition, 'created_by_id' => $by->id])->save();

                return $draft->refresh();
            }

            $nextVersion = ((int) $flow->versions()->max('version')) + 1;

            return $flow->versions()->create([
                'version' => $nextVersion,
                'status' => 'draft',
                'definition' => $definition,
                'created_by_id' => $by->id,
            ]);
        });
    }

    /** @return list<string> validate() + validateReferences() combined */
    public function errors(array $def): array
    {
        return [...FlowDefinition::validate($def), ...FlowDefinition::validateReferences($def)];
    }

    /**
     * Publishes the current draft: validates it, archives the previously
     * published version, marks the draft `published` and mirrors its
     * definition onto `bot_flows.definition`.
     *
     * The `BotFlow` row is locked for the whole transaction and the draft is
     * re-read under that lock, so a concurrent publish/save can't race this
     * one into archiving two rows or publishing a draft that no longer
     * exists — "exactly one published row, at most one draft" always holds.
     *
     * @throws FlowValidationException when the draft fails validation
     * @throws InvalidArgumentException when there is no draft to publish, or `$note` exceeds 200 characters
     */
    public function publish(BotFlow $flow, User $by, ?string $note): BotFlowVersion
    {
        $this->assertValidNote($note);

        return DB::transaction(function () use ($flow, $by, $note) {
            BotFlow::query()->whereKey($flow->id)->lockForUpdate()->firstOrFail();

            $draft = $flow->draft()->first();

            if (! $draft) {
                throw new InvalidArgumentException('This flow has no draft to publish.');
            }

            $errors = $this->errors($draft->definition);
            if ($errors !== []) {
                throw new FlowValidationException($errors);
            }

            $flow->publishedVersion()->update(['status' => 'archived']);

            $draft->fill([
                'status' => 'published',
                'published_by_id' => $by->id,
                'published_at' => now(),
                'note' => $note,
            ])->save();

            $flow->fill(['definition' => $draft->definition])->save();

            return $draft->refresh();
        });
    }

    public function discardDraft(BotFlow $flow): void
    {
        $flow->draft()->delete();
    }

    /**
     * Locks the given flow's row (the flow designer's "add to main menu"
     * action, design doc §2), re-reads its draft-or-published definition
     * under that lock, appends `{title, action, synonyms: []}` to its menu
     * step's options and saves the draft — all inside one transaction, so a
     * concurrent append (or any other draft save) can't race and clobber
     * this one.
     *
     * @throws InvalidArgumentException when the flow has no menu step (R-F3), the action already has an option, or 13 options already exist
     */
    public function appendMenuOption(BotFlow $menuFlow, string $title, string $action, User $by): BotFlowVersion
    {
        return DB::transaction(function () use ($menuFlow, $title, $action, $by) {
            $locked = BotFlow::query()->whereKey($menuFlow->id)->lockForUpdate()->firstOrFail();

            $draftRow = $locked->draft()->first();
            $definition = $draftRow?->definition ?? $locked->definition;

            $stepKey = FlowDefinition::menuStepKey($definition);
            if ($stepKey === null) {
                throw new InvalidArgumentException('القائمة الرئيسية مفيهاش خطوة قائمة.');
            }

            $options = $definition['steps'][$stepKey]['options'] ?? [];

            if (collect($options)->contains(fn ($option) => ($option['action'] ?? null) === $action)) {
                throw new InvalidArgumentException('الفلو ده موجود في القائمة الرئيسية بالفعل.');
            }

            if (count($options) >= 13) {
                throw new InvalidArgumentException('القائمة الرئيسية وصلت للحد الأقصى (13) من الخيارات.');
            }

            $options[] = ['title' => $title, 'action' => $action, 'synonyms' => []];
            $definition['steps'][$stepKey]['options'] = $options;

            return $this->saveDraft($locked, $definition, $by);
        });
    }

    /**
     * Publishes a copy of an old (archived) version as a brand-new version,
     * numbered after the current highest. Any existing draft is left alone.
     *
     * The `BotFlow` row is locked for the whole transaction, so the archive
     * of the current published row and the next version number are always
     * computed under that lock — consistent with `publish()`.
     *
     * @throws FlowValidationException when the restored definition fails validation
     * @throws InvalidArgumentException when the generated restore note exceeds 200 characters
     */
    public function restore(BotFlowVersion $version, User $by): BotFlowVersion
    {
        $flow = $version->flow;

        $errors = $this->errors($version->definition);
        if ($errors !== []) {
            throw new FlowValidationException($errors);
        }

        $note = "استرجاع نسخة {$version->version}";
        $this->assertValidNote($note);

        return DB::transaction(function () use ($flow, $version, $by, $note) {
            BotFlow::query()->whereKey($flow->id)->lockForUpdate()->firstOrFail();

            $flow->publishedVersion()->update(['status' => 'archived']);

            $nextVersion = ((int) $flow->versions()->max('version')) + 1;

            $restored = $flow->versions()->create([
                'version' => $nextVersion,
                'status' => 'published',
                'definition' => $version->definition,
                'created_by_id' => $by->id,
                'published_by_id' => $by->id,
                'published_at' => now(),
                'note' => $note,
            ]);

            $flow->fill(['definition' => $version->definition])->save();

            return $restored;
        });
    }

    /**
     * Creates a new flow: from scratch (`NEW_FLOW_DEFINITION`, inactive) or
     * as a copy of an existing flow's published definition.
     *
     * @throws InvalidArgumentException on a malformed or duplicate key
     */
    public function create(string $key, string $titleAr, ?BotFlow $copyFrom, User $by): BotFlow
    {
        if (! preg_match(self::KEY_PATTERN, $key)) {
            throw new InvalidArgumentException("Invalid flow key '{$key}'.");
        }

        if (BotFlow::query()->where('key', $key)->exists()) {
            throw new InvalidArgumentException("Flow key '{$key}' already exists.");
        }

        $definition = $copyFrom?->definition ?? self::NEW_FLOW_DEFINITION;

        return DB::transaction(function () use ($key, $titleAr, $definition, $by) {
            $flow = BotFlow::create([
                'key' => $key,
                'title_ar' => $titleAr,
                'is_active' => false,
                'definition' => $definition,
            ]);

            $flow->versions()->create([
                'version' => 1,
                'status' => 'published',
                'definition' => $definition,
                'created_by_id' => $by->id,
                'published_by_id' => $by->id,
                'published_at' => now(),
            ]);

            return $flow;
        });
    }

    /** @throws InvalidArgumentException when $note is longer than 200 characters */
    private function assertValidNote(?string $note): void
    {
        if ($note !== null && mb_strlen($note) > self::NOTE_MAX_LENGTH) {
            throw new InvalidArgumentException('Note must be at most '.self::NOTE_MAX_LENGTH.' characters.');
        }
    }
}
