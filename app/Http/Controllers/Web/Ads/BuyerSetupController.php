<?php

namespace App\Http\Controllers\Web\Ads;

use App\Ads\AdsSettings;
use App\Ads\Launch\LaunchSettings;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\BuyerTarget;
use App\Models\MediaBuyer;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Media buyers (who they are, their monthly targets) and the Ads Hub settings. Supervisor and up. */
class BuyerSetupController extends Controller
{
    public function index(AdsSettings $settings): Response
    {
        $buyers = MediaBuyer::with(['user:id,name', 'targets'])->orderBy('name')->get()
            ->map(fn (MediaBuyer $b) => [
                'id' => $b->id,
                'name' => $b->name,
                'color' => $b->color,
                'is_active' => $b->is_active,
                'user' => $b->user ? ['id' => $b->user->id, 'name' => $b->user->name] : null,
                'targets' => $b->targets->sortByDesc('month')->values()->map(fn (BuyerTarget $t) => [
                    'month' => $t->month->format('Y-m'),
                    'budget' => (float) $t->budget,
                    'target_roas' => $t->target_roas === null ? null : (float) $t->target_roas,
                ])->all(),
            ])->all();

        return Inertia::render('Ads/BuyersSetup', [
            'buyers' => $buyers,
            'users' => User::where('role', UserRole::MediaBuyer->value)->orderBy('name')->get(['id', 'name', 'role'])
                ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'role' => $u->role->value])->all(),
            'settings' => [
                'tax_rate' => $settings->taxRate(),
                'tax_rate_percent' => round($settings->taxRate() * 100, 2),
                'winner_thresholds' => $settings->winnerThresholds(),
            ],
            'launchExpiryDays' => app(LaunchSettings::class)->expiryDays(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        MediaBuyer::create($this->buyerData($request, null));

        return back()->with('status', __('ads.flash.saved'));
    }

    public function update(Request $request, MediaBuyer $buyer): RedirectResponse
    {
        $buyer->update($this->buyerData($request, $buyer));

        return back()->with('status', __('ads.flash.saved'));
    }

    /** A buyer with account history is archived instead, so past reports keep their owner. */
    public function destroy(MediaBuyer $buyer): RedirectResponse
    {
        if ($buyer->assignments()->exists()) {
            $buyer->update(['is_active' => false]);

            return back()->with('status', __('ads.flash.buyer_archived'));
        }

        $buyer->delete();

        return back()->with('status', __('ads.flash.deleted'));
    }

    public function targets(Request $request, MediaBuyer $buyer): RedirectResponse
    {
        $data = $request->validate([
            'month' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])(-01)?$/'],
            'budget' => ['required', 'numeric', 'min:0', 'max:999999999999'],
            'target_roas' => ['nullable', 'numeric', 'min:0', 'max:999999'],
        ]);

        $month = CarbonImmutable::createFromFormat('!Y-m', substr($data['month'], 0, 7))->toDateString();
        BuyerTarget::updateOrCreate(
            ['media_buyer_id' => $buyer->id, 'month' => $month],
            ['budget' => $data['budget'], 'target_roas' => $data['target_roas'] ?? null],
        );

        return back()->with('status', __('ads.flash.saved'));
    }

    public function settings(Request $request, AdsSettings $settings): RedirectResponse
    {
        $data = $request->validate([
            'tax_rate_percent' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'winner_thresholds' => ['sometimes', 'array'],
            'winner_thresholds.winner' => ['sometimes', 'numeric', 'gt:0'],
            'winner_thresholds.promising' => ['sometimes', 'numeric', 'gt:0'],
            'winner_thresholds.loser' => ['sometimes', 'numeric', 'gt:0'],
            'winner_thresholds.loser_min_spend' => ['sometimes', 'numeric', 'gt:0'],
            'winner_thresholds.min_spend' => ['sometimes', 'numeric', 'gt:0'],
            'winner_thresholds.min_days' => ['sometimes', 'integer', 'min:1', 'max:30'],
            'launch_expiry_days' => ['sometimes', 'integer', 'min:1', 'max:30'],
        ]);

        if (isset($data['tax_rate_percent'])) {
            $settings->set('tax_rate', round((float) $data['tax_rate_percent'] / 100, 4));
        }
        if (isset($data['winner_thresholds'])) {
            $settings->set('winner_thresholds', array_merge($settings->winnerThresholds(), $data['winner_thresholds']));
        }
        if (isset($data['launch_expiry_days'])) {
            $settings->set(LaunchSettings::EXPIRY_KEY, (int) $data['launch_expiry_days']);
        }

        return back()->with('status', __('ads.flash.saved'));
    }

    /** @return array{name:string,color:?string,user_id:?int,is_active:bool} */
    private function buyerData(Request $request, ?MediaBuyer $buyer): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'color' => ['nullable', 'string', 'max:20'],
            'user_id' => [
                'nullable', 'integer',
                Rule::exists('users', 'id')->where('role', UserRole::MediaBuyer->value),
                Rule::unique('media_buyers', 'user_id')->ignore($buyer?->id),
            ],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        return [
            'name' => $data['name'],
            'color' => $data['color'] ?? null,
            'user_id' => $data['user_id'] ?? null,
            'is_active' => $data['is_active'] ?? true,
        ];
    }
}
