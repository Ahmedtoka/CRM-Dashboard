<?php

namespace App\Http\Controllers\Web\Ads;

use App\Ads\Materials\MaterialService;
use App\Http\Controllers\Controller;
use App\Models\AdMaterial;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Materials x product inventory, with the manual availability override (content and supervisors set it). */
class AdStockController extends Controller
{
    public function index(Request $request, MaterialService $service): Response
    {
        $min = $request->query('min_qty');
        $filters = [
            'min_qty' => is_numeric($min) ? max((int) $min, 0) : null,
            'availability' => in_array($request->query('availability'), ['in', 'out'], true) ? (string) $request->query('availability') : 'all',
        ];
        $page = max((int) $request->query('page', 1), 1);

        return Inertia::render('Ads/Materials/Stock', [
            'filters' => $filters,
            'rows' => $service->stockRows($filters, $page)->withQueryString(),
        ]);
    }

    /** `available` true/false pins the flag by hand; null returns it to following the product's inventory. */
    public function availability(Request $request, AdMaterial $material): RedirectResponse
    {
        abort_unless(MaterialService::canAuthor($request->user()), 403);
        $data = $request->validate(['available' => ['present', 'nullable', 'boolean']]);

        $material->forceFill(['stock_override' => $data['available'] === null ? null : (bool) $data['available']])->save();

        return back()->with('status', __('ads.flash.saved'));
    }
}
