<?php

namespace App\Http\Controllers\Web\Ads;

use App\Ads\Materials\MaterialService;
use App\Http\Controllers\Controller;
use App\Models\AdMaterial;
use App\Models\AdMaterialCollection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Material collections: everyone with materials access reads; content and supervisors manage. */
class MaterialCollectionController extends Controller
{
    public function index(): Response
    {
        $collections = AdMaterialCollection::query()
            ->withCount([
                'materials',
                'materials as activated' => fn ($q) => $q->where('ad_materials.status', 'activated'),
                'materials as not_started' => fn ($q) => $q->where('ad_materials.status', 'not_started'),
                'materials as done' => fn ($q) => $q->where('ad_materials.status', 'done'),
                'materials as need_stop' => fn ($q) => $q->whereNotNull('ad_materials.need_stop_at'),
            ])
            ->orderBy('sort')->orderBy('name')->get()
            ->map(fn (AdMaterialCollection $c) => [
                'id' => $c->id, 'name' => $c->name, 'is_active' => $c->is_active,
                'materials' => (int) $c->materials_count, 'activated' => (int) $c->activated, 'not_started' => (int) $c->not_started,
                'need_stop' => (int) $c->need_stop, 'done' => (int) $c->done,
            ])->all();

        $all = AdMaterial::query()->toBase()->selectRaw(
            "COUNT(*) as materials, COALESCE(SUM(CASE WHEN status = 'activated' THEN 1 ELSE 0 END), 0) as activated, "
            ."COALESCE(SUM(CASE WHEN status = 'not_started' THEN 1 ELSE 0 END), 0) as not_started, "
            ."COALESCE(SUM(CASE WHEN status = 'done' THEN 1 ELSE 0 END), 0) as done, "
            .'COALESCE(SUM(CASE WHEN need_stop_at IS NOT NULL THEN 1 ELSE 0 END), 0) as need_stop'
        )->first();

        return Inertia::render('Ads/Materials/Collections', [
            'collections' => $collections,
            // Over every material (a material in two collections counts once), not the sum of the rows.
            'totals' => [
                'collections' => count($collections), 'materials' => (int) $all->materials, 'activated' => (int) $all->activated,
                'not_started' => (int) $all->not_started, 'need_stop' => (int) $all->need_stop, 'done' => (int) $all->done,
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeWrite($request);
        $data = $request->validate(['name' => ['required', 'string', 'max:100'], 'is_active' => ['nullable', 'boolean']]);

        AdMaterialCollection::create([
            'name' => $data['name'], 'is_active' => $data['is_active'] ?? true, 'sort' => ((int) AdMaterialCollection::max('sort')) + 1,
        ]);

        return back()->with('status', __('ads.flash.saved'));
    }

    public function update(Request $request, AdMaterialCollection $collection): RedirectResponse
    {
        $this->authorizeWrite($request);
        $data = $request->validate(['name' => ['required', 'string', 'max:100'], 'is_active' => ['nullable', 'boolean']]);

        $collection->update(['name' => $data['name'], 'is_active' => $data['is_active'] ?? $collection->is_active]);

        return back()->with('status', __('ads.flash.saved'));
    }

    public function destroy(Request $request, AdMaterialCollection $collection): RedirectResponse
    {
        $this->authorizeWrite($request);
        $collection->delete(); // detaches its materials, never deletes them

        return back()->with('status', __('ads.flash.deleted'));
    }

    private function authorizeWrite(Request $request): void
    {
        abort_unless(MaterialService::canAuthor($request->user()), 403);
    }
}
