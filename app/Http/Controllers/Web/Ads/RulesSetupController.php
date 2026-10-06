<?php

namespace App\Http\Controllers\Web\Ads;

use App\Ads\Alerts\AlertScope;
use App\Ads\Alerts\BreakEven;
use App\Ads\Alerts\RuleSettings;
use App\Ads\Alerts\Targets;
use App\Ads\Reports\AdsFilter;
use App\Http\Controllers\Controller;
use App\Models\AdAccount;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

/**
 * Ads › الإعداد › القواعد, the S5 half (spec 7.1, 7.4): break-even inputs, targets, low-stock units, spike floor, notifications
 * switch. The page itself is S2's `Ads/SetupRules` (BuyerSetupController::rules), which receives these numbers as its
 * `rules` prop; this controller owns the writes. Reading: ads:manage. Writing: admin or Ads authority.
 */
class RulesSetupController extends Controller
{
    public function __construct(
        private readonly RuleSettings $settings,
        private readonly BreakEven $breakEven,
        private readonly Targets $targets,
        private readonly AlertScope $scope,
    ) {}

    /** @return array<string, mixed> the `rules` prop of Ads/SetupRules */
    public function props(User $user): array
    {
        $today = CarbonImmutable::now(AdsFilter::TIMEZONE)->startOfDay();

        return [
            'global' => $this->settings->globalInputs(),
            'general' => ['low_stock_units' => $this->settings->lowStockUnits(), 'spike_min_amount' => $this->settings->spikeMinAmount()],
            'notify_enabled' => $this->settings->notifyEnabled(),
            'can_edit' => $this->scope->canManage($user),
            'default_floor' => BreakEven::DEFAULT_FLOOR,
            'accounts' => AdAccount::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'currency', 'platform'])
                ->map(fn (AdAccount $a) => [
                    'id' => $a->id, 'name' => (string) $a->name, 'currency' => $a->currency, 'platform' => (string) $a->platform,
                    'inputs' => $this->settings->accountInputs($a->id),
                    'effective' => $this->breakEven->explain($a->id),
                    'targets' => $this->targets->forAccount($a->id, $today),
                ])->values()->all(),
        ];
    }

    public function update(Request $request): RedirectResponse
    {
        abort_unless($this->scope->canManage($request->user()), 403);
        $data = $request->validate([
            'account_id' => ['nullable', 'integer', Rule::exists('ad_accounts', 'id')],
            'margin_pct' => ['nullable', 'numeric', 'gt:0', 'max:100'],
            'shipping_subsidy' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'return_cost' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'target_cpp' => ['nullable', 'numeric', 'gt:0', 'max:1000000'],
            'target_cpo' => ['nullable', 'numeric', 'gt:0', 'max:1000000'],
            'low_stock_units' => ['sometimes', 'integer', 'min:0', 'max:10000'],
            'spike_min_amount' => ['sometimes', 'numeric', 'min:0', 'max:10000000'],
        ]);

        $inputs = Arr::only($data, RuleSettings::FIELDS);
        if ($inputs !== []) {
            $this->settings->saveInputs($request->user(), $data['account_id'] ?? null, $inputs);
        }
        $general = Arr::only($data, ['low_stock_units', 'spike_min_amount']);
        if ($general !== []) {
            $this->settings->saveGeneral($request->user(), $general);
        }

        return back()->with('status', __('ads.flash.saved'));
    }

    public function notify(Request $request): RedirectResponse
    {
        abort_unless($this->scope->canManage($request->user()), 403);
        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        $this->settings->setNotify($request->user(), (bool) $data['enabled']);

        return back()->with('status', __('ads.flash.saved'));
    }
}
