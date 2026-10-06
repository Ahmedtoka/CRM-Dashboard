<?php

namespace App\Http\Controllers\Web\Ads;

use App\Ads\Access\AdsScope;
use App\Ads\Control\Write\WriteDenied;
use App\Ads\Launch\LaunchService;
use App\Ads\Launch\LaunchState;
use App\Ads\Launch\MaterialStatus;
use App\Ads\Materials\MaterialService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ads\StoreMaterialRequest;
use App\Http\Requests\Ads\UpdateMaterialRequest;
use App\Models\AdMaterial;
use App\Models\AdMaterialCollection;
use App\Models\AdMaterialFile;
use App\Models\MediaBuyer;
use App\Models\Product;
use App\Support\CsvSafe;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** The materials library (spec section 8, screen 7): list, form, files, status, linked ads, export. */
class MaterialController extends Controller
{
    private const FILTERS = ['q', 'status', 'stock', 'collection', 'type', 'from', 'to'];

    public function index(Request $request, MaterialService $service, AdsScope $scope): Response
    {
        $user = $request->user();
        $filters = $this->filters($request);

        $page = $service->withRelations($service->query($filters))
            ->paginate(MaterialService::PER_PAGE)->withQueryString();
        // Files are already eager loaded (thumbnail); sending them lets the list open its gallery without another request.
        $page->setCollection(collect($service->rows($page->getCollection(), $user, true)));

        return Inertia::render('Ads/Materials/Index', [
            'filters' => $filters + ['page' => $page->currentPage()],
            'stats' => $service->stats(),
            'materials' => $page,
            'collections' => AdMaterialCollection::query()->orderBy('sort')->orderBy('name')->get(['id', 'name'])
                ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name])->all(),
            'canSeeSpend' => $scope->canSeeSpend($user),
        ]);
    }

    public function create(Request $request, MaterialService $service): Response
    {
        abort_unless(MaterialService::canAuthor($request->user()), 403);

        return $this->form($service, null, $request);
    }

    public function edit(Request $request, AdMaterial $material, MaterialService $service): Response
    {
        abort_unless(MaterialService::canAuthor($request->user()), 403);

        return $this->form($service, $material, $request);
    }

    public function store(StoreMaterialRequest $request, MaterialService $service): RedirectResponse
    {
        $service->create($request->safe()->except(['files']), $request->uploads(), $request->user());

        return redirect()->route('ads.materials.index')->with('status', __('ads.flash.saved'));
    }

    public function update(UpdateMaterialRequest $request, AdMaterial $material, MaterialService $service): RedirectResponse
    {
        $service->update($material, $request->safe()->except(['files', 'remove_file_ids']), $request->uploads(), $request->removeFileIds());

        return redirect()->route('ads.materials.index')->with('status', __('ads.flash.saved'));
    }

    public function destroy(Request $request, AdMaterial $material, MaterialService $service): RedirectResponse
    {
        $user = $request->user();
        $own = $material->created_by_id !== null && $material->created_by_id === $user->id && MaterialService::canAuthor($user);
        abort_unless($user->isSupervisorOrAbove() || $own, 403);
        if (($reason = $service->deleteBlockedReason($material)) !== null) {
            return back()->withErrors(['material' => __('ads.materials.delete_blocked.'.$reason)]);
        }
        $service->delete($material);

        return redirect()->route('ads.materials.index')->with('status', __('ads.flash.deleted'));
    }

    /** Retire a material (خلصت): its live launches are stopped and retired, drafts withdrawn; a launch waiting for a decision blocks it. */
    public function retire(Request $request, AdMaterial $material, LaunchService $launches, MaterialStatus $status): RedirectResponse
    {
        $user = $request->user();
        abort_unless(MaterialService::canOperate($user), 403);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        $key = (string) $request->header('Idempotency-Key', 'retire-'.$material->id.'-'.now()->format('YmdHi'));

        $pending = $material->launches()->whereIn('state', ['creating_paused', 'awaiting_approval', 'launching'])->exists();
        if ($pending) {
            return back()->withErrors(['material' => __('ads.errors.launches_pending')]);
        }
        try {
            foreach ($material->launches()->whereIn('state', ['live', 'stopped'])->get() as $l) {
                $launches->retire($user, $l, $data['reason'] ?? null, $key);
            }
            // The material retire is an operator decision: open drafts end whoever holds them (withdrawn; a held one expires).
            foreach ($material->launches()->whereIn('state', ['draft', 'changes_requested', 'buyer_review', 'create_failed', 'on_hold'])->get() as $l) {
                $from = $l->state;
                $to = $from === LaunchState::OnHold ? LaunchState::Expired : LaunchState::Withdrawn;
                $l = $launches->transition($l, [$from], $to, ['decided_by_id' => $user->id, 'decided_at' => now(), 'hold_from_state' => null], null, $user, ['by' => 'material_retired']);
                if ($from === LaunchState::CreateFailed) {
                    $launches->archive($l);
                }
            }
        } catch (WriteDenied $e) {
            return back()->withErrors(['material' => $e->getMessage()]);
        }
        $material->forceFill(['status' => 'retired', 'retired_by_id' => $user->id, 'retire_reason' => $data['reason'] ?? null, 'done_at' => now(), 'need_stop_at' => null])->save();
        $status->refresh($material->fresh());

        return back()->with('status', __('ads.flash.saved'));
    }

    public function syncAds(Request $request, AdMaterial $material, MaterialService $service): RedirectResponse
    {
        $user = $request->user();
        abort_unless(MaterialService::canOperate($user), 403);
        $data = $request->validate(['ad_ids' => ['present', 'array', 'max:200'], 'ad_ids.*' => ['integer', Rule::exists('ads', 'id')]]);

        abort_unless($service->syncAds($material, $data['ad_ids'], $user), 403);

        return back()->with('status', __('ads.flash.saved'));
    }

    public function adSearch(Request $request, MaterialService $service): JsonResponse
    {
        abort_unless(MaterialService::canOperate($request->user()), 403);

        return response()->json($service->searchAds($request->user(), (string) $request->query('q', '')));
    }

    public function productSearch(Request $request): JsonResponse
    {
        $term = trim((string) $request->query('q', ''));
        $like = '%'.addcslashes($term, '%_\\').'%';

        $rows = Product::query()->where('status', 'active')
            ->when($term !== '', fn ($q) => $q->where('title', 'like', $like))
            ->withSum('variants as inventory', 'inventory_quantity')
            ->orderBy('title')->limit(20)->get(['id', 'title', 'image_url'])
            ->map(fn (Product $p) => ['id' => $p->id, 'title' => $p->title, 'image_url' => $p->image_url, 'inventory' => (int) ($p->inventory ?? 0)]);

        return response()->json($rows->values());
    }

    /** Streams the stored file (HTTP Range works through BinaryFileResponse, so video seeks). */
    public function file(AdMaterialFile $file): SymfonyResponse
    {
        return $this->serve($file->disk, $file->path, (string) $file->mime);
    }

    /** The thumbnail; the original when it is an image without one, 404 for a video without a poster. */
    public function thumb(AdMaterialFile $file): SymfonyResponse
    {
        if ($file->thumb_path !== null && Storage::disk($file->disk)->exists($file->thumb_path)) {
            return $this->serve($file->disk, $file->thumb_path, str_ends_with($file->thumb_path, '.webp') ? 'image/webp' : 'image/jpeg');
        }
        abort_unless(str_starts_with((string) $file->mime, 'image/'), 404);

        return $this->file($file);
    }

    /** CSV of the (filtered) library, streamed in chunks, UTF-8 with a BOM so Excel reads Arabic. */
    public function export(Request $request, MaterialService $service, AdsScope $scope): StreamedResponse
    {
        $user = $request->user();
        $spend = $scope->canSeeSpend($user);
        $query = $service->withRelations($service->query($this->filters($request)), false)->reorder('ad_materials.id');

        return response()->streamDownload(function () use ($query, $service, $user, $spend) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, array_merge([
                __('ads.materials.csv.title'), __('ads.materials.csv.created'), __('ads.materials.csv.product'), __('ads.materials.csv.collections'),
                __('ads.materials.csv.types'), __('ads.materials.csv.status'), __('ads.materials.csv.stock'), __('ads.materials.csv.drive_links'), __('ads.materials.csv.ads'),
            ], $spend ? [__('ads.materials.csv.spend'), __('ads.materials.csv.roas')] : []));

            $query->chunkById(200, function ($chunk) use ($out, $service, $user, $spend) {
                foreach ($service->rows($chunk, $user) as $r) {
                    $perf = $r['performance'];
                    fputcsv($out, CsvSafe::row(array_merge([
                        $r['title'], substr((string) $r['created_at'], 0, 10), $r['product']['title'] ?? '',
                        implode(' | ', array_column($r['collections'], 'name')), implode(' | ', $r['types']),
                        __('ads.materials.status.'.$r['status']), __('ads.materials.stock.'.$r['stock']),
                        implode(' | ', $r['drive_links']), implode(' | ', array_column($r['ads'], 'name')),
                    ], $spend ? [$perf['spend'] ?? '', $perf['roas'] ?? ''] : [])));
                }
            }, 'ad_materials.id', 'id');
            fclose($out);
        }, 'ad-materials-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array<string, ?string> */
    private function filters(Request $request): array
    {
        $out = [];
        foreach (self::FILTERS as $key) {
            $v = $request->query($key);
            $out[$key] = is_scalar($v) && trim((string) $v) !== '' ? trim((string) $v) : null;
        }
        if ($out['status'] !== null && ! in_array($out['status'], MaterialService::STATUSES, true)) {
            $out['status'] = null;
        }
        if ($out['stock'] !== null && ! in_array($out['stock'], ['in', 'out', 'none'], true)) {
            $out['stock'] = null;
        }
        if ($out['type'] !== null && ! in_array($out['type'], MaterialService::TYPES, true)) {
            $out['type'] = null;
        }
        if ($out['collection'] !== null && ! ctype_digit($out['collection'])) {
            $out['collection'] = null;
        }

        return $out;
    }

    private function form(MaterialService $service, ?AdMaterial $material, Request $request): Response
    {
        $row = null;
        if ($material !== null) {
            $loaded = $service->withRelations(AdMaterial::query()->whereKey($material->id))->get();
            $row = $service->rows($loaded, $request->user(), true)[0];
        }
        $attached = $material?->collections()->pluck('ad_material_collections.id')->all() ?? [];

        return Inertia::render('Ads/Materials/Form', [
            'material' => $row,
            'collections' => AdMaterialCollection::query()->where(fn ($q) => $q->where('is_active', true)->orWhereIn('id', $attached))
                ->orderBy('sort')->orderBy('name')->get(['id', 'name'])->map(fn ($c) => ['id' => $c->id, 'name' => $c->name])->all(),
            'types' => MaterialService::TYPES,
            'buyers' => MediaBuyer::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])
                ->map(fn ($b) => ['id' => $b->id, 'name' => $b->name])->all(),
            // Upload limits (MB) for the client-side pre-check; the request validates again.
            'limits' => [
                'image_mb' => (int) config('crm.ads.material_max_mb.image', 20),
                'video_mb' => (int) config('crm.ads.material_max_mb.video', 500),
                // What PHP really accepts for one request: above it the POST is refused (413) before
                // validation, so the page stops the upload itself and says why.
                'post_mb' => self::postMaxMb(),
            ],
        ]);
    }

    /** The smaller of post_max_size and upload_max_filesize, in MB (null when unlimited). */
    private static function postMaxMb(): ?int
    {
        $bytes = fn (string $v): int => (int) $v * match (strtolower(substr(trim($v), -1))) {
            'g' => 1024 ** 3, 'm' => 1024 ** 2, 'k' => 1024, default => 1,
        };
        $limits = array_filter([$bytes((string) ini_get('post_max_size')), $bytes((string) ini_get('upload_max_filesize'))], fn (int $b) => $b > 0);

        return $limits === [] ? null : intdiv(min($limits), 1024 ** 2);
    }

    private function serve(string $diskName, string $path, string $mime): SymfonyResponse
    {
        $disk = Storage::disk($diskName);
        abort_unless($disk->exists($path), 404);

        $headers = [
            'Content-Type' => $mime !== '' ? $mime : 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => 'sandbox',
            'Cache-Control' => 'private, max-age=3600',
        ];
        if ($disk instanceof FilesystemAdapter && $disk->getAdapter() instanceof LocalFilesystemAdapter) {
            $response = response()->file($disk->path($path), $headers);
            $response->setPrivate();

            return $response;
        }

        return $disk->response($path, null, $headers);
    }
}
