<?php

namespace App\Http\Controllers\Web\Ads;

use App\Ads\Reports\AdsFilter;
use App\Ads\Reports\RunningCreatives;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Old Ads Hub URLs (bookmarks, notifications) land on the control-room page that replaced them, same question asked. */
class LegacyAdsRedirectController extends Controller
{
    private const KEEP = ['from', 'to', 'range', 'platform', 'buyer', 'q', 'ad'];

    public function creatives(Request $r): RedirectResponse
    {
        $q = $this->base($r) + ['view' => 'table', 'status' => self::status($r)];
        if (is_numeric($r->query('account'))) {
            $q['accounts'] = (string) (int) $r->query('account');
        }
        if (in_array($r->query('sort'), RunningCreatives::SORTS, true)) {
            $q['sort'] = '-'.$r->query('sort');
        }
        if (in_array((int) $r->query('per_page'), ExplorerController::PER_PAGE, true)) {
            $q['per_page'] = (int) $r->query('per_page');
        }
        if (is_numeric($r->query('page')) && (int) $r->query('page') > 1) {
            $q['page'] = (int) $r->query('page');
        }

        return $this->to('/ads/explorer', $q);
    }

    public function winners(Request $r): RedirectResponse
    {
        // Every old tier chip keeps its answer; an unknown tier was the page default (top = winner + promising).
        $health = match ($r->query('tier', 'top')) {
            'loser' => 'losing',
            'winner' => 'winning',
            'promising' => 'promising',
            'neutral' => 'neutral',
            'all' => null,
            default => 'top',
        };
        $sort = match ($r->query('sort')) {
            'spend' => '-spend',
            'date' => '-date',
            default => '-roas', // score, roas, revenue (A5)
        };

        return $this->to('/ads/explorer', array_filter($this->base($r) + ['view' => 'cards', 'status' => self::status($r), 'health' => $health, 'sort' => $sort], fn ($v) => $v !== null));
    }

    public function campaigns(Request $r): RedirectResponse
    {
        return $this->to('/ads/explorer', $this->base($r) + ['view' => 'tree', 'status' => 'all', 'sort' => $r->query('sort') === 'roas' ? '-roas' : '-spend']);
    }

    public function actions(Request $r): RedirectResponse
    {
        return $this->to('/ads/decisions', $this->base($r));
    }

    public function buyers(Request $r): RedirectResponse
    {
        return $this->to('/ads/numbers', $this->base($r) + ['section' => 'buyers']);
    }

    /** D11: merged into «الأرقام». Its platform filter was an inbox channel, not an ad platform: dropped. */
    public function reportsAds(Request $r): RedirectResponse
    {
        $q = array_filter(['from' => $r->query('from'), 'to' => $r->query('to')], fn ($v) => is_string($v) && $v !== '') + ['section' => 'chat'];

        return redirect('/ads/numbers?'.http_build_query($q), 301);
    }

    /** @return array<string, string> */
    private function base(Request $r): array
    {
        $q = [];
        foreach (self::KEEP as $k) {
            $v = $r->query($k);
            if (is_string($v) && $v !== '') {
                $q[$k] = $v;
            }
        }
        $accounts = AdsFilter::accountIdsFrom($r->query('accounts'));
        if ($accounts !== []) {
            $q['accounts'] = implode(',', $accounts);
        }

        return $q;
    }

    private static function status(Request $r): string
    {
        return match ($r->query('status')) {
            'active' => 'running',
            'inactive' => 'paused',
            default => 'all', // the old pages listed every status by default
        };
    }

    private function to(string $path, array $q): RedirectResponse
    {
        return redirect($path.($q === [] ? '' : '?'.http_build_query($q)));
    }
}
