<?php

namespace App\Ads\Materials;

use App\Ads\Access\AdsScope;
use App\Ads\Launch\LaunchState;
use App\Ads\Launch\MaterialStatus;
use App\Enums\UserRole;
use App\Models\Ad;
use App\Models\AdMaterial;
use App\Models\AdMaterialFile;
use App\Models\ProductVariant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/** The materials library: queries and filters, presentation rows, create/update/delete with files, status, ad links, stock. */
final class MaterialService
{
    public const TYPES = ['reel', 'carousel', 'post', 'story', 'image', 'video'];

    public const STATUSES = MaterialStatus::VALUES;

    public const PER_PAGE = 15;

    public const STOCK_PER_PAGE = 10;

    public function __construct(
        private readonly MaterialFileStorage $files,
        private readonly AdsScope $scope,
        private readonly MaterialPerformance $performance,
    ) {}

    /** Content users and supervisors+ author materials, collections and the manual stock flag. */
    public static function canAuthor(User $u): bool
    {
        return $u->isSupervisorOrAbove() || $u->role === UserRole::Content;
    }

    /** Media buyers and supervisors+ run materials: status, ad links. */
    public static function canOperate(User $u): bool
    {
        return $u->isSupervisorOrAbove() || $u->role === UserRole::MediaBuyer;
    }

    // ---- stock ----------------------------------------------------------------------------------------------

    /** Sum of the product's variants' inventory, as a correlated subquery on ad_materials. */
    public static function inventorySql(): string
    {
        return '(SELECT COALESCE(SUM(pv.inventory_quantity), 0) FROM product_variants pv WHERE pv.product_id = ad_materials.product_id)';
    }

    /** 'none' (no live product) | 'out' (manual override off, or inventory <= 0) | 'in' (override on, or inventory > 0). */
    public static function stockSql(): string
    {
        return "CASE WHEN NOT EXISTS (SELECT 1 FROM products p WHERE p.id = ad_materials.product_id AND p.deleted_at IS NULL) THEN 'none' "
            ."WHEN ad_materials.stock_override IS NOT NULL THEN (CASE WHEN ad_materials.stock_override = 1 THEN 'in' ELSE 'out' END) "
            .'WHEN '.self::inventorySql()." > 0 THEN 'in' ELSE 'out' END";
    }

    /** The same rule in PHP, for a material with its `product` (and an `inventory` sum) loaded. */
    public static function stockOf(AdMaterial $m): string
    {
        if ($m->product === null) {
            return 'none';
        }
        if ($m->stock_override !== null) {
            return $m->stock_override ? 'in' : 'out';
        }

        return (int) ($m->product->inventory ?? 0) > 0 ? 'in' : 'out';
    }

    // ---- queries ----------------------------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $f  q, status, stock, collection, type, from, to
     * @return Builder<AdMaterial>
     */
    public function query(array $f): Builder
    {
        $q = AdMaterial::query()->orderByDesc('ad_materials.id');

        if (($term = trim((string) ($f['q'] ?? ''))) !== '') {
            $like = '%'.addcslashes($term, '%_\\').'%';
            $q->where(fn (Builder $w) => $w->where('ad_materials.title', 'like', $like)
                ->orWhereHas('product', fn (Builder $p) => $p->where('title', 'like', $like)));
        }
        if (in_array($f['status'] ?? null, self::STATUSES, true)) {
            $q->where('ad_materials.status', $f['status']);
        }
        if (in_array($f['stock'] ?? null, ['in', 'out', 'none'], true)) {
            $q->whereRaw('('.self::stockSql().') = ?', [$f['stock']]);
        }
        if (is_numeric($f['collection'] ?? null)) {
            $q->whereHas('collections', fn (Builder $c) => $c->where('ad_material_collections.id', (int) $f['collection']));
        }
        if (in_array($f['type'] ?? null, self::TYPES, true)) {
            $q->whereJsonContains('ad_materials.types', $f['type']);
        }
        if (($from = $this->cairoDay($f['from'] ?? null)) !== null) {
            $q->where('ad_materials.created_at', '>=', $from->startOfDay()->utc());
        }
        if (($to = $this->cairoDay($f['to'] ?? null)) !== null) {
            $q->where('ad_materials.created_at', '<=', $to->endOfDay()->utc());
        }

        return $q;
    }

    /** @return array{total:int, activated:int, not_started:int, done:int, in_review:int, paused:int, reels:int, posts:int, carousels:int, in_stock:int, out_of_stock:int, need_stop:int} */
    public function stats(): array
    {
        $stock = self::stockSql();
        $s = DB::table('ad_materials')->selectRaw(
            'COUNT(*) as total, '
            ."COALESCE(SUM(CASE WHEN status = 'live' THEN 1 ELSE 0 END), 0) as live, "
            ."COALESCE(SUM(CASE WHEN status IN ('new', 'in_review') THEN 1 ELSE 0 END), 0) as not_started, "
            ."COALESCE(SUM(CASE WHEN status = 'in_review' THEN 1 ELSE 0 END), 0) as in_review, "
            ."COALESCE(SUM(CASE WHEN status = 'paused' THEN 1 ELSE 0 END), 0) as paused, "
            ."COALESCE(SUM(CASE WHEN status = 'retired' THEN 1 ELSE 0 END), 0) as done, "
            ."COALESCE(SUM(CASE WHEN need_stop_at IS NOT NULL AND status = 'live' THEN 1 ELSE 0 END), 0) as need_stop, "
            ."COALESCE(SUM(CASE WHEN ({$stock}) = 'in' THEN 1 ELSE 0 END), 0) as in_stock, "
            ."COALESCE(SUM(CASE WHEN ({$stock}) = 'out' THEN 1 ELSE 0 END), 0) as out_of_stock"
        )->first();

        return [
            'total' => (int) $s->total, 'activated' => (int) $s->live, 'not_started' => (int) $s->not_started, 'done' => (int) $s->done,
            'in_review' => (int) $s->in_review, 'paused' => (int) $s->paused,
            'reels' => $this->typeCount('reel'), 'posts' => $this->typeCount('post'), 'carousels' => $this->typeCount('carousel'),
            'in_stock' => (int) $s->in_stock, 'out_of_stock' => (int) $s->out_of_stock, 'need_stop' => (int) $s->need_stop,
        ];
    }

    private function typeCount(string $type): int
    {
        return AdMaterial::query()->whereJsonContains('types', $type)->count();
    }

    /**
     * Relations the presentation rows need.
     *
     * @param  Builder<AdMaterial>  $q
     * @return Builder<AdMaterial>
     */
    public function withRelations(Builder $q, bool $files = true): Builder
    {
        $with = [
            'product' => fn ($p) => $p->select(['products.id', 'products.title', 'products.image_url'])->withSum('variants as inventory', 'inventory_quantity')
                ->with('variants:id,product_id,title,inventory_quantity'),
            'collections:ad_material_collections.id,ad_material_collections.name',
            'buyer:id,name', 'creator:id,name',
            'ads:ads.id,ads.name,ads.status,ads.effective_status,ads.ad_account_id', 'ads.account:id,platform',
        ];
        if ($files) {
            $with[] = 'files';
        }

        return $q->with($with);
    }

    // ---- presentation -----------------------------------------------------------------------------------------

    /**
     * MaterialRow for a set of materials (relations loaded by withRelations), performance computed in batch.
     *
     * @param  Collection<int, AdMaterial>  $materials
     * @return list<array<string, mixed>>
     */
    public function rows(Collection $materials, User $user, bool $withFiles = false): array
    {
        // A media buyer only sees the linked ads of their own accounts (never another buyer's ads).
        if ($user->role === UserRole::MediaBuyer) {
            $allowed = $this->scope->accountIds($user) ?? [];
            foreach ($materials as $m) {
                $m->setRelation('ads', $m->ads->filter(fn (Ad $a) => in_array($a->ad_account_id, $allowed, true))->values());
            }
        }
        $perf = $this->performance->forMaterials($materials, $user);

        return $materials->map(function (AdMaterial $m) use ($perf, $withFiles) {
            $row = [
                'id' => $m->id,
                'title' => $m->title,
                'created_at' => $m->created_at?->toIso8601String(),
                'thumb_url' => $this->thumbUrl($m->files->first()),
                'files_count' => $m->files->count(),
                'product' => $m->product === null ? null : [
                    'id' => $m->product->id, 'title' => $m->product->title, 'image_url' => $m->product->image_url, 'inventory' => (int) ($m->product->inventory ?? 0),
                    'variants' => $m->product->relationLoaded('variants')
                        ? $m->product->variants->map(fn (ProductVariant $v) => ['id' => $v->id, 'title' => $v->title, 'inventory' => (int) $v->inventory_quantity])->values()->all()
                        : [],
                ],
                'collections' => $m->collections->map(fn ($c) => ['id' => $c->id, 'name' => $c->name])->values()->all(),
                'types' => array_values((array) $m->types),
                'status' => $m->status,
                'drive_links' => array_values((array) $m->drive_links),
                'website_links' => array_values((array) $m->website_links),
                'ig_links' => array_values((array) $m->ig_links),
                'content_notes' => $m->content_notes,
                'buyer' => $m->buyer === null ? null : ['id' => $m->buyer->id, 'name' => $m->buyer->name],
                'creator' => $m->creator === null ? null : ['id' => $m->creator->id, 'name' => $m->creator->name],
                'stock' => self::stockOf($m),
                'need_stop' => $m->need_stop_at !== null && $m->status === 'live',
                'activated_at' => $m->activated_at?->toIso8601String(),
                'done_at' => $m->done_at?->toIso8601String(),
                'ads' => $m->ads->map(fn (Ad $a) => [
                    'id' => $a->id, 'name' => $a->name, 'platform' => (string) $a->account?->platform, 'status' => $a->effective_status ?? $a->status,
                ])->values()->all(),
                'performance' => $perf[$m->id] ?? null,
            ];
            if ($withFiles) {
                $row['files'] = $m->files->map(fn (AdMaterialFile $f) => $this->fileRow($f))->values()->all();
            }

            return $row;
        })->values()->all();
    }

    /** @return array{id:int, url:string, thumb_url:?string, mime:?string, original_name:?string, size:?int} */
    public function fileRow(AdMaterialFile $f): array
    {
        return [
            'id' => $f->id, 'url' => route('ads.materials.files.show', $f->id, false), 'thumb_url' => $this->thumbUrl($f),
            'mime' => $f->mime, 'original_name' => $f->original_name, 'size' => $f->size,
        ];
    }

    private function thumbUrl(?AdMaterialFile $f): ?string
    {
        if ($f === null) {
            return null;
        }
        if ($f->thumb_path !== null) {
            return route('ads.materials.files.thumb', $f->id, false);
        }

        return str_starts_with((string) $f->mime, 'image/') ? route('ads.materials.files.show', $f->id, false) : null;
    }

    // ---- writes -----------------------------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $data  validated fields (title, product_id, types, links, content_notes, media_buyer_id, collection_ids)
     * @param  list<UploadedFile>  $uploads
     */
    public function create(array $data, array $uploads, User $by): AdMaterial
    {
        $stored = [];
        try {
            return DB::transaction(function () use ($data, $uploads, $by, &$stored) {
                $m = AdMaterial::create($this->fields($data) + ['status' => 'new', 'created_by_id' => $by->id]);
                $m->collections()->sync($data['collection_ids'] ?? []);
                foreach ($uploads as $upload) {
                    $stored[] = $this->files->store($m, $upload);
                }

                return $m;
            });
        } catch (Throwable $e) {
            // The transaction rolled the rows back; do not leave the bytes behind.
            foreach ($stored as $f) {
                $this->files->deleteFromDisk($f->disk, $f->path, $f->thumb_path);
            }
            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<UploadedFile>  $uploads
     * @param  list<int>  $removeFileIds
     */
    public function update(AdMaterial $m, array $data, array $uploads, array $removeFileIds): AdMaterial
    {
        $stored = [];
        try {
            DB::transaction(function () use ($m, $data, $uploads, $removeFileIds, &$stored) {
                $m->update($this->fields($data));
                $m->collections()->sync($data['collection_ids'] ?? []);

                $gone = $m->files()->whereIn('id', $removeFileIds)->get();
                $m->files()->whereIn('id', $gone->pluck('id'))->delete();
                $this->deleteFilesAfterCommit($gone);

                foreach ($uploads as $upload) {
                    $stored[] = $this->files->store($m, $upload);
                }
            });
        } catch (Throwable $e) {
            foreach ($stored as $f) {
                $this->files->deleteFromDisk($f->disk, $f->path, $f->thumb_path);
            }
            throw $e;
        }

        return $m->refresh();
    }

    public function delete(AdMaterial $m): void
    {
        DB::transaction(function () use ($m) {
            $files = $m->files()->get();
            $m->delete(); // files, collection and ad links cascade
            $this->deleteFilesAfterCommit($files);
        });
    }

    /** E14 / C9: a material with an open launch or a running ad cannot be deleted (retire it first). */
    public function deleteBlockedReason(AdMaterial $m): ?string
    {
        if ($m->launches()->whereIn('state', LaunchState::NON_TERMINAL_VALUES)->exists()) {
            return 'launches_open';
        }

        return $m->ads()->whereRaw("UPPER(ads.status) = 'ACTIVE'")->exists() ? 'ads_live' : null;
    }

    /**
     * Replaces the material's linked ads. A media buyer manages only the ads of their own accounts: links to other
     * buyers' ads stay untouched. Returns false when a buyer asked for an ad outside their accounts.
     *
     * @param  list<int>  $adIds
     */
    public function syncAds(AdMaterial $m, array $adIds, User $by): bool
    {
        $adIds = array_values(array_unique(array_map('intval', $adIds)));
        $allowed = $this->scope->accountIds($by);

        if ($allowed !== null) {
            $inScope = Ad::query()->whereIn('ad_account_id', $allowed)->pluck('id')->map(fn ($id) => (int) $id)->all();
            if (array_diff($adIds, $inScope) !== []) {
                return false;
            }
            $keep = $m->ads()->pluck('ads.id')->map(fn ($id) => (int) $id)->reject(fn ($id) => in_array($id, $inScope, true))->all();
            $adIds = array_values(array_unique([...$adIds, ...$keep]));
        }
        $m->ads()->sync($adIds);

        return true;
    }

    /**
     * At most 20 ads (name or external id match) among the accounts the user may see.
     *
     * @return list<array<string, mixed>>
     */
    public function searchAds(User $user, string $term): array
    {
        $allowed = $this->scope->accountIds($user);
        $term = trim($term);
        $like = '%'.addcslashes($term, '%_\\').'%';

        return Ad::query()->with('account:id,name,platform')
            ->when($allowed !== null, fn (Builder $q) => $q->whereIn('ad_account_id', $allowed))
            ->when($term !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w->where('name', 'like', $like)->orWhere('external_id', 'like', $like)))
            ->orderByDesc('id')->limit(20)->get()
            ->map(fn (Ad $a) => [
                'id' => $a->id, 'name' => $a->name, 'external_id' => $a->external_id,
                'platform' => (string) $a->account?->platform, 'account' => $a->account?->name,
                'status' => $a->effective_status ?? $a->status, 'thumbnail_url' => $a->thumbnail_url,
            ])->values()->all();
    }

    // ---- stock page -------------------------------------------------------------------------------------------

    /**
     * @param  array{min_qty:?int, availability:string}  $f
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function stockRows(array $f, int $page): LengthAwarePaginator
    {
        return $this->stockQuery($f)->orderByDesc('ad_materials.id')
            ->paginate(self::STOCK_PER_PAGE, ['*'], 'page', $page)
            ->through(fn (AdMaterial $m) => $this->stockRow($m));
    }

    /**
     * Materials with a live product, filtered like the stock page (no order: the page sorts, the export chunks by id).
     *
     * @param  array{min_qty:?int, availability:string}  $f
     * @return Builder<AdMaterial>
     */
    public function stockQuery(array $f, bool $files = true): Builder
    {
        $q = AdMaterial::query()
            ->select('ad_materials.*')
            ->selectRaw(self::inventorySql().' as inventory_total')
            ->whereRaw('('.self::stockSql().") <> 'none'")
            ->with(array_merge(['collections:ad_material_collections.id,ad_material_collections.name', 'product.variants'], $files ? ['files'] : []));
        if (in_array($f['availability'], ['in', 'out'], true)) {
            $q->whereRaw('('.self::stockSql().') = ?', [$f['availability']]);
        }
        if ($f['min_qty'] !== null) {
            $q->whereRaw(self::inventorySql().' >= ?', [$f['min_qty']]);
        }

        return $q;
    }

    /**
     * One stock row; `override` is the manual pin (true / false) or null when it follows the inventory.
     *
     * @return array<string, mixed>
     */
    public function stockRow(AdMaterial $m): array
    {
        $variants = $m->product->variants;
        $prices = $variants->pluck('price')->map(fn ($p) => (float) $p);
        $m->product->setAttribute('inventory', (int) $m->inventory_total);

        return [
            'material_id' => $m->id,
            'title' => $m->title,
            'thumb_url' => $m->relationLoaded('files') ? $this->thumbUrl($m->files->first()) : null,
            'product' => ['id' => $m->product->id, 'title' => $m->product->title],
            'variants' => $variants->map(fn (ProductVariant $v) => ['id' => $v->id, 'title' => $v->title, 'sku' => $v->sku, 'price' => (float) $v->price, 'quantity' => (int) $v->inventory_quantity])->values()->all(),
            'price' => ['min' => $prices->min(), 'max' => $prices->max()],
            'quantity' => (int) $m->inventory_total,
            'collections' => $m->collections->map(fn ($c) => ['id' => $c->id, 'name' => $c->name])->values()->all(),
            'availability' => self::stockOf($m) === 'in',
            'override' => $m->stock_override,
        ];
    }

    // ---- helpers ----------------------------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function fields(array $data): array
    {
        $out = [];
        foreach (['title', 'product_id', 'types', 'website_links', 'drive_links', 'ig_links', 'content_notes', 'media_buyer_id'] as $k) {
            if (array_key_exists($k, $data)) {
                $out[$k] = $data[$k];
            }
        }
        foreach (['website_links', 'drive_links', 'ig_links'] as $k) {
            if (array_key_exists($k, $out)) {
                $out[$k] = array_values((array) $out[$k]);
            }
        }

        return $out;
    }

    /** @param  Collection<int, AdMaterialFile>  $files */
    private function deleteFilesAfterCommit(Collection $files): void
    {
        if ($files->isEmpty()) {
            return;
        }
        $targets = $files->map(fn (AdMaterialFile $f) => [$f->disk, $f->path, $f->thumb_path])->all();
        DB::afterCommit(function () use ($targets) {
            foreach ($targets as [$disk, $path, $thumb]) {
                $this->files->deleteFromDisk($disk, $path, $thumb);
            }
        });
    }

    private function cairoDay(mixed $v): ?CarbonImmutable
    {
        if (! is_string($v) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
            return null;
        }
        try {
            return CarbonImmutable::createFromFormat('!Y-m-d', $v, 'Africa/Cairo') ?: null;
        } catch (Throwable) {
            return null;
        }
    }
}
