<?php

namespace App\Http\Controllers\Web\Ads;

use App\Ads\Alerts\AlertScope;
use App\Ads\Alerts\AlertStore;
use App\Ads\Reports\AdsFilter;
use App\Http\Controllers\Controller;
use App\Models\AdsAlert;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/** Card actions of «محتاج قرار»: seen, later (بعدين), disagree (مش موافق). Scope per AlertScope: outside = 404. */
class AlertController extends Controller
{
    public function seen(Request $request, AlertScope $scope, AlertStore $store): JsonResponse
    {
        foreach ($this->load($request, $scope) as $a) {
            $store->markSeen($a, $request->user());
        }

        return response()->json(['ok' => true]);
    }

    public function snooze(Request $request, AlertScope $scope, AlertStore $store): JsonResponse
    {
        $data = $request->validate(['until' => ['required', Rule::in(['tomorrow', '3d', '7d'])]]);
        $now = CarbonImmutable::now(AdsFilter::TIMEZONE);
        $until = match ($data['until']) {
            'tomorrow' => $now->addDay()->setTime(9, 0),
            '3d' => $now->addDays(3),
            default => $now->addDays(7),
        };
        foreach ($this->load($request, $scope) as $a) {
            if ($a->isLive()) {
                $store->snooze($a, $request->user(), $until);
            }
        }

        return response()->json(['ok' => true, 'until' => $until->toIso8601String()]);
    }

    public function dismiss(Request $request, AlertScope $scope, AlertStore $store): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', Rule::in(AlertStore::DISMISS_REASONS)],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $alerts = $this->load($request, $scope);
        foreach ($alerts as $a) {
            abort_unless($scope->canDismiss($request->user(), $a), 403, __('ads.alerts.cannot_dismiss'));
        }
        $now = CarbonImmutable::now();
        foreach ($alerts as $a) {
            if ($a->isLive()) {
                $store->dismiss($a, $request->user(), $data['reason'], $data['note'] ?? null, $now);
            }
        }

        return response()->json(['ok' => true]);
    }

    /** @return Collection<int, AdsAlert> */
    private function load(Request $request, AlertScope $scope): Collection
    {
        $data = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:50'], 'ids.*' => ['integer']]);
        $ids = array_values(array_unique(array_map('intval', $data['ids'])));
        $rows = $scope->visible($request->user())->whereIn('ads_alerts.id', $ids)->get();
        abort_if($rows->count() !== count($ids), 404);

        return $rows;
    }
}
