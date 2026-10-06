<?php

namespace App\Http\Controllers\Web\Ads;

use App\Ads\Access\AdsScope;
use App\Ads\Control\DuplicatePublication;
use App\Ads\Launch\CheckResult;
use App\Ads\Launch\LaunchChecks;
use App\Ads\Launch\LaunchPolicy;
use App\Ads\Launch\LaunchPresenter;
use App\Ads\Launch\LaunchService;
use App\Ads\Launch\SlotService;
use App\Ads\Materials\MaterialService;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ads\LaunchRequest;
use App\Models\AdLaunch;
use App\Models\AdMaterial;
use App\Models\AdMaterialCaption;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Launch drafts (control room S1): content prepares, the buyer of the account reviews. Every rule lives in LaunchService. */
class LaunchController extends Controller
{
    public function index(Request $request, LaunchPresenter $presenter, AdsScope $scope): Response
    {
        $user = $request->user();
        $buyer = $scope->buyerFor($user);
        $boxes = ['mine', 'review', 'live', 'all'];
        $box = in_array($request->query('box'), $boxes, true) ? (string) $request->query('box') : ($user->role === UserRole::MediaBuyer ? 'review' : 'mine');
        $material = is_numeric($request->query('material')) ? (int) $request->query('material') : null;

        $base = fn () => LaunchPolicy::visible(AdLaunch::query(), $user)->when($material !== null, fn ($q) => $q->where('ad_material_id', $material));
        $inBox = function ($q, string $b) use ($user, $buyer) {
            return match ($b) {
                'mine' => $q->where('prepared_by_id', $user->id),
                'review' => $q->whereIn('state', ['buyer_review', 'create_failed', 'creating_paused'])
                    ->when(! $user->hasAdsAuthority() && ! $user->isSupervisorOrAbove(), fn ($w) => $w->where('reviewer_buyer_id', $buyer?->id ?? 0)),
                'live' => $q->whereIn('state', ['launching', 'live', 'stopped']),
                default => $q,
            };
        };

        $page = $inBox($base(), $box)->with(LaunchPresenter::WITH)->orderByDesc('updated_at')->paginate(20)->withQueryString();
        $page->setCollection($page->getCollection()->map(fn (AdLaunch $l) => $presenter->row($l, $user, ['checks' => 'stored', 'publications' => $box === 'live'])));

        $canToggleSlots = $user->hasAdsAuthority() || $buyer !== null;

        return Inertia::render('Ads/Launches', [
            'box' => $box,
            'filters' => [
                'material' => $material, 'launch' => is_string($request->query('launch')) ? $request->query('launch') : null, 'stop' => $request->boolean('stop'),
                // ?box=slots opens the open-slots tab on first load (final review C1); the list underneath stays the default box.
                'tab' => $request->query('box') === 'slots' && $canToggleSlots ? 'slots' : null,
            ],
            'launches' => $page,
            'counts' => ['mine' => $inBox($base(), 'mine')->count(), 'review' => $inBox($base(), 'review')->count(), 'live' => $inBox($base(), 'live')->count()],
            'canReview' => $user->hasAdsAuthority() || $buyer !== null,
            'canToggleSlots' => $canToggleSlots,
            'reasons' => LaunchService::REASONS,
        ]);
    }

    public function options(Request $request, SlotService $slots, MaterialService $materials): JsonResponse
    {
        abort_unless(LaunchPolicy::canPrepare($request->user()), 403);
        $material = AdMaterial::query()->with('files')->findOrFail((int) $request->query('material'));

        return response()->json([
            'slots' => $slots->openSlots(),
            'files' => $material->files->map(fn ($f) => $materials->fileRow($f) + ['width' => $f->width, 'height' => $f->height])->values()->all(),
            'captions' => AdMaterialCaption::query()->where('ad_material_id', $material->id)->orderBy('ad_material_file_id')->orderBy('position')->limit(15)->get()
                ->map(fn (AdMaterialCaption $c) => ['headline' => $c->headline, 'primary_text' => $c->primary_text, 'cta' => $c->cta, 'angle' => $c->angle])->values()->all(),
            'ctas' => PublishController::CTAS,
            'max_captions' => PublishController::MAX_CAPTIONS,
        ]);
    }

    public function show(Request $request, AdLaunch $launch, LaunchPresenter $presenter): JsonResponse
    {
        $this->see($request, $launch);

        return response()->json(['launch' => $presenter->row($launch, $request->user(), ['checks' => 'stored', 'publications' => true, 'history' => true])]);
    }

    public function checks(Request $request, AdLaunch $launch, LaunchChecks $checks): JsonResponse
    {
        $this->see($request, $launch);
        $results = $checks->run($launch, LaunchPresenter::phaseFor($launch), $request->user());

        return response()->json(['checks' => array_map(fn (CheckResult $c) => $c->toArray(), $results), 'checks_hash' => LaunchChecks::hash($launch, $results)]);
    }

    public function store(LaunchRequest $request, AdMaterial $material, LaunchService $launches): JsonResponse
    {
        $launch = $launches->create($request->user(), $material, $request->validated());

        return $this->answer($request, $launch, __('ads.launch.flash.created'), 201);
    }

    public function update(LaunchRequest $request, AdLaunch $launch, LaunchService $launches): JsonResponse
    {
        $this->see($request, $launch);
        $data = $request->validated();
        $launch = $launches->update($request->user(), $launch, array_diff_key($data, ['revision' => true]), (int) $data['revision']);

        return $this->answer($request, $launch, __('ads.launch.flash.saved'));
    }

    public function submit(Request $request, AdLaunch $launch, LaunchService $launches): JsonResponse
    {
        $this->see($request, $launch);
        $launch = $launches->submit($request->user(), $launch, $this->revision($request));

        return $this->answer($request, $launch, __('ads.launch.flash.submitted'));
    }

    public function sendBack(Request $request, AdLaunch $launch, LaunchService $launches): JsonResponse
    {
        $this->see($request, $launch);
        $data = $request->validate(['code' => ['required', 'string', Rule::in(LaunchService::REASONS)], 'text' => ['nullable', 'string', 'max:1000']]);
        $launch = $launches->sendBack($request->user(), $launch, $data['code'], $data['text'] ?? null);

        return $this->answer($request, $launch, __('ads.launch.flash.changes_requested'));
    }

    public function withdraw(Request $request, AdLaunch $launch, LaunchService $launches): JsonResponse
    {
        $this->see($request, $launch);
        $launch = $launches->withdraw($request->user(), $launch);

        return $this->answer($request, $launch, __('ads.launch.flash.withdrawn'));
    }

    public function forward(Request $request, AdLaunch $launch, LaunchService $launches): JsonResponse
    {
        $this->see($request, $launch);
        try {
            $launch = $launches->forward($request->user(), $launch, $this->revision($request));
        } catch (DuplicatePublication $e) {
            return response()->json(['code' => 'duplicate_in_flight', 'message' => $e->getMessage()], 409);
        }

        return $this->answer($request, $launch, __('ads.launch.flash.forwarded'));
    }

    public function retry(Request $request, AdLaunch $launch, LaunchService $launches): JsonResponse
    {
        $this->see($request, $launch);
        $launch = $launches->retry($request->user(), $launch);

        return $this->answer($request, $launch, __('ads.launch.flash.retried'));
    }

    public function stop(Request $request, AdLaunch $launch, LaunchService $launches): JsonResponse
    {
        $this->see($request, $launch);
        $ads = $launches->stop($request->user(), $launch, $this->key($request));
        $launch->refresh();

        return response()->json(['ok' => true, 'message' => __('ads.launch.flash.stopped'), 'ads' => $ads,
            'launch' => app(LaunchPresenter::class)->row($launch, $request->user(), ['checks' => 'stored', 'publications' => true])]);
    }

    public function retire(Request $request, AdLaunch $launch, LaunchService $launches): JsonResponse
    {
        $this->see($request, $launch);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        $launch = $launches->retire($request->user(), $launch, $data['reason'] ?? null, $this->key($request));

        return $this->answer($request, $launch, __('ads.launch.flash.retired'));
    }

    /** The client's Idempotency-Key: a double click repeats nothing (the pipeline replays the same actions). */
    protected function key(Request $request): string
    {
        $key = (string) $request->header('Idempotency-Key', '');
        if (! preg_match('/^[A-Za-z0-9-]{8,64}$/', $key)) {
            throw ValidationException::withMessages(['idempotency_key' => __('ads.publish.idempotency_key_required')]);
        }

        return $key;
    }

    /** Not visible = not found (a launch id is not a secret, but its contents are). */
    protected function see(Request $request, AdLaunch $launch): void
    {
        abort_unless(LaunchPolicy::canSee($request->user(), $launch), 404);
    }

    protected function revision(Request $request): int
    {
        return (int) $request->validate(['revision' => ['required', 'integer', 'min:1']])['revision'];
    }

    protected function answer(Request $request, AdLaunch $launch, string $message, int $status = 200): JsonResponse
    {
        $row = app(LaunchPresenter::class)->row($launch->fresh() ?? $launch, $request->user(), ['checks' => 'stored']);

        return response()->json(['ok' => true, 'message' => $message, 'launch' => $row], $status);
    }
}
