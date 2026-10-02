<?php

namespace App\Http\Controllers\Web\Ads;

use App\Ads\Materials\MaterialService;
use App\Http\Controllers\Controller;
use App\Models\AdMaterial;
use App\Support\CsvSafe;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Materials x product inventory, with the manual availability override (content and supervisors set it). */
class AdStockController extends Controller
{
    public function index(Request $request, MaterialService $service): Response
    {
        $filters = $this->filters($request);
        $page = max((int) $request->query('page', 1), 1);

        return Inertia::render('Ads/Materials/Stock', [
            'filters' => $filters,
            'rows' => $service->stockRows($filters, $page)->withQueryString(),
        ]);
    }

    /** CSV of the (filtered) stock page, streamed in chunks, UTF-8 with a BOM so Excel reads Arabic. */
    public function export(Request $request, MaterialService $service): StreamedResponse
    {
        $query = $service->stockQuery($this->filters($request), false);

        return response()->streamDownload(function () use ($query, $service) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [
                __('ads.materials.stock_csv.title'), __('ads.materials.stock_csv.product'), __('ads.materials.stock_csv.variants'), __('ads.materials.stock_csv.price'),
                __('ads.materials.stock_csv.quantity'), __('ads.materials.stock_csv.collections'), __('ads.materials.stock_csv.availability'),
            ]);
            $query->chunkById(200, function ($chunk) use ($out, $service) {
                foreach ($chunk as $m) {
                    $r = $service->stockRow($m);
                    $price = $r['price']['min'] === null ? '' : ($r['price']['min'] === $r['price']['max'] ? $r['price']['min'] : $r['price']['min'].' - '.$r['price']['max']);
                    fputcsv($out, CsvSafe::row([
                        $r['title'], $r['product']['title'],
                        implode(' | ', array_map(fn ($v) => trim(($v['title'] ?? $v['sku'] ?? '').': '.$v['quantity']), $r['variants'])),
                        $price, $r['quantity'], implode(' | ', array_column($r['collections'], 'name')),
                        __($r['availability'] ? 'ads.materials.stock_csv.yes' : 'ads.materials.stock_csv.no'),
                    ]));
                }
            }, 'ad_materials.id', 'id');
            fclose($out);
        }, 'ad-stock-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** `available` true/false pins the flag by hand; null returns it to following the product's inventory. */
    public function availability(Request $request, AdMaterial $material): RedirectResponse
    {
        abort_unless(MaterialService::canAuthor($request->user()), 403);
        $data = $request->validate(['available' => ['present', 'nullable', 'boolean']]);

        $material->forceFill(['stock_override' => $data['available'] === null ? null : (bool) $data['available']])->save();

        return back()->with('status', __('ads.flash.saved'));
    }

    /** @return array{min_qty: ?int, availability: string} */
    private function filters(Request $request): array
    {
        $min = $request->query('min_qty');

        return [
            'min_qty' => is_numeric($min) ? max((int) $min, 0) : null,
            'availability' => in_array($request->query('availability'), ['in', 'out'], true) ? (string) $request->query('availability') : 'all',
        ];
    }
}
