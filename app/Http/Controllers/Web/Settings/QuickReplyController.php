<?php

namespace App\Http\Controllers\Web\Settings;

use App\Enums\AttachmentType;
use App\Enums\Platform;
use App\Enums\QuickReplyScope;
use App\Http\Controllers\Concerns\RespondsWithData;
use App\Http\Controllers\Controller;
use App\Inbox\SavedReplies\ReplyVariables;
use App\Media\MediaInspector;
use App\Media\MediaPolicy;
use App\Media\MediaRejected;
use App\Media\MediaStorage;
use App\Models\QuickReply;
use App\Models\QuickReplyAttachment;
use App\Models\QuickReplyCategory;
use App\Support\StarterExamples;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Throwable;

/**
 * The saved-replies settings screen (spec §2.3): every signed-in user can
 * reach it (shared is read-only unless supervisor+, personal is always
 * theirs to manage) — every mutation still runs through `QuickReplyPolicy`.
 */
class QuickReplyController extends Controller
{
    use RespondsWithData;

    /** A saved reply takes at most this many attachments. */
    private const MAX_ATTACHMENTS = 5;

    public function index(Request $request): Response
    {
        $user = $request->user();
        $rows = fn (Builder $query) => $query->with(['creator:id,name', 'attachments'])->orderBy('shortcut')->get()
            ->map(fn (QuickReply $r) => $this->row($r))->values();

        return Inertia::render('settings/QuickReplies', [
            'shared' => $rows(QuickReply::query()->where('scope', QuickReplyScope::Shared->value)),
            'personal' => $rows(QuickReply::query()->where('scope', QuickReplyScope::Personal->value)->where('user_id', $user->id)),
            'categories' => QuickReplyCategory::orderBy('sort')->get(['id', 'name', 'sort']),
            'canManageShared' => $user->isSupervisorOrAbove(),
            // Titles the empty state's one-click starter set would add (shared replies).
            'starterExamples' => array_column(StarterExamples::QUICK_REPLIES, 'title'),
            'variables' => collect(ReplyVariables::ALIASES)->map(fn ($alias, $key) => ['key' => $key, 'alias' => $alias])->values(),
        ]);
    }

    public function store(Request $request): HttpResponse
    {
        // Validated as a string/enum *before* anything treats it as a scalar
        // — an array `scope` (e.g. `scope[]=x`) must 422 here, never reach a
        // raw `(string) $array` cast (an "Array to string conversion" trip).
        $request->validate(['scope' => ['sometimes', 'string', Rule::enum(QuickReplyScope::class)]]);
        $scope = (string) $request->input('scope', QuickReplyScope::Shared->value);
        Gate::authorize('create', [QuickReply::class, $scope]);
        $data = $this->validated($request, $scope, null);

        $reply = QuickReply::create($data + [
            'scope' => $scope,
            'user_id' => $scope === QuickReplyScope::Personal->value ? $request->user()->id : null,
            'created_by' => $request->user()->id,
        ]);

        return $this->done($request, $this->row($reply->load(['creator:id,name', 'attachments'])), 201);
    }

    /** Empty-state "add ready-made examples": shared starter replies (supervisor+ route, idempotent). */
    public function examples(Request $request, StarterExamples $examples): HttpResponse
    {
        Gate::authorize('create', [QuickReply::class, QuickReplyScope::Shared->value]);
        $created = $examples->addQuickReplies($request->user());

        return $this->done($request, ['created' => $created], $created > 0 ? 201 : 200);
    }

    public function update(Request $request, QuickReply $quickReply): HttpResponse
    {
        Gate::authorize('update', $quickReply);
        $quickReply->update($this->validated($request, $quickReply->scope->value, $quickReply));

        return $this->done($request, $this->row($quickReply->load(['creator:id,name', 'attachments'])));
    }

    public function destroy(Request $request, QuickReply $quickReply): HttpResponse
    {
        Gate::authorize('delete', $quickReply);
        $paths = $quickReply->attachments()->get(['disk', 'path']);
        $quickReply->delete();
        $paths->each(fn (QuickReplyAttachment $a) => Storage::disk($a->disk)->delete($a->path));

        return $this->done($request, ['id' => $quickReply->id]);
    }

    /**
     * Live preview against a sample customer (spec §2.3) — never persists anything.
     */
    public function preview(Request $request, ReplyVariables $variables): JsonResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:4000']]);
        $rendered = $variables->renderSample($data['body'], $request->user());

        return response()->json(['body' => $rendered->body, 'missing' => $rendered->missing]);
    }

    public function storeAttachment(Request $request, QuickReply $quickReply, MediaInspector $inspector, MediaPolicy $policy, MediaStorage $storage): JsonResponse
    {
        Gate::authorize('update', $quickReply);
        $request->validate(['file' => ['required', 'file', 'max:25600']]);

        $file = $request->file('file');
        $mime = $inspector->sniff((string) $file->getRealPath(), $file->getClientMimeType(), $file->getClientOriginalExtension());
        $type = $policy->assertUploadable($mime, (int) $file->getSize());
        if (! in_array($type, [AttachmentType::Image, AttachmentType::File], true)) {
            throw new MediaRejected(__(MediaPolicy::UNSUPPORTED));
        }

        $disk = $storage->disk();
        $path = str_replace('outbound/', 'replies/', $storage->relativePath('outbound', $mime));
        // Stream straight to disk instead of reading the whole upload into memory.
        Storage::disk($disk)->putFileAs(dirname($path), $file, basename($path));
        [$w, $h] = $inspector->dimensions((string) $file->getRealPath(), $mime);

        try {
            $row = DB::transaction(function () use ($quickReply, $disk, $path, $type, $mime, $file, $w, $h): QuickReplyAttachment {
                // Lock the parent row so two concurrent uploads on the same
                // reply can never both pass the count check and land a 6th.
                QuickReply::query()->whereKey($quickReply->id)->lockForUpdate()->firstOrFail();
                abort_if($quickReply->attachments()->count() >= self::MAX_ATTACHMENTS, 422, __('errors.replies.attachments_max', ['max' => self::MAX_ATTACHMENTS]));

                return $quickReply->attachments()->create([
                    'disk' => $disk,
                    'path' => $path,
                    'type' => $type,
                    'mime' => $mime,
                    'size_bytes' => (int) $file->getSize(),
                    'original_name' => MediaStorage::sanitizeFilename($file->getClientOriginalName()),
                    'width' => $w,
                    'height' => $h,
                    'sort' => (int) $quickReply->attachments()->max('sort') + 1,
                ]);
            });
        } catch (Throwable $e) {
            // The transaction already rolled back any row; the file it would
            // have pointed at must not become an orphan on disk.
            Storage::disk($disk)->delete($path);
            throw $e;
        }

        return response()->json(['data' => $this->attachmentRow($row)], 201);
    }

    public function destroyAttachment(Request $request, QuickReply $quickReply, QuickReplyAttachment $attachment): HttpResponse
    {
        Gate::authorize('update', $quickReply);
        abort_unless($attachment->quick_reply_id === $quickReply->id, 404);

        $disk = $attachment->disk;
        $path = $attachment->path;
        $attachment->delete();
        Storage::disk($disk)->delete($path);

        return $this->done($request, ['id' => $attachment->id]);
    }

    /**
     * @return array{shortcut: string, title: string, body: string, category_id: ?int, platforms: ?array<int, string>}
     */
    private function validated(Request $request, string $scope, ?QuickReply $existing): array
    {
        $request->merge(['shortcut' => ltrim((string) $request->input('shortcut'), '/')]);
        $userId = $request->user()->id;

        $data = $request->validate([
            'scope' => ['sometimes', 'string', Rule::enum(QuickReplyScope::class)],
            'shortcut' => ['required', 'string', 'max:50', 'regex:/^[\pL\pN_-]+$/u', function (string $attr, string $value, Closure $fail) use ($scope, $userId, $existing) {
                $taken = QuickReply::query()
                    ->whereIn('shortcut', [$value, '/'.$value])
                    ->where('scope', $scope)
                    ->when($scope === QuickReplyScope::Personal->value, fn (Builder $q) => $q->where('user_id', $existing?->user_id ?? $userId))
                    ->when($existing, fn (Builder $q) => $q->whereKeyNot($existing->id))
                    ->exists();
                if ($taken) {
                    $fail(__('errors.replies.shortcut_taken'));
                }
            }],
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:4000'],
            'category_id' => ['nullable', 'integer', 'exists:quick_reply_categories,id'],
            'platforms' => ['nullable', 'array'],
            'platforms.*' => [Rule::enum(Platform::class)],
        ]);

        return Arr::except($data, ['scope']);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(QuickReply $reply): array
    {
        return [
            'id' => $reply->id,
            'shortcut' => $reply->shortcut,
            'title' => $reply->title,
            'body' => $reply->body,
            'platforms' => $reply->platforms ?? [],
            'scope' => $reply->scope->value,
            'category_id' => $reply->category_id,
            'use_count' => (int) $reply->use_count,
            'last_used_at' => $reply->last_used_at?->toIso8601String(),
            'creator' => $reply->creator ? ['id' => $reply->creator->id, 'name' => $reply->creator->name] : null,
            'attachments' => $reply->attachments->map(fn (QuickReplyAttachment $a) => $this->attachmentRow($a))->all(),
        ];
    }

    /**
     * @return array{id: int, type: string, original_name: ?string, thumb_url: ?string}
     */
    private function attachmentRow(QuickReplyAttachment $a): array
    {
        return [
            'id' => $a->id,
            'type' => $a->type->value,
            'original_name' => $a->original_name,
            'thumb_url' => $a->type === AttachmentType::Image ? route('quick-reply-attachments.show', $a, false) : null,
        ];
    }
}
