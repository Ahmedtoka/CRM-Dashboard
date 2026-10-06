<?php

namespace App\Http\Controllers\Web\Ads;

use App\Ads\Launch\LaunchPolicy;
use App\Ads\Launch\LaunchService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ads\LaunchRequest;
use App\Models\AdLaunch;
use App\Models\AdMaterial;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Launch drafts (control room S1): content prepares, the buyer of the account reviews. Every rule lives in LaunchService. */
class LaunchController extends Controller
{
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
        return response()->json(['ok' => true, 'message' => $message, 'launch' => ['id' => $launch->public_id, 'state' => $launch->state->value, 'revision' => $launch->revision]], $status);
    }
}
