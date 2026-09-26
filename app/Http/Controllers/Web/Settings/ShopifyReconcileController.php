<?php

namespace App\Http\Controllers\Web\Settings;

use App\Http\Controllers\Controller;
use App\Shopify\Client\ShopifyException;
use App\Shopify\Connection\IntegrationRepository;
use App\Shopify\Sync\BulkImporter;
use App\Shopify\Sync\OrderReconciler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Settings → Shopify → «مطابقة الطلبات»: Shopify's own order counts against the
 * CRM's, day by day and by status, with the missing order numbers. Admin only.
 * The last result is kept so reopening the page shows it without re-asking
 * Shopify; "bring them" reuses the orders range import (shopify.sync).
 */
class ShopifyReconcileController extends Controller
{
    private const CACHE_KEY = 'shopify:reconcile:last';

    /** Shopify is asked once per day of the range: keep one check reasonable. */
    private const MAX_DAYS = 62;

    public function __construct(
        private readonly OrderReconciler $reconciler,
        private readonly IntegrationRepository $integrations,
    ) {}

    public function index(): InertiaResponse
    {
        $today = now(BulkImporter::shopTimezone());

        return Inertia::render('settings/ShopifyReconcile', [
            'connected' => $this->integrations->current()?->status === 'connected',
            'result' => Cache::get(self::CACHE_KEY),
            'defaults' => ['from' => $today->copy()->startOfMonth()->toDateString(), 'to' => $today->toDateString()],
            'importState' => $this->integrations->current()?->import_state,
        ]);
    }

    public function run(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $days = now()->parse($data['from'])->diffInDays(now()->parse($data['to'])) + 1;

        if ($days > self::MAX_DAYS) {
            return response()->json(['ok' => false, 'error' => __('errors.shopify.reconcile_range_max', ['days' => self::MAX_DAYS])], 422);
        }

        if ($this->integrations->current()?->status !== 'connected') {
            return response()->json(['ok' => false, 'error' => __('errors.shopify.no_integration')], 404);
        }

        try {
            $result = $this->reconciler->compare($data['from'], $data['to']);
        } catch (ShopifyException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 502);
        }

        Cache::put(self::CACHE_KEY, $result, now()->addDays(7));

        return response()->json(['ok' => true, 'result' => $result]);
    }
}
