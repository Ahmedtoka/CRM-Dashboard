<?php

namespace App\Http\Controllers\Web\Settings;

use App\Bot\BotPreview;
use App\Bot\Knowledge\KnowledgeDefaults;
use App\Bot\Knowledge\SizeChart;
use App\Enums\AttachmentType;
use App\Enums\Platform;
use App\Http\Controllers\Concerns\RespondsWithData;
use App\Http\Controllers\Controller;
use App\Media\MediaInspector;
use App\Media\MediaPolicy;
use App\Media\MediaRejected;
use App\Media\MediaResponder;
use App\Media\MediaStorage;
use App\Models\BotKnowledgeEntry;
use App\Models\BotSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Throwable;

/**
 * Bot knowledge entries and size chart (spec §4.2): the store's policy
 * answers and size chart, editable by a supervisor and read-only for the
 * bot (Task 11) through KnowledgeBase/SizeChart.
 */
class BotKnowledgeController extends Controller
{
    use RespondsWithData;

    /** Size-chart image formats sendable on every platform (WhatsApp: jpeg/png only). */
    public const SIZE_CHART_MIMES = ['image/jpeg', 'image/png'];

    /** WhatsApp's image limit, the strictest of all platforms. */
    public const SIZE_CHART_MAX_BYTES = 5 * 1024 * 1024;

    public function index(): Response
    {
        $settings = BotSetting::current();

        return Inertia::render('settings/BotKnowledge', [
            'entries' => BotKnowledgeEntry::orderBy('sort')->orderBy('id')->get(),
            'sizeChart' => SizeChart::fromSettings($settings)->toArray(),
            'sizeChartImageUrl' => $settings->size_chart_image_path ? route('bot.size-chart-image', [], false).'?v='.$settings->updated_at?->timestamp : null,
            'coreKeys' => KnowledgeDefaults::CORE_KEYS,
        ]);
    }

    public function storeEntry(Request $request): HttpResponse
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9_]+$/', 'unique:bot_knowledge_entries,key'],
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:5000'],
        ]);

        return $this->done($request, BotKnowledgeEntry::create($data + ['is_active' => true, 'is_template' => false, 'sort' => (int) BotKnowledgeEntry::max('sort') + 10]), 201);
    }

    public function updateEntry(Request $request, BotKnowledgeEntry $entry): HttpResponse
    {
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'body' => ['sometimes', 'string', 'max:5000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        if (array_key_exists('body', $data) || array_key_exists('title', $data)) {
            $data['is_template'] = false;
        }
        $entry->update($data);

        return $this->done($request, $entry->fresh());
    }

    public function destroyEntry(Request $request, BotKnowledgeEntry $entry): HttpResponse
    {
        abort_if(in_array($entry->key, KnowledgeDefaults::CORE_KEYS, true), 422, 'المعلومة الأساسية دي مينفعش تتمسح — ممكن توقفيها بس');
        $entry->delete();

        return $this->done($request, ['id' => $entry->id]);
    }

    public function updateSizeChart(Request $request): HttpResponse
    {
        $data = $request->validate(SizeChart::rules($request->all()));
        $settings = BotSetting::current();
        $settings->update(['size_chart' => [
            'unit' => $data['unit'],
            'columns' => array_values($data['columns']),
            'rows' => array_map(fn ($r) => array_map(fn ($v) => (string) $v, array_values($r)), array_values($data['rows'])),
            'note' => $data['note'] ?? null,
        ]]);

        return $this->done($request, SizeChart::fromSettings($settings)->toArray());
    }

    public function storeSizeChartImage(Request $request, MediaInspector $inspector, MediaStorage $storage): HttpResponse
    {
        // Only what every platform accepts (final fix wave I4): WhatsApp rejects
        // webp/gif images and anything over 5 MB, so the bot could otherwise
        // never send the chart there. The mime is sniffed, never trusted.
        $request->validate(['file' => ['required', 'file', 'max:'.intdiv(self::SIZE_CHART_MAX_BYTES, 1024)]]);
        $file = $request->file('file');
        $mime = $inspector->sniff((string) $file->getRealPath(), $file->getClientMimeType(), $file->getClientOriginalExtension());
        if (! in_array($mime, self::SIZE_CHART_MIMES, true)) {
            throw new MediaRejected(MediaPolicy::UNSUPPORTED);
        }
        if ((int) $file->getSize() > self::SIZE_CHART_MAX_BYTES) {
            throw new MediaRejected(strtr(MediaPolicy::TOO_BIG, [':max' => (string) intdiv(self::SIZE_CHART_MAX_BYTES, 1024 * 1024)]));
        }
        $settings = BotSetting::current();
        $oldPath = $settings->size_chart_image_path;

        // Write the new file and commit the row before touching the old file,
        // so a failed row update never leaves the store pointing at nothing —
        // and the just-written file is cleaned up if that update does fail.
        $path = 'bot/size-chart-'.Str::uuid().'.'.$inspector->extensionFor($mime);
        Storage::disk($storage->disk())->put($path, (string) file_get_contents((string) $file->getRealPath()));
        try {
            $settings->update(['size_chart_image_path' => $path, 'size_chart_image_mime' => $mime]);
        } catch (Throwable $e) {
            Storage::disk($storage->disk())->delete($path);
            throw $e;
        }
        if ($oldPath) {
            Storage::disk($storage->disk())->delete($oldPath);
        }

        return $this->done($request, ['url' => route('bot.size-chart-image', [], false)]);
    }

    public function destroySizeChartImage(Request $request, MediaStorage $storage): HttpResponse
    {
        $settings = BotSetting::current();
        if ($settings->size_chart_image_path) {
            Storage::disk($storage->disk())->delete($settings->size_chart_image_path);
        }
        $settings->update(['size_chart_image_path' => null, 'size_chart_image_mime' => null]);

        return $this->done($request, ['url' => null]);
    }

    /** "اسألي البوت": what the bot would do with this text — nothing is sent or stored. */
    public function ask(Request $request, BotPreview $preview): JsonResponse
    {
        $data = $request->validate([
            'text' => ['required', 'string', 'max:1000'],
            'platform' => ['required', Rule::enum(Platform::class)],
        ]);

        return response()->json($preview->run($data['text'], Platform::from($data['platform'])));
    }

    /**
     * Open to any signed-in user (not just supervisor+): the bot hands this
     * same image to customers as an outbound attachment (Task 11), so a
     * moderator working the inbox needs to be able to preview it too.
     */
    public function showSizeChartImage(MediaStorage $storage): BinaryFileResponse
    {
        $settings = BotSetting::current();
        abort_unless($settings->size_chart_image_path && Storage::disk($storage->disk())->exists($settings->size_chart_image_path), 404);

        return MediaResponder::file($storage->disk(), $settings->size_chart_image_path, AttachmentType::Image, $settings->size_chart_image_mime, null);
    }
}
